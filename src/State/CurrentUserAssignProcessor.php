<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Favorites;
use App\Entity\Years;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Création d'une année de formation ou d'un favori : le propriétaire est toujours
 * l'utilisateur connecté, quelle que soit la valeur envoyée.
 *
 * @implements ProcessorInterface<Years|Favorites, Years|Favorites>
 */
final class CurrentUserAssignProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Years || $data instanceof Favorites) {
            $data->setUser($this->security->getUser());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
