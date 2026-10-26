<?php

namespace App\Export\Job;

use App\Entity\ExportJob;

/**
 * Fichiers générés : un fichier par export, nommé d'après l'identifiant public (UUID aléatoire),
 * dans un dossier hors de la racine web (var/exports par défaut). Les droits d'accès sont vérifiés
 * par l'API au téléchargement ; le dossier n'est jamais servi directement.
 */
final class ExportStorage
{
    public function __construct(private ExportSettings $settings)
    {
    }

    public function directory(): string
    {
        $directory = rtrim($this->settings->storageDir, '/\\');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Dossier des exports impossible à créer');
        }

        return $directory;
    }

    public function fileNameFor(ExportJob $job): string
    {
        return $job->getPublicId() . '.xlsx';
    }

    public function pathFor(ExportJob $job): ?string
    {
        $name = $job->getFileName();
        // Le nom vient de la base, mais ne doit jamais sortir du dossier des exports.
        if ($name === null || !preg_match('/^[0-9a-f-]{36}\.xlsx$/', $name)) {
            return null;
        }

        return $this->directory() . '/' . $name;
    }

    public function exists(ExportJob $job): bool
    {
        $path = $this->pathFor($job);

        return $path !== null && is_file($path);
    }

    public function delete(ExportJob $job): bool
    {
        $path = $this->pathFor($job);

        return $path !== null && is_file($path) && @unlink($path);
    }

    /**
     * Fichiers du dossier qui ne correspondent à aucun export connu (ou fichiers temporaires
     * « .part » d'une génération interrompue), plus anciens que $olderThan.
     *
     * @param callable(string): bool $isKnown reçoit l'identifiant public extrait du nom
     * @return list<string> chemins supprimés
     */
    public function deleteOrphans(callable $isKnown, \DateTimeImmutable $olderThan): array
    {
        $deleted = [];
        foreach (glob($this->directory() . '/*') ?: [] as $path) {
            if (!is_file($path) || filemtime($path) >= $olderThan->getTimestamp()) {
                continue;
            }
            $name = basename($path);
            $orphan = str_ends_with($name, '.part')
                || !preg_match('/^([0-9a-f-]{36})\.xlsx$/', $name, $matches)
                || !$isKnown($matches[1]);
            if ($orphan && @unlink($path)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }
}
