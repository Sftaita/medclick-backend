<?php

namespace App\Doctrine;

use App\Entity\User;
use App\Entity\Years;
use App\Entity\Surgeries;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Security;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use App\Entity\Consultations;
use App\Entity\Favorites;
use App\Entity\Formations;
use App\Entity\Gardes;
use App\Entity\Statistics;
use App\Entity\Surgeons;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{

    private $security;
    private $auth;


    public function __construct(Security $security, AuthorizationCheckerInterface $checker)
    {
        $this->security = $security;
        $this->auth = $checker;
    }

    private function AddWhere(QueryBuilder $queryBuilder, string $resourceClass)
    {
        // 1. Obtenir l'utilisateur connecté
        $user = $this->security->getUser();

        //2. Si on demande des interventions, année ou liste de chirurgien, tenir compte de l'utilisateur connecté
        if (($resourceClass === Years::class || $resourceClass === Surgeries::class || $resourceClass === Surgeons::class || $resourceClass === Consultations::class || $resourceClass === Favorites::class || $resourceClass === Formations::class || $resourceClass === Gardes::class || $resourceClass === User::class || $resourceClass === Statistics::class) && $user instanceof User) {
            $rootAlias = $queryBuilder->getRootAliases()[0];

            if ($resourceClass === Years::class) {
                $queryBuilder->andWhere("$rootAlias.user = :user");
            } elseif ($resourceClass === User::class) {
                $queryBuilder->andWhere("$rootAlias = :user");
                $queryBuilder->setParameter("user", $user);
            } elseif ($resourceClass === Surgeries::class) {
                $queryBuilder->join("$rootAlias.year", "c")
                    ->andWhere("c.user = :user");
            } elseif ($resourceClass === Surgeons::class) {
                $queryBuilder->join("$rootAlias.year", "s")
                    ->andWhere("s.user = :user");
            } elseif ($resourceClass === Consultations::class) {
                $queryBuilder->join("$rootAlias.year", "s")
                    ->andWhere("s.user = :user");
            } elseif ($resourceClass === Favorites::class) {
                $queryBuilder->andWhere("$rootAlias.user = :user");
            } elseif ($resourceClass === Formations::class) {
                $queryBuilder->join("$rootAlias.year", "s")
                    ->andWhere("s.user = :user");
            } elseif ($resourceClass === Gardes::class) {
                $queryBuilder->join("$rootAlias.year", "s")
                    ->andWhere("s.user = :user");
            } elseif ($resourceClass === Statistics::class) {
                $queryBuilder->andWhere("$rootAlias.user = :user");
            }
            $queryBuilder->setParameter("user", $user);
        }

        // Seul l'admin a accès à  la liste de tous les utilisateurs :
        /*
        if (($resourceClass === User::class) && $user instanceof User && !$this->auth->isGranted('ROLE_ADMIN')) {
            $rootAlias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->andWhere("$rootAlias = :user");
            $queryBuilder->setParameter("user", $user);
        }
        */
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?string $operationName = null)
    {
        $this->AddWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?string $operationName = null, array $context = [])
    {
        $this->AddWhere($queryBuilder, $resourceClass);
    }
}
