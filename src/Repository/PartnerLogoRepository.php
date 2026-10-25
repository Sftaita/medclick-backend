<?php

namespace App\Repository;

use App\Entity\PartnerLogo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerLogo>
 */
class PartnerLogoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerLogo::class);
    }

    /** Logo désigné par son nom public <sha256>.<ext>, ou null. */
    public function findByFileName(string $fileName): ?PartnerLogo
    {
        if (!preg_match('/^([a-f0-9]{64})\.(png|svg)$/', $fileName, $match)) {
            return null;
        }
        $logo = $this->findOneBy(['sha256' => $match[1]]);

        return $logo !== null && $logo->getFileName() === $fileName ? $logo : null;
    }
}
