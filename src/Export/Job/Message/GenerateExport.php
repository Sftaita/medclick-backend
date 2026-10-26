<?php

namespace App\Export\Job\Message;

/**
 * Message Messenger : générer l'export $jobId (transport « exports », consommé par le worker).
 */
final class GenerateExport
{
    public function __construct(public readonly int $jobId)
    {
    }
}
