<?php

namespace App\Service;

use App\Entity\PartnerLogo;

/**
 * Contrôle d'un logo de partenaire envoyé par l'administration : PNG ou SVG uniquement,
 * 1 Mo au plus, PNG d'au moins 512 px de large. Le type est déterminé par le contenu, jamais
 * par le nom ou le type MIME déclaré.
 *
 * Un SVG peut contenir du code : il est REFUSÉ (pas « nettoyé ») s'il contient un script, un
 * gestionnaire d'événement (on…), un objet étranger, une ressource externe, une DTD ou des
 * entités XML. Il est en outre servi avec une CSP qui interdit tout script
 * (PartnerController::logo) et affiché via <img> par le front, où les scripts ne s'exécutent pas.
 */
class PartnerLogoValidator
{
    public const MAX_BYTES = 1048576;
    public const MIN_PNG_WIDTH = 512;

    private const FORBIDDEN_ELEMENTS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'canvas', 'handler', 'listener', 'set', 'animate', 'animatemotion', 'animatetransform'];

    /**
     * @return array{mime: string, width: ?int, height: ?int}
     *
     * @throws \InvalidArgumentException message lisible pour l'administrateur
     */
    public function validate(string $data): array
    {
        if ($data === '') {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }
        if (strlen($data) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Le logo dépasse 1 Mo.');
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return $this->validatePng($data);
        }
        if ($this->looksLikeSvg($data)) {
            $this->validateSvg($data);

            return ['mime' => PartnerLogo::MIME_SVG, 'width' => null, 'height' => null];
        }

        throw new \InvalidArgumentException('Format refusé : envoyez un fichier SVG ou PNG.');
    }

    /** @return array{mime: string, width: int, height: int} */
    private function validatePng(string $data): array
    {
        $info = @getimagesizefromstring($data);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            throw new \InvalidArgumentException('Ce fichier PNG est illisible.');
        }
        if ($info[0] < self::MIN_PNG_WIDTH) {
            throw new \InvalidArgumentException(sprintf('Le logo PNG doit mesurer au moins %d px de large (reçu : %d px).', self::MIN_PNG_WIDTH, $info[0]));
        }

        return ['mime' => PartnerLogo::MIME_PNG, 'width' => $info[0], 'height' => $info[1]];
    }

    private function looksLikeSvg(string $data): bool
    {
        $head = ltrim(substr($data, 0, 2048), "\xEF\xBB\xBF \t\r\n");

        return str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains($head, '<svg'));
    }

    private function validateSvg(string $data): void
    {
        $refuse = static fn (string $why) => new \InvalidArgumentException("SVG refusé : $why. Exportez le logo en SVG simple (formes et textes) ou en PNG.");

        // Pas de DTD ni d'entités : ni XXE, ni « billion laughs ».
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $data)) {
            throw $refuse('DTD ou entités XML interdites');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($data, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $doc->documentElement === null || strtolower($doc->documentElement->localName) !== 'svg') {
            throw $refuse('fichier SVG invalide');
        }

        foreach ($doc->getElementsByTagName('*') as $element) {
            $name = strtolower($element->localName);
            if (in_array($name, self::FORBIDDEN_ELEMENTS, true)) {
                throw $refuse("élément <$element->localName> interdit");
            }
            if ($name === 'style' && $this->hasExternalReference($element->textContent)) {
                throw $refuse('feuille de style faisant appel à une ressource externe');
            }
            foreach ($element->attributes as $attribute) {
                $attr = strtolower($attribute->localName);
                $value = trim($attribute->value);
                if (str_starts_with($attr, 'on')) {
                    throw $refuse("attribut « $attribute->name » interdit");
                }
                if ($attr === 'href' && $value !== '' && !str_starts_with($value, '#')) {
                    throw $refuse('lien ou image externe interdit');
                }
                if (preg_match('/javascript:|vbscript:|data:text\/html/i', $value) || ($attr === 'style' && $this->hasExternalReference($value))) {
                    throw $refuse("valeur interdite dans « $attribute->name »");
                }
            }
        }
    }

    /** url(…) vers autre chose qu'une référence interne (#id), @import, expression(). */
    private function hasExternalReference(string $css): bool
    {
        if (preg_match('/@import|expression\s*\(/i', $css)) {
            return true;
        }
        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $matches);
        foreach ($matches[2] as $target) {
            if (!str_starts_with(trim($target), '#')) {
                return true;
            }
        }

        return false;
    }
}
