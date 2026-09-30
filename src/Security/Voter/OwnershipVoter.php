<?php

namespace App\Security\Voter;

use App\Entity\Consultations;
use App\Entity\Favorites;
use App\Entity\Formations;
use App\Entity\Gardes;
use App\Entity\Surgeons;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Vérifie qu'une ressource appartient à l'utilisateur connecté.
 *
 * Utilisation :
 *  - API Platform : "security_post_denormalize"="is_granted('OWNER', object)"
 *  - Contrôleur   : $this->isGranted('OWNER', $year)
 *
 * Les éléments d'une année (interventions, chirurgiens, consultations, gardes, formations)
 * appartiennent au propriétaire de leur année. Sans propriétaire identifiable, l'accès est refusé.
 */
class OwnershipVoter extends Voter
{
    public const OWNER = 'OWNER';

    protected function supports($attribute, $subject)
    {
        return $attribute === self::OWNER && (
            $subject instanceof User
            || $subject instanceof Years
            || $subject instanceof Favorites
            || $subject instanceof Surgeries
            || $subject instanceof Surgeons
            || $subject instanceof Consultations
            || $subject instanceof Gardes
            || $subject instanceof Formations
        );
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token)
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $owner = $this->getOwner($subject);

        return $owner !== null && $owner->getId() !== null && $owner->getId() === $user->getId();
    }

    private function getOwner($subject): ?User
    {
        if ($subject instanceof User) {
            return $subject;
        }

        if ($subject instanceof Years || $subject instanceof Favorites) {
            return $subject->getUser();
        }

        // Éléments rattachés à une année de formation.
        $year = $subject->getYear();

        return $year ? $year->getUser() : null;
    }
}
