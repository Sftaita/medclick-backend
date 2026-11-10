<?php

declare(strict_types=1);

namespace App\EmailCampaign;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Rendu d'une campagne. Un seul gabarit pour l'e-mail et l'aperçu ; seules diffèrent la source de
 * l'image (pièce intégrée « cid: » dans l'e-mail, data: dans l'aperçu de l'administration) et le
 * lien de désinscription (propre à chaque envoi).
 */
final class EmailCampaignRenderer
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%app.public_api_url%')] private readonly string $publicApiUrl,
    ) {
    }

    /** Nom de la pièce intégrée, référencée par « cid:… » dans le HTML. */
    public static function inlineName(EmailCampaignDefinition $campaign): string
    {
        return basename($campaign->visual);
    }

    public function visualPath(EmailCampaignDefinition $campaign): string
    {
        return $this->projectDir . '/' . $campaign->visual;
    }

    public function unsubscribeUrl(string $token): string
    {
        return rtrim($this->publicApiUrl, '/') . '/email/desinscription/' . $token;
    }

    public function html(EmailCampaignDefinition $campaign, string $unsubscribeUrl, bool $preview = false): string
    {
        $visual = $preview
            ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($this->visualPath($campaign)))
            : 'cid:' . self::inlineName($campaign);

        return $this->twig->render($campaign->template . '.html.twig', [
            'campaign' => $campaign,
            'visual_src' => $visual,
            'unsubscribe_url' => $unsubscribeUrl,
        ]);
    }

    public function text(EmailCampaignDefinition $campaign, string $unsubscribeUrl): string
    {
        return $this->twig->render($campaign->template . '.txt.twig', [
            'campaign' => $campaign,
            'unsubscribe_url' => $unsubscribeUrl,
        ]);
    }
}
