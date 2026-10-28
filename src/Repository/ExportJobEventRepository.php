<?php

namespace App\Repository;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExportJobEvent>
 */
class ExportJobEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExportJobEvent::class);
    }

    /**
     * @return list<ExportJobEvent>
     */
    public function findForJob(ExportJob $job): array
    {
        return $this->findBy(['job' => $job], ['id' => 'ASC']);
    }
}
