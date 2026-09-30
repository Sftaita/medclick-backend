<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Formations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Création / modification d'une formation : le lieu "local" est remplacé par l'hôpital
 * de l'année de formation.
 *
 * @implements ProcessorInterface<Formations, Formations>
 */
final class FormationProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Formations && $data->getLocation() === 'local' && $data->getYear()) {
            $data->setLocation($data->getYear()->getHospital());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
