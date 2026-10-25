<?php

namespace App\Service;

/**
 * Contrôle et optimisation d'un visuel de campagne envoyé par l'administration.
 *
 * - PNG, JPEG ou WebP uniquement, type déterminé par le CONTENU (signature + décodage), jamais
 *   par le nom ni le type MIME déclaré ; images animées refusées.
 * - 5 Mo au plus ; 480 px minimum par côté ; 8000 px et 25 mégapixels au plus (contrôlés AVANT
 *   le décodage : pas de « bombe de décompression »).
 * - Toujours redessiné puis réencodé en WebP : les métadonnées (EXIF, GPS…) et tout contenu
 *   caché après l'image disparaissent ; orientation EXIF des photos JPEG appliquée ; réduit à
 *   2560 px sur le plus grand côté ; 1,5 Mo au plus une fois optimisé (la publicité s'affiche à
 *   la connexion : un visuel lourd la ralentirait).
 */
class MarketingImageProcessor
{
    public const MAX_UPLOAD_BYTES = 5242880;
    public const MIN_SIDE = 480;
    public const MAX_SIDE = 8000;
    public const MAX_PIXELS = 25000000;
    public const OUTPUT_MAX_SIDE = 2560;
    public const MAX_OUTPUT_BYTES = 1572864;
    private const QUALITIES = [82, 72, 60];

    /**
     * @return array{data: string, width: int, height: int}
     *
     * @throws \InvalidArgumentException message lisible pour l'administrateur
     */
    public function process(string $data): array
    {
        if ($data === '') {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }
        if (strlen($data) > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Le visuel dépasse 5 Mo.');
        }
        $type = $this->detectType($data);
        $info = @getimagesizefromstring($data);
        if ($info === false || $info[2] !== $type) {
            throw new \InvalidArgumentException('Image illisible ou endommagée.');
        }
        [$width, $height] = $info;
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(sprintf('Image trop grande (%d × %d px) : %d px par côté et 25 mégapixels au maximum.', $width, $height, self::MAX_SIDE));
        }
        if ($this->isAnimated($data, $type)) {
            throw new \InvalidArgumentException('Les images animées ne sont pas acceptées.');
        }

        // Décodage d'une image de 25 Mpx : jusqu'à ~100 Mo en mémoire (limite globale : 128 Mo).
        $previousLimit = ini_get('memory_limit');
        if ($previousLimit !== false && $previousLimit !== '-1') {
            ini_set('memory_limit', '512M');
        }
        try {
            $image = @imagecreatefromstring($data);
            if ($image === false) {
                throw new \InvalidArgumentException('Image illisible ou endommagée.');
            }
            if ($type === IMAGETYPE_JPEG) {
                $image = $this->applyOrientation($image, $this->jpegOrientation($data));
            }
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
                throw new \InvalidArgumentException(sprintf('Image trop petite (%d × %d px) : %d px minimum par côté.', $width, $height, self::MIN_SIDE));
            }
            $image = $this->fit($image);

            foreach (self::QUALITIES as $quality) {
                ob_start();
                $encoded = imagewebp($image, null, $quality);
                $webp = (string) ob_get_clean();
                if (!$encoded || $webp === '') {
                    throw new \RuntimeException('Encodage WebP impossible.');
                }
                if (strlen($webp) <= self::MAX_OUTPUT_BYTES) {
                    return ['data' => $webp, 'width' => imagesx($image), 'height' => imagesy($image)];
                }
            }

            throw new \InvalidArgumentException('Visuel trop lourd même optimisé (1,5 Mo au maximum) : réduisez sa complexité ou ses dimensions.');
        } finally {
            if ($previousLimit !== false) {
                ini_set('memory_limit', $previousLimit);
            }
        }
    }

    private function detectType(string $data): int
    {
        // PNG : l'en-tête IHDR suit obligatoirement la signature (sinon dimensions lues au hasard).
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            if (substr($data, 12, 4) !== 'IHDR') {
                throw new \InvalidArgumentException('Image illisible ou endommagée.');
            }

            return IMAGETYPE_PNG;
        }
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return IMAGETYPE_JPEG;
        }
        if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            return IMAGETYPE_WEBP;
        }

        throw new \InvalidArgumentException('Format refusé : envoyez une image PNG, JPEG ou WebP.');
    }

    /** APNG (chunk acTL avant les données) ou WebP animé (indicateur du chunk VP8X). */
    private function isAnimated(string $data, int $type): bool
    {
        if ($type === IMAGETYPE_PNG) {
            $idat = strpos($data, 'IDAT');
            $actl = strpos($data, 'acTL');

            return $actl !== false && ($idat === false || $actl < $idat);
        }
        if ($type === IMAGETYPE_WEBP) {
            return substr($data, 12, 4) === 'VP8X' && (ord($data[20] ?? "\0") & 0x02) !== 0;
        }

        return false;
    }

    /** Réduction à OUTPUT_MAX_SIDE, couche alpha conservée ; toujours une image en couleurs vraies. */
    private function fit(\GdImage $image): \GdImage
    {
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(1, self::OUTPUT_MAX_SIDE / max($width, $height));
        if ($ratio < 1) {
            $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
            $image = $target;
        }
        imagesavealpha($image, true);

        return $image;
    }

    /** Orientation EXIF (1 à 8) d'un JPEG, 1 si absente : lecture du seul tag 0x0112 de l'IFD0. */
    private function jpegOrientation(string $data): int
    {
        $length = strlen($data);
        $offset = 2;
        while ($offset + 4 <= $length && $data[$offset] === "\xFF") {
            $marker = ord($data[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = unpack('n', substr($data, $offset + 2, 2))[1];
            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
                $tiff = $offset + 10;
                $little = substr($data, $tiff, 2) === 'II';
                // Lecture bornée : un EXIF tronqué ou malformé donne 0, jamais une erreur.
                $u16 = static fn (int $at) => strlen($v = substr($data, $at, 2)) === 2 ? unpack($little ? 'v' : 'n', $v)[1] : 0;
                $u32 = static fn (int $at) => strlen($v = substr($data, $at, 4)) === 4 ? unpack($little ? 'V' : 'N', $v)[1] : 0;
                $ifd = $tiff + $u32($tiff + 4);
                $entries = min($u16($ifd), 512);
                for ($i = 0; $i < $entries; ++$i) {
                    $entry = $ifd + 2 + 12 * $i;
                    if ($u16($entry) === 0x0112) {
                        $orientation = $u16($entry + 8);

                        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
                    }
                }

                return 1;
            }
            $offset += 2 + $size;
        }

        return 1;
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        // imagerotate : angle dans le sens inverse des aiguilles d'une montre.
        $rotate = static fn (\GdImage $img, int $angle) => imagerotate($img, $angle, 0) ?: $img;
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = $rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = $rotate($image, -90);
                break;
            case 7:
                $image = $rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = $rotate($image, 90);
                break;
        }

        return $image;
    }
}
