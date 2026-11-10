<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\EmailCampaign\CampaignSender;
use App\EmailCampaign\EmailCampaignAudience;
use App\EmailCampaign\EmailCampaignCatalog;
use App\EmailCampaign\EmailCampaignDefinition;
use App\EmailCampaign\EmailCampaignException;
use App\EmailCampaign\EmailCampaignMailer;
use App\EmailCampaign\EmailCampaignRenderer;
use App\Entity\EmailCampaignSend;
use App\Entity\EmailUnsubscribe;
use App\Entity\User;
use App\Repository\EmailCampaignSendRepository;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Campagnes e-mail (ROLE_ADMIN, access_control ^/api/admin) : consultation, aperçu, e-mail test,
 * statut des envois, sélection des destinataires (compteurs seulement) et désinscriptions.
 *
 * Aucun envoi collectif ici : il passe uniquement par la commande app:email-campaign:send,
 * verrouillée (docs/CAMPAGNES-EMAIL.md).
 */
#[Route('/api/admin')]
final class EmailCampaignAdminController extends AbstractController
{
    public function __construct(
        private readonly EmailCampaignCatalog $catalog,
        private readonly CampaignSender $sender,
        private readonly EmailCampaignSendRepository $sends,
        private readonly EmailUnsubscribeRepository $unsubscribes,
    ) {
    }

