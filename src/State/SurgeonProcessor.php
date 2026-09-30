<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Surgeons;
use App\Repository\SurgeonsRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Création / modification d'un chirurgien : s'il est désigné maître de stage, les autres
 * chirurgiens de la même année perdent ce statut (un seul maître de stage par année).
 *
 * @implements ProcessorInterface<Surgeons, Surgeons>
 */
final class SurgeonProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private SurgeonsRepository $surgeonsRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Surgeons && $data->getBoss() == true) {
            foreach ($this->surgeonsRepository->getBoss($data->getYear()) as $other) {
                if ($other !== $data) {
                    $other->setBoss(false);
                }
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
