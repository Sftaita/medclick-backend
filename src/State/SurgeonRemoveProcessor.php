<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Surgeons;
use App\Repository\SurgeriesRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Suppression d'un chirurgien : supprime d'abord les interventions de l'année où il était
 * première ou deuxième main (positions 2 et 3).
 *
 * @implements ProcessorInterface<Surgeons, null>
 */
final class SurgeonRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private SurgeriesRepository $surgeriesRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Surgeons) {
            $this->surgeriesRepository->deleteSurgeryBySurgeon($data, $data->getYear());
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
