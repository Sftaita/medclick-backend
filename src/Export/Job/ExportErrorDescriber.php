<?php

namespace App\Export\Job;

use App\Export\ExportFailure;

/**
 * Décrit un échec d'export SANS donnée personnelle ni médicale : le message brut d'une exception
 * peut contenir des valeurs de cellules ou des paramètres SQL, il n'est donc jamais conservé.
 * Seuls la classe d'exception, l'emplacement dans le code (fichier:ligne), le SQLSTATE éventuel et
 * une pile d'appels sans arguments sont retenus.
 */
final class ExportErrorDescriber
{
    private const MESSAGES = [
        ExportFailure::DATABASE => 'La lecture des données a échoué.',
        ExportFailure::TIMEOUT => 'La génération a dépassé la durée maximale autorisée.',
        ExportFailure::MEMORY => 'La génération a dépassé la mémoire disponible.',
        ExportFailure::XLSX_GENERATION => 'La construction du fichier Excel a échoué.',
        ExportFailure::FILE_WRITE => "L'enregistrement du fichier a échoué.",
        ExportFailure::WORKER_INTERRUPTED => 'La génération a été interrompue (worker arrêté ou bloqué).',
        ExportFailure::WORKER_UNAVAILABLE => "Le service de génération n'a pas pris la demande en charge.",
        ExportFailure::NOT_FOUND => 'Données introuvables.',
        ExportFailure::ACCESS_DENIED => 'Accès refusé à cette année de formation.',
        ExportFailure::UNEXPECTED => 'Une erreur inattendue est survenue.',
    ];

    public static function message(string $category): string
    {
        return self::MESSAGES[$category] ?? self::MESSAGES[ExportFailure::UNEXPECTED];
    }

    /**
     * Code technique stable (sert aussi au regroupement des alertes), ex. :
     * « TypeError@CarnetDeStageSpreadsheetBuilder.php:612 » ou « Doctrine\DBAL\…\ConnectionException SQLSTATE[HY000] ».
     */
    public static function code(\Throwable $exception): string
    {
        $root = $exception instanceof ExportFailure && $exception->getPrevious() ? $exception->getPrevious() : $exception;
        $code = (new \ReflectionClass($root))->getShortName() . '@' . self::applicationLocation($root);
        for ($e = $root; $e !== null; $e = $e->getPrevious()) {
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $matches)) {
                return $code . ' SQLSTATE[' . $matches[1] . ']';
            }
        }

        return $code;
    }

    /**
     * Contexte pour les journaux techniques : classes et emplacements de la chaîne d'exceptions,
     * pile d'appels sans arguments (aucune valeur manipulée n'y figure).
     */
    public static function logContext(\Throwable $exception): array
    {
        $chain = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $chain[] = $e::class . ' @ ' . self::relative($e->getFile()) . ':' . $e->getLine();
        }
        $frames = [];
        foreach (array_slice(($exception->getPrevious() ?? $exception)->getTrace(), 0, 25) as $frame) {
            $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?') . '()'
                . (isset($frame['file']) ? ' ' . self::relative($frame['file']) . ':' . ($frame['line'] ?? '?') : '');
        }

        return ['exceptions' => $chain, 'code' => self::code($exception), 'trace' => $frames];
    }

    /**
     * Emplacement le plus utile au diagnostic : celui de l'exception s'il est dans le code de
     * l'application (src/), sinon le premier appel depuis src/ (ex. la requête du chargeur plutôt
     * que le convertisseur d'exceptions de Doctrine).
     */
    private static function applicationLocation(\Throwable $exception): string
    {
        $src = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];
        foreach ($frames as $frame) {
            if (isset($frame['file']) && str_starts_with(str_replace('/', DIRECTORY_SEPARATOR, $frame['file']), $src)) {
                return basename($frame['file']) . ':' . ($frame['line'] ?? 0);
            }
        }

        return basename($exception->getFile()) . ':' . $exception->getLine();
    }

    private static function relative(string $path): string
    {
        $root = dirname(__DIR__, 3);

        return str_starts_with($path, $root) ? ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/') : basename($path);
    }
}