    #[Route('/email-campaigns', name: 'admin_email_campaigns', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'sender' => $this->sender->status(),
            'campaigns' => array_map(fn (EmailCampaignDefinition $campaign) => $this->summary($campaign), $this->catalog->all()),
            'unsubscribes' => $this->unsubscribes->count([]),
        ]);
    }

    #[Route('/email-campaigns/{slug}', name: 'admin_email_campaign', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug, #[Autowire('%env(bool:EMAIL_CAMPAIGN_BULK_ENABLED)%')] bool $bulkEnabled): JsonResponse
    {
        $campaign = $this->campaign($slug);

        return new JsonResponse($this->summary($campaign) + [
            'sender' => $this->sender->status(),
            'bulk_enabled' => $bulkEnabled,
            'test_recipients' => $this->testRecipients(),
            'latest_tests' => array_map($this->sendRow(...), $this->sends->latest($slug, EmailCampaignSend::KIND_TEST, 20)),
            'latest_campaign' => array_map($this->sendRow(...), $this->sends->latest($slug, EmailCampaignSend::KIND_CAMPAIGN, 50)),
        ]);
    }

    /** HTML de l'e-mail tel qu'il sera envoyé (image intégrée en data:, lien de désinscription neutralisé). */
    #[Route('/email-campaigns/{slug}/preview', name: 'admin_email_campaign_preview', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function preview(string $slug, Request $request, EmailCampaignRenderer $renderer): Response
    {
        $campaign = $this->campaign($slug);
        if ($request->query->get('format') === 'text') {
            return new Response($renderer->text($campaign, $renderer->unsubscribeUrl('lien-personnel-du-destinataire')), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response($renderer->html($campaign, '#lien-de-desinscription-personnel', true), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Affiché dans un iframe « srcdoc » par l'administration ; jamais de script.
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
        ]);
    }

    #[Route('/email-campaigns/{slug}/audience', name: 'admin_email_campaign_audience', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function audience(string $slug, EmailCampaignAudience $audience): JsonResponse
    {
        return new JsonResponse($audience->summary($this->campaign($slug)));
    }

    /**
     * E-mail test, à UNE adresse : celle de l'administrateur connecté ou une adresse de
     * EMAIL_CAMPAIGN_TEST_RECIPIENTS. Même rendu que la campagne.
     */
    #[Route('/email-campaigns/{slug}/test', name: 'admin_email_campaign_test', methods: ['POST'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function test(
        string $slug,
        Request $request,
        EmailCampaignMailer $mailer,
        #[Target('email_campaign_test.limiter')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $campaign = $this->campaign($slug);
        $data = json_decode($request->getContent(), true);
        $recipient = is_array($data) && is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : '';

        if (!in_array($recipient, $this->testRecipients(), true)) {
            return new JsonResponse(['message' => "L'e-mail test ne peut être envoyé qu'à votre adresse d'administrateur ou à une adresse de test autorisée."], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        if (!$limiter->create('admin-' . $admin->getId())->consume()->isAccepted()) {
            return new JsonResponse(['message' => "Trop d'e-mails test. Réessayez plus tard."], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $send = $mailer->sendTest($campaign, $recipient, $admin->getId());
        } catch (EmailCampaignException $refused) {
            return new JsonResponse(['message' => $refused->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->sendRow($send), $send->getStatus() === EmailCampaignSend::STATUS_SENT ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }

    #[Route('/email-unsubscribes', name: 'admin_email_unsubscribes', methods: ['GET'])]
    public function unsubscribes(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $result = $this->unsubscribes->search($request->query->getString('q') ?: null, 50, ($page - 1) * 50);

        return new JsonResponse([
            'total' => $result['total'],
            'page' => $page,
            'items' => array_map(fn (EmailUnsubscribe $u) => [
                'id' => $u->getId(),
                'email' => $u->getEmail(),
                'source' => $u->getSource(),
                'campaign' => $u->getCampaign(),
                'created_at' => $u->getCreatedAt()->format(\DATE_ATOM),
            ], $result['items']),
        ]);
    }

    /** Désinscription demandée par un autre canal (contact@medclick.be…). */
    #[Route('/email-unsubscribes', name: 'admin_email_unsubscribe_add', methods: ['POST'])]
    public function addUnsubscribe(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = is_array($data) && is_string($data['email'] ?? null) ? EmailUnsubscribe::normalize($data['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['message' => 'Adresse e-mail invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if ($this->unsubscribes->isUnsubscribed($email)) {
            return new JsonResponse(['message' => 'Cette adresse est déjà désinscrite.'], Response::HTTP_OK);
        }

        $em->persist(new EmailUnsubscribe($email, EmailUnsubscribe::SOURCE_ADMIN));
        $em->flush();

        return new JsonResponse(['message' => 'Adresse désinscrite.'], Response::HTTP_CREATED);
    }

    /** Réinscription : uniquement à la demande expresse de la personne concernée. */
    #[Route('/email-unsubscribes/{id}', name: 'admin_email_unsubscribe_remove', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function removeUnsubscribe(int $id, EntityManagerInterface $em): JsonResponse
    {
        $unsubscribe = $this->unsubscribes->find($id);
        if ($unsubscribe === null) {
            return new JsonResponse(['message' => 'Désinscription introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $em->remove($unsubscribe);
        $em->flush();

        return new JsonResponse(['message' => 'Adresse réinscrite.']);
    }

    private function campaign(string $slug): EmailCampaignDefinition
    {
        return $this->catalog->get($slug) ?? throw $this->createNotFoundException('Campagne inconnue.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(EmailCampaignDefinition $campaign): array
    {
        return [
            'slug' => $campaign->slug,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'title' => $campaign->title,
            'destination_url' => $campaign->destinationUrl,
            'visual_alt' => $campaign->visualAlt,
            'counts' => $this->sends->countsFor($campaign->slug),
        ];
    }

    /**
     * @return list<string>
     */
    private function testRecipients(): array
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $allowed = array_filter(array_map(fn (string $e) => mb_strtolower(trim($e)), explode(',', (string) $this->getParameter('app.email_campaign_test_recipients'))));

        return array_values(array_unique([mb_strtolower((string) $admin->getEmail()), ...$allowed]));
    }

    /**
     * @return array<string, mixed>
     */
    private function sendRow(EmailCampaignSend $send): array
    {
        return [
            'id' => $send->getId(),
            'kind' => $send->getKind(),
            // Adresse masquée pour la campagne ; complète pour un test (adresse de l'administration).
            'email' => $send->getKind() === EmailCampaignSend::KIND_TEST ? $send->getEmail() : self::mask($send->getEmail()),
            'status' => $send->getStatus(),
            'error' => $send->getError(),
            'created_at' => $send->getCreatedAt()->format(\DATE_ATOM),
            'sent_at' => $send->getSentAt()?->format(\DATE_ATOM),
        ];
    }

    private static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1) . str_repeat('•', 3) . '@' . $domain;
    }
}
