<?php

declare(strict_types=1);

namespace App\EmailCampaign;

/**
 * Envoi refusé avant tout contact avec le serveur SMTP (message affichable à l'administrateur).
 */
final class EmailCampaignException extends \RuntimeException
{
}
