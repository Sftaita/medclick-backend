<?php

namespace App\Security;

use App\Entity\ConnectionHistory;
use App\Entity\User;
use App\Exceptions\AccountDisabledException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

class UserChecker implements UserCheckerInterface
{

    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }
    public function checkPreAuth(UserInterface $user)
    {


        if ($user instanceof User) {

            $token = $user->getToken();

            if ($token !== null) {
             throw new AccountDisabledException();
            }
        }
    }

    /**
     * Appelé après la vérification du mot de passe : seules les connexions réussies
     * sont enregistrées dans l'historique.
     */
    public function checkPostAuth(UserInterface $user)
    {
        if ($user instanceof User) {
            $Connection = new ConnectionHistory();
            $Connection->setUser($user)
                        ->setDate(new \DateTime());

            $this->em->persist($Connection);
            $this->em->flush();
        }
    }
}