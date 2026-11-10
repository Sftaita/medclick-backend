<?php

declare(strict_types=1);

namespace App\EmailCampaign;

/**
 * Définition d'une campagne e-mail. Le contenu est versionné dans le code (gabarits Twig) : il est
 * relu et validé avant publication, et l'aperçu, l'e-mail test et la campagne utilisent exactement
 * les mêmes gabarits.
 */
final readonly class EmailCampaignDefinition
{
    /**
     * @param string $template      gabarit Twig sans extension (.html.twig et .txt.twig)
     * @param string $visual        image intégrée à l'e-mail, relative à la racine du projet
     * @param string $visualAlt     texte alternatif de l'image
     * @param string $destinationUrl lien du bouton principal
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $subject,
        public string $title,
        public string $template,
        public string $visual,
        public string $visualAlt,
        public int $visualWidth,
        public int $visualHeight,
        public string $destinationUrl,
    ) {
    }
}
