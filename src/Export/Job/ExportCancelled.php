<?php

namespace App\Export\Job;

/**
 * Levée par le suivi de progression quand l'utilisateur a annulé l'export en cours.
 */
final class ExportCancelled extends \RuntimeException
{
}
