<?php

namespace App\Exceptions;

use Symfony\Component\Security\Core\Exception\AccountStatusException;



class AccountDisabledException extends AccountStatusException
{
    /**
     * {@inheritdoc}
     */
    public function getMessageKey()
    {
        return "Votre compte doit d'abord être validé par email";
    }
}
