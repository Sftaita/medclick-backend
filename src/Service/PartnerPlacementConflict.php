<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Publication refusée : l'emplacement est déjà attribué sur la période. Les affectations en
 * conflit sont renvoyées à l'administration, qui peut confirmer le remplacement (replace).
 */
final class PartnerPlacementConflict extends \InvalidArgumentException
{
    /** @param list<array<string, mixed>> $conflicts */
    public function __construct(string $message, public readonly array $conflicts)
    {
        parent::__construct($message);
    }
}
