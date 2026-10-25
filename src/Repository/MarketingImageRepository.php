<?php

namespace App\Repository;

use App\Entity\MarketingImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketingImage>
 */
class MarketingImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketingImage::class);
    }

    /** Visuel désigné par son nom public <sha256>.webp, ou null. */
    public function findByFileName(string $fileName): ?MarketingImage
    {
        return preg_match('/^([a-f0-9]{64})\.webp$/', $fileName, $match) ? $this->findOneBy(['sha256' => $match[1]]) : null;
    }

    /** Visuel désigné par son URL relative /api/marketing/image/<sha256>.webp, ou null. */
    public function findByPath(mixed $path): ?MarketingImage
    {
        return is_string($path) && preg_match(MarketingImage::PATH_PATTERN, $path, $match) ? $this->findOneBy(['sha256' => $match[1]]) : null;
    }

    /**
     * Empreintes et dates de tous les visuels, sans charger leur contenu.
     *
     * @return list<array{id: int, sha256: string, createdAt: \DateTimeImmutable}>
     */
    public function findSummaries(): array
    {
        return $this->createQueryBuilder('i')->select('i.id', 'i.sha256', 'i.createdAt')->getQuery()->getArrayResult();
    }

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->getEntityManager()->createQuery('DELETE FROM App\Entity\MarketingImage i WHERE i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->execute();
    }
}
