<?php

namespace App\Export\CarnetDeStage;

use Composer\InstalledVersions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Gabarit du carnet de stage (public/ExcelTemplate.xlsx), analysé une fois puis mis en cache.
 *
 * L'analyse du gabarit coûte ~3 s (1 190 formules, 665 fusions) ; la copie mise en cache est
 * restaurée en ~0,1 s. C'est le mécanisme de copie de PhpSpreadsheet (Spreadsheet::copy() =
 * unserialize(serialize())), donc un classeur strictement identique à un chargement direct
 * (vérifié par les tests d'équivalence). Clé du cache : contenu et chemin du gabarit, version de
 * PhpSpreadsheet. Le cache est signé (HMAC avec le secret de l'application) : un fichier altéré
 * est ignoré et le gabarit rechargé. Préparé par cache:warmup (lancé à chaque déploiement).
 */
final class CarnetDeStageTemplate implements CacheWarmerInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/ExcelTemplate.xlsx')]
        private string $templatePath,
        #[Autowire('%kernel.cache_dir%/carnet-de-stage')]
        private string $cacheDir,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function load(): Spreadsheet
    {
        $cacheFile = $this->cacheFile();
        $content = is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
        if ($content !== false && strlen($content) > 64) {
            $signature = substr($content, 0, 64);
            $payload = substr($content, 64);
            if (hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)) {
                $spreadsheet = unserialize($payload);
                if ($spreadsheet instanceof Spreadsheet) {
                    return $spreadsheet;
                }
            }
        }

        $spreadsheet = IOFactory::createReader('Xlsx')->load($this->templatePath);
        $this->store($cacheFile, serialize($spreadsheet));

        return $spreadsheet;
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->load()->disconnectWorksheets();

        return [];
    }

    private function cacheFile(): string
    {
        $key = sha1(implode('|', [
            $this->templatePath,
            is_file($this->templatePath) ? sha1_file($this->templatePath) : '',
            InstalledVersions::getReference('phpoffice/phpspreadsheet') ?? InstalledVersions::getVersion('phpoffice/phpspreadsheet'),
            PHP_VERSION,
        ]));

        return "{$this->cacheDir}/template-$key.bin";
    }

    private function store(string $cacheFile, string $payload): void
    {
        // Écriture atomique ; un échec (droits, disque) n'empêche pas l'export.
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return;
        }
        $temporary = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, hash_hmac('sha256', $payload, $this->secret) . $payload) !== false) {
            @rename($temporary, $cacheFile) || @unlink($temporary);
        }
    }
}
