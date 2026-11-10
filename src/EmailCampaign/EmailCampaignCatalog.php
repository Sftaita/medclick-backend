<?php

declare(strict_types=1);

namespace App\EmailCampaign;

/**
 * Campagnes e-mail disponibles (docs/CAMPAGNES-EMAIL.md). Ajouter une campagne = une entrée ici et
 * ses deux gabarits dans templates/email/campagnes/.
 */
final class EmailCampaignCatalog
{
    /** @var array<string, EmailCampaignDefinition> */
    private array $campaigns;

    public function __construct()
    {
        $this->campaigns = [];
        foreach ([
            new EmailCampaignDefinition(
                slug: 'recherche-inami',
                name: 'Recherche INAMI',
                subject: 'Vos codes INAMI en quelques secondes !',
                title: 'Nouveau sur MedClick : vos codes INAMI en quelques secondes !',
                template: 'email/campagnes/recherche-inami',
                visual: 'data/email-campaigns/recherche-inami.jpg',
                visualAlt: 'Nouveau sur MedClick : trouvez vos codes INAMI gratuitement. Recherche par spécialité, intervention ou numéro de code, sans compte, sur ordinateur et sur smartphone.',
                visualWidth: 1200,
                visualHeight: 900,
                destinationUrl: 'https://www.medclick.be/codes-inami',
            ),
        ] as $campaign) {
            $this->campaigns[$campaign->slug] = $campaign;
        }
    }

    public function get(string $slug): ?EmailCampaignDefinition
    {
        return $this->campaigns[$slug] ?? null;
    }

    /**
     * @return list<EmailCampaignDefinition>
     */
    public function all(): array
    {
        return array_values($this->campaigns);
    }
}
