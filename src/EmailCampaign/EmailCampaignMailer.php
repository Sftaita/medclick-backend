<?php

declare(strict_types=1);

namespace App\EmailCampaign;

use App\Entity\EmailCampaignSend;
use App\Repository\EmailCampaignSendRepository;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Envoi d'une campagne à UNE adresse (Symfony Mailer, transport « campaigns »). Un message par
 * destinataire : aucune autre adresse n'apparaît dans l'e-mail (ni copie, ni liste visible).
 *
 * Chaque envoi est journalisé (email_campaign_send) AVANT l'envoi :
 * - campagne : la clé unique (campagne + adresse) empêche tout second envoi à la même adresse ;
 * - test : répétable, réservé à l'administration et à la commande.
 */
final class EmailCampaignMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EmailCampaignRenderer $renderer,
        private readonly CampaignSender $sender,
        private readonly EmailUnsubscribeRepository $unsubscribes,
        private readonly EmailCampaignSendRepository $sends,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * E-mail test : même rendu que la campagne (même gabarit, même expéditeur, même pied de page,
     * lien de désinscription fonctionnel pour cette adresse).
     *
     * @throws EmailCampaignException expéditeur non configuré, adresse invalide ou désinscrite
     */
    public function sendTest(EmailCampaignDefinition $campaign, string $recipient, ?int $requestedBy): EmailCampaignSend
    {
        $recipient = $this->checkRecipient($recipient);
        $send = (new EmailCampaignSend($campaign->slug, EmailCampaignSend::KIND_TEST, $recipient, self::newToken()))
            ->setRequestedBy($requestedBy);
        $this->em->persist($send);
        $this->em->flush();

        return $this->deliver($campaign, $send);
    }

    /**
     * Envoi de campagne à une adresse : null si elle a déjà une ligne pour cette campagne
     * (envoyée, en échec ou interrompue) — jamais deux envois.
     *
     * @throws EmailCampaignException expéditeur non configuré, adresse invalide ou désinscrite
     */
    public function sendCampaign(EmailCampaignDefinition $campaign, string $recipient): ?EmailCampaignSend
    {
        $recipient = $this->checkRecipient($recipient);
        $existing = $this->sends->findOneBy(['dedupKey' => EmailCampaignSend::dedupKeyFor($campaign->slug, $recipient)]);
        if ($existing !== null) {
            return null;
        }

        $send = new EmailCampaignSend($campaign->slug, EmailCampaignSend::KIND_CAMPAIGN, $recipient, self::newToken());
        try {
            $this->em->persist($send);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Réservée entre-temps par une autre exécution.
            $this->registry->resetManager();

            return null;
        }

        return $this->deliver($campaign, $send);
    }

    /** Nouvelle tentative d'un envoi de campagne en échec (jamais d'un envoi interrompu). */
    public function retry(EmailCampaignDefinition $campaign, EmailCampaignSend $send): EmailCampaignSend
    {
        if ($send->getStatus() !== EmailCampaignSend::STATUS_FAILED) {
            throw new EmailCampaignException('Seul un envoi en échec peut être relancé.');
        }
        $this->checkRecipient($send->getEmail());
        $send->retry();
        $this->em->flush();

        return $this->deliver($campaign, $send);
    }

    public function build(EmailCampaignDefinition $campaign, EmailCampaignSend $send): Email
    {
        $unsubscribeUrl = $this->renderer->unsubscribeUrl($send->getUnsubscribeToken());
        $from = new Address($this->sender->address(), CampaignSender::NAME);

        $email = (new Email())
            ->from($from)
            ->replyTo($from)
            ->to($send->getEmail())
            ->subject($campaign->subject)
            ->text($this->renderer->text($campaign, $unsubscribeUrl))
            ->html($this->renderer->html($campaign, $unsubscribeUrl))
            ->addPart((new DataPart(new File($this->renderer->visualPath($campaign)), EmailCampaignRenderer::inlineName($campaign), 'image/jpeg'))->asInline());

        $headers = $email->getHeaders();
        // Désinscription proposée par la messagerie (Gmail, Outlook, Apple Mail), en un clic (RFC 8058).
        $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>, <mailto:%s?subject=D%%C3%%A9sinscription>', $unsubscribeUrl, $this->sender->address()));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('X-Transport', CampaignSender::TRANSPORT);

        return $email;
    }

    private function deliver(EmailCampaignDefinition $campaign, EmailCampaignSend $send): EmailCampaignSend
    {
        try {
            $this->mailer->send($this->build($campaign, $send));
            $send->markSent();
        } catch (\Throwable $failure) {
            // Message du serveur SMTP (code et raison), sans identifiant de connexion.
            $send->markFailed($failure::class . ' : ' . preg_replace('#[a-z0-9+.-]+://\S+#i', '[dsn]', $failure->getMessage()));
            $this->logger->warning('[campagne e-mail] Envoi en échec', ['campaign' => $campaign->slug, 'send' => $send->getId(), 'failure' => $failure::class]);
        }
        $this->em->flush();

        return $send;
    }

    private function checkRecipient(string $recipient): string
    {
        $problem = $this->sender->problem();
        if ($problem !== null) {
            throw new EmailCampaignException($problem);
        }
        $recipient = mb_strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new EmailCampaignException('Adresse e-mail invalide.');
        }
        if ($this->unsubscribes->isUnsubscribed($recipient)) {
            throw new EmailCampaignException('Cette adresse est désinscrite des communications e-mail.');
        }

        return $recipient;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
