<?php

namespace App\Service;

use App\Entity\Marketing;
use App\Entity\MarketingImage;
use App\Repository\MarketingImageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Visuels importés des campagnes : enregistrement (une fois par contenu) et suppression des
 * visuels qu'aucune campagne n'utilise plus.
 *
 * - Visuel remplacé ou campagne supprimée : l'ancien visuel est supprimé aussitôt s'il n'est
 *   utilisé par aucune autre campagne.
 * - Import abandonné (formulaire annulé, onglet fermé) : le front demande la suppression ; à
 *   défaut, tout visuel inutilisé depuis plus de 24 h est supprimé au prochain import ou
 *   enregistrement (délai laissé à un formulaire encore ouvert).
 */
class MarketingImageStore
{
    public const ORPHAN_GRACE = 'PT24H';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketingImageRepository $images,
        private readonly MarketingImageProcessor $processor,
    ) {
    }

    /** @throws \InvalidArgumentException message lisible */
    public function store(string $upload): MarketingImage
    {
        $result = $this->processor->process($upload);
        $image = $this->images->findOneBy(['sha256' => hash('sha256', $result['data'])]);
        if ($image === null) {
            $image = new MarketingImage($result['data'], $result['width'], $result['height'], strlen($upload));
            $this->em->persist($image);
            $this->em->flush();
        }
        $this->purgeOrphans();

        return $image;
    }

    /** Visuels internes (URL /api/marketing/image/…) d'une campagne. @return list<string> */
    public function pathsOf(Marketing $marketing): array
    {
        return array_values(array_filter(self::formatValues($marketing), static fn ($value) => is_string($value) && preg_match(MarketingImage::PATH_PATTERN, $value)));
    }

    /** Empreintes SHA-256 utilisées par au moins une campagne. @return array<string, true> */
    public function usedHashes(): array
    {
        $used = [];
        foreach ($this->em->getRepository(Marketing::class)->findAll() as $marketing) {
            foreach ($this->pathsOf($marketing) as $path) {
                preg_match(MarketingImage::PATH_PATTERN, $path, $match);
                $used[$match[1]] = true;
            }
        }

        return $used;
    }

    public function isUsed(MarketingImage $image): bool
    {
        return isset($this->usedHashes()[$image->getSha256()]);
    }

    /**
     * Supprime les visuels donnés (URL) s'ils ne sont plus utilisés, puis les visuels inutilisés
     * depuis plus de 24 h. À appeler après l'enregistrement (flush) de la campagne.
     *
     * @param list<string> $paths
     */
    public function release(array $paths): int
    {
        $hashes = [];
        foreach ($paths as $path) {
            if (preg_match(MarketingImage::PATH_PATTERN, $path, $match)) {
                $hashes[$match[1]] = true;
            }
        }

        return $this->purge($hashes);
    }

    public function purgeOrphans(): int
    {
        return $this->purge([]);
    }

    /** @param array<string, true> $immediately empreintes à supprimer sans attendre le délai */
    private function purge(array $immediately): int
    {
        $used = $this->usedHashes();
        $limit = (new \DateTimeImmutable())->sub(new \DateInterval(self::ORPHAN_GRACE));
        $ids = [];
        foreach ($this->images->findSummaries() as $summary) {
            if (isset($used[$summary['sha256']])) {
                continue;
            }
            if (isset($immediately[$summary['sha256']]) || $summary['createdAt'] < $limit) {
                $ids[] = $summary['id'];
            }
        }

        return $this->images->deleteByIds($ids);
    }

    /** @return array<string, ?string> */
    public static function formatValues(Marketing $marketing): array
    {
        return [
            'smartphone_format' => $marketing->getSmartphoneFormat(),
            'tablet_portrait_format' => $marketing->getTabletPortraitFormat(),
            'tablet_landscape_format' => $marketing->getTabletLandscapeFormat(),
            'screen_14_inch_format' => $marketing->getScreen14InchFormat(),
            'large_screen_format' => $marketing->getLargeScreenFormat(),
        ];
    }
}
