<?php

declare(strict_types=1);

namespace App\EmailCampaign;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Expéditeur des campagnes et contrôle de sa configuration, AVANT tout envoi (test compris).
 *
 * Les campagnes partent par un transport SMTP dédié (CAMPAIGN_MAILER_DSN, transport « campaigns »
 * de config/packages/mailer.yaml), authentifié avec la boîte de l'expéditeur. Les e-mails de
 * service (activation, mot de passe, alertes) restent sur MAILER_DSN (service@medclick.be).
 *
 * Refus si : transport absent ou nul, compte SMTP différent de l'expéditeur (le serveur pourrait
 * refuser le message ou il serait signé pour une autre boîte), expéditeur hors du domaine
 * medclick.be (jamais une adresse personnelle).
 */
final class CampaignSender
{
    public const ALLOWED_DOMAIN = 'medclick.be';
    public const NAME = 'MedClick';
    /** Transport Symfony Mailer sélectionné par l'en-tête X-Transport. */
    public const TRANSPORT = 'campaigns';

    public function __construct(
        #[Autowire('%env(CAMPAIGN_SENDER)%')] private readonly string $address,
        #[Autowire('%env(CAMPAIGN_MAILER_DSN)%')] private readonly string $dsn,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    public function address(): string
    {
        return mb_strtolower(trim($this->address));
    }

    /**
     * @return array{ready: bool, sender: string, reason: ?string}
     */
    public function status(): array
    {
        return ['ready' => $this->problem() === null, 'sender' => $this->address(), 'reason' => $this->problem()];
    }

    public function problem(): ?string
    {
        $sender = $this->address();
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || !str_ends_with($sender, '@' . self::ALLOWED_DOMAIN)) {
            return sprintf("L'expéditeur des campagnes (CAMPAIGN_SENDER) doit être une adresse @%s.", self::ALLOWED_DOMAIN);
        }

        // Tests : transport nul, les messages sont interceptés.
        if ($this->environment === 'test') {
            return null;
        }

        $dsn = trim($this->dsn);
        if ($dsn === '' || str_starts_with($dsn, 'null://')) {
            return "Transport des campagnes non configuré (CAMPAIGN_MAILER_DSN, boîte $sender) : aucun envoi possible.";
        }

        $user = parse_url($dsn, PHP_URL_USER);
        if (!is_string($user) || mb_strtolower(urldecode($user)) !== $sender) {
            return "Le compte SMTP des campagnes (CAMPAIGN_MAILER_DSN) n'est pas celui de l'expéditeur $sender : envoi refusé.";
        }

        return null;
    }
}
