<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailCampaignSend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailCampaignSend>
 */
class EmailCampaignSendRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailCampaignSend::class);
    }

    /**
     * Compteurs par type (test, campaign) et statut.
     *
     * @return array<string, array<string, int>>
     */
    public function countsFor(string $campaign): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.kind AS kind, s.status AS status, COUNT(s.id) AS n')
            ->where('s.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->groupBy('s.kind, s.status')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ([EmailCampaignSend::KIND_TEST, EmailCampaignSend::KIND_CAMPAIGN] as $kind) {
            $counts[$kind] = [EmailCampaignSend::STATUS_PENDING => 0, EmailCampaignSend::STATUS_SENT => 0, EmailCampaignSend::STATUS_FAILED => 0];
        }
        foreach ($rows as $row) {
            $counts[$row['kind']][$row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Derniers envois, du plus récent au plus ancien.
     *
     * @return list<EmailCampaignSend>
     */
    public function latest(string $campaign, ?string $kind, int $limit): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('s.id', \SortDirection::Descending)
            ->setMaxResults($limit);
        if ($kind !== null) {
            $qb->andWhere('s.kind = :kind')->setParameter('kind', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Adresses (minuscules) ayant déjà une ligne de campagne, quel qu'en soit le statut.
     *
     * @return array<string, true>
     */
    public function campaignAddresses(string $campaign): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.email')
            ->where('s.campaign = :campaign AND s.kind = :kind')
            ->setParameter('campaign', $campaign)
            ->setParameter('kind', EmailCampaignSend::KIND_CAMPAIGN)
            ->getQuery()->getSingleColumnResult();

        return array_fill_keys(array_map('mb_strtolower', $rows), true);
    }
}
