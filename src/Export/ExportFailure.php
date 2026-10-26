<?php

namespace App\Export;

/**
 * Échec d'un export, qualifié : catégorie (pour l'historique, la surveillance et les alertes) et
 * étape en cours. Le message ne contient jamais de donnée médicale ni personnelle.
 */
final class ExportFailure extends \RuntimeException
{
    public const DATABASE = 'database';
    public const TIMEOUT = 'timeout';
    public const MEMORY = 'memory';
    public const XLSX_GENERATION = 'xlsx_generation';
    public const FILE_WRITE = 'file_write';
    public const WORKER_INTERRUPTED = 'worker_interrupted';
    public const WORKER_UNAVAILABLE = 'worker_unavailable';
    public const NOT_FOUND = 'not_found';
    public const ACCESS_DENIED = 'access_denied';
    public const UNEXPECTED = 'unexpected';

    public const CATEGORIES = [self::DATABASE, self::TIMEOUT, self::MEMORY, self::XLSX_GENERATION, self::FILE_WRITE,
        self::WORKER_INTERRUPTED, self::WORKER_UNAVAILABLE, self::NOT_FOUND, self::ACCESS_DENIED, self::UNEXPECTED];

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?ExportStep $step = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
