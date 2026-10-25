<?php

namespace App\Tests\Api;

use App\Entity\Partner;
use App\Tests\ApiTestBase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Module partenaire : logo (contrôles PNG/SVG), brouillon, publication, programmation,
 * chevauchements, passage automatique, retrait, reprogrammation, droits.
 */
class PartnerTest extends ApiTestBase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 40"><defs><linearGradient id="g"><stop offset="0" stop-color="#2447D6"/></linearGradient></defs><path d="M7 35C4 21 13 8 33 3z" fill="url(#g)"/><text x="40" y="28">OrthoNova</text></svg>';

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));
    }

    // ------------------------------------------------------------------ Logo

    public function testPngAndSvgLogosAreAcceptedAndServedSafely(): void
    {
        $png = $this->upload(self::png(600, 200), 'logo.png');
        $this->assertResponseStatusCodeSame(201);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\.png$/', $png['logo']);
        $this->assertSame([600, 200], [$png['width'], $png['height']]);

        $svg = $this->upload(self::SVG, 'logo.svg');
        $this->assertResponseStatusCodeSame(201);
        $this->assertStringEndsWith('.svg', $svg['logo']);

        // Même contenu envoyé deux fois : un seul stockage, même nom.
        $this->assertSame($svg['logo'], $this->upload(self::SVG, 'autre-nom.svg')['logo']);

        $response = static::createClient()->request('GET', '/api/partner/logo/' . $svg['logo']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(self::SVG, $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('image/svg+xml', $headers['content-type'][0]);
        $this->assertStringContainsString("default-src 'none'", $headers['content-security-policy'][0]);
        $this->assertSame('nosniff', $headers['x-content-type-options'][0]);
        $this->assertStringContainsString('immutable', $headers['cache-control'][0]);

        static::createClient()->request('GET', '/api/partner/logo/' . str_repeat('a', 64) . '.png');
        $this->assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedLogos(): iterable
    {
        yield 'PNG trop étroit' => [self::png(300, 100), 'Le logo PNG doit mesurer au moins 512 px de large (reçu : 300 px).'];
        yield 'texte déguisé' => ['GIF89a pas une image', 'Format refusé : envoyez un fichier SVG ou PNG.'];
        yield 'SVG avec script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'élément <script> interdit'];
        yield 'SVG avec onload' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>', 'attribut « onload » interdit'];
        yield 'SVG image externe' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="https://evil.example/x.png"/></svg>', 'lien ou image externe interdit'];
        yield 'SVG javascript: dans un lien' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>', 'lien ou image externe interdit'];
        yield 'SVG avec entités (XXE)' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>', 'DTD ou entités XML interdites'];
        yield 'SVG foreignObject' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>', 'élément <foreignObject> interdit'];
        yield 'SVG style @import' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.example/a.css);</style></svg>', 'feuille de style faisant appel à une ressource externe'];
        yield 'fichier de plus de 1 Mo' => ['<svg xmlns="http://www.w3.org/2000/svg"><!--' . str_repeat('a', 1048600) . '--></svg>', 'Le logo dépasse 1 Mo.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedLogos')]
    public function testDangerousOrInvalidLogosAreRefused(string $content, string $message): void
    {
        $this->upload($content, 'logo.svg');

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString($message, $this->admin->getResponse()->toArray(false)['message']);
    }

    // ------------------------------------------------------- Brouillon, publication

    public function testDraftIsSavedButNeverShown(): void
    {
        $draft = $this->post(['name' => 'OrthoNova', 'tagline' => 'Innover aujourd\'hui']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['draft', 'draft'], [$draft['status'], $draft['state']]);
        $this->assertActiveIsEmpty();
    }

    public function testPublicationRequiresLogoAndStartDate(): void
    {
        $this->post(['name' => 'OrthoNova', 'startsOn' => $this->day(0), 'publish' => true]);
        $this->assertBadRequest('Ajoutez un logo avant de publier.');

        $this->post(['name' => 'OrthoNova', 'logo' => $this->logo(), 'publish' => true]);
        $this->assertBadRequest('Indiquez la date de début d\'affichage.');

        $this->post(['name' => 'OrthoNova', 'logo' => $this->logo(), 'startsOn' => $this->day(-30), 'endsOn' => $this->day(-1), 'publish' => true]);
        $this->assertBadRequest('La date de fin est déjà passée');

        $this->assertSame(0, $this->em->getRepository(Partner::class)->count([]));
    }

    public function testPublishedPartnerIsShownEverywhereWithItsLogo(): void
    {
        $logo = $this->logo();
        $partner = $this->post($this->valid(['logo' => $logo, 'startsOn' => $this->day(0), 'publish' => true]));

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['published', 'live'], [$partner['status'], $partner['state']]);

        $active = static::createClient()->request('GET', '/api/partner/active')->toArray();
        $this->assertSame([
            'id' => $partner['id'],
            'name' => 'OrthoNova',
            'websiteUrl' => 'https://www.exemple-partenaire.be',
            'tagline' => 'Innover aujourd\'hui pour mieux soigner demain',
            'about' => 'Un partenaire engagé aux côtés des médecins assistants.',
            'commitments' => ['Solutions innovantes', 'Formation continue'],
            'logo' => $logo,
            'startsOn' => $this->day(0),
            'endsOn' => null,
        ], $active);
    }

    /**
     * Régression (staging) : PHP en UTC (conteneur), « aujourd'hui » calculé à Bruxelles. Un
     * partenaire commençant aujourd'hui, relu depuis la base, doit être « live », pas « scheduled ».
     */
    public function testStateIsComputedOnDaysWhateverThePhpTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $partner = $this->post($this->valid(['startsOn' => $this->day(0), 'endsOn' => $this->day(0), 'publish' => true]));
            $this->em->clear();

            $this->assertSame('live', $partner['state']);
            $states = array_column($this->admin->request('GET', '/api/admin/partners')->toArray()['partners'], 'state');
            $this->assertSame(['live'], $states);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testFuturePartnerIsScheduledNotShown(): void
    {
        $partner = $this->post($this->valid(['startsOn' => $this->day(3), 'publish' => true]));

        $this->assertSame('scheduled', $partner['state']);
        $this->assertActiveIsEmpty();
    }

    public function testOverlappingPeriodsAreRefusedButConsecutiveOnesAreAllowed(): void
    {
        $this->post($this->valid(['name' => 'OrthoNova', 'startsOn' => $this->day(0), 'endsOn' => $this->day(10), 'publish' => true]));

        $this->post($this->valid(['name' => 'Suivant', 'startsOn' => $this->day(10), 'publish' => true]));
        $this->assertBadRequest('Cette période chevauche celle de « OrthoNova »');

        // Sans date de fin, le premier occupe tout l'avenir : le suivant ne pourrait jamais commencer.
        $this->post($this->valid(['name' => 'Suivant', 'startsOn' => $this->day(11), 'publish' => true]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testDisplayedPartnerSwitchesAutomaticallyWithTheDates(): void
    {
        $first = $this->post($this->valid(['name' => 'Premier', 'startsOn' => $this->day(0), 'endsOn' => $this->day(0), 'publish' => true]));
        $second = $this->post($this->valid(['name' => 'Second', 'startsOn' => $this->day(1), 'publish' => true]));
        $this->assertSame('Premier', $this->active()['name']);

        // Le lendemain (simulé en décalant les périodes d'un jour) : le second, sans action.
        foreach ([$first['id'] => [-1, -1], $second['id'] => [0, null]] as $id => [$start, $end]) {
            $partner = $this->em->find(Partner::class, $id);
            $partner->setStartsOn(new \DateTimeImmutable($this->day($start)))->setEndsOn($end === null ? null : new \DateTimeImmutable($this->day($end)));
        }
        $this->em->flush();

        $this->assertSame('Second', $this->active()['name']);
        $states = array_column($this->admin->request('GET', '/api/admin/partners')->toArray()['partners'], 'state', 'name');
        $this->assertSame(['Second' => 'live', 'Premier' => 'ended'], $states);
    }

    public function testWithdrawalHidesThePartnerAndKeepsHistory(): void
    {
        $partner = $this->post($this->valid(['startsOn' => $this->day(0), 'publish' => true]));

        $withdrawn = $this->admin->request('POST', "/api/admin/partners/{$partner['id']}/withdraw")->toArray();

        $this->assertSame('withdrawn', $withdrawn['state']);
        $this->assertNotNull($withdrawn['withdrawnAt']);
        $this->assertActiveIsEmpty();
        $list = $this->admin->request('GET', '/api/admin/partners')->toArray();
        $this->assertNull($list['liveId']);
        $this->assertCount(1, $list['partners']);

        // Un partenaire retiré libère sa période et ne se modifie plus directement.
        $this->admin->request('PUT', "/api/admin/partners/{$partner['id']}", ['json' => $this->valid(['startsOn' => $this->day(0)])]);
        $this->assertResponseStatusCodeSame(409);
        $this->post($this->valid(['name' => 'Remplaçant', 'startsOn' => $this->day(0), 'publish' => true]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testRescheduleCopiesContentIntoANewDraft(): void
    {
        $logo = $this->logo();
        $old = $this->post($this->valid(['logo' => $logo, 'startsOn' => $this->day(0), 'publish' => true]));
        $this->admin->request('POST', "/api/admin/partners/{$old['id']}/withdraw");

        $copy = $this->admin->request('POST', "/api/admin/partners/{$old['id']}/reschedule")->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertNotSame($old['id'], $copy['id']);
        $this->assertSame(['draft', 'OrthoNova', $logo, null, null], [$copy['status'], $copy['name'], $copy['logo'], $copy['startsOn'], $copy['endsOn']]);
        $this->assertSame($old['commitments'], $copy['commitments']);

        $published = $this->admin->request('PUT', "/api/admin/partners/{$copy['id']}", ['json' => array_merge($copy, ['startsOn' => $this->day(5), 'publish' => true])])->toArray();
        $this->assertSame('scheduled', $published['state']);
    }

    public function testEditingAPublishedPartnerKeepsItPublishedAndRechecksPeriods(): void
    {
        $live = $this->post($this->valid(['name' => 'OrthoNova', 'startsOn' => $this->day(0), 'endsOn' => $this->day(10), 'publish' => true]));
        $next = $this->post($this->valid(['name' => 'Suivant', 'startsOn' => $this->day(11), 'publish' => true]));

        $this->admin->request('PUT', "/api/admin/partners/{$live['id']}", ['json' => $this->valid(['name' => 'OrthoNova 2', 'logo' => $live['logo'], 'startsOn' => $this->day(0), 'endsOn' => $this->day(10)])]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('OrthoNova 2', $this->active()['name']);

        // Prolonger sur la période du suivant : refusé, rien n'est enregistré.
        $this->admin->request('PUT', "/api/admin/partners/{$live['id']}", ['json' => $this->valid(['name' => 'Modifié', 'logo' => $live['logo'], 'startsOn' => $this->day(0), 'endsOn' => $this->day(20)])]);
        $this->assertBadRequest('chevauche celle de « Suivant »');
        $this->assertSame('OrthoNova 2', $this->active()['name']);
        $this->assertSame($this->day(10), $this->active()['endsOn']);
        $this->assertNotNull($next['id']);
    }

    public function testOnlyDraftsCanBeDeleted(): void
    {
        $draft = $this->post(['name' => 'Brouillon']);
        $live = $this->post($this->valid(['startsOn' => $this->day(0), 'publish' => true]));

        $this->admin->request('DELETE', "/api/admin/partners/{$live['id']}");
        $this->assertResponseStatusCodeSame(409);
        $this->admin->request('DELETE', "/api/admin/partners/{$draft['id']}");
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->em->getRepository(Partner::class)->count([]));
    }

    /** @return iterable<string, array{array, string}> */
    public static function invalidFields(): iterable
    {
        yield 'nom vide' => [['name' => ' '], 'Le nom du partenaire est obligatoire.'];
        yield 'site non http' => [['websiteUrl' => 'javascript:alert(1)'], 'Site web : adresse commençant par https:// attendue.'];
        yield 'cinq engagements' => [['commitments' => ['a', 'b', 'c', 'd', 'e']], '4 engagements au maximum.'];
        yield 'engagement trop long' => [['commitments' => [str_repeat('x', 61)]], 'Chaque engagement : 60 caractères au maximum.'];
        yield 'fin avant début' => [['startsOn' => '2026-12-10', 'endsOn' => '2026-12-01'], 'La date de fin doit suivre la date de début.'];
        yield 'date invalide' => [['startsOn' => '31/12/2026'], 'Date de début invalide (AAAA-MM-JJ).'];
        yield 'logo inconnu' => [['logo' => str_repeat('b', 64) . '.png'], 'Logo introuvable : envoyez-le de nouveau.'];
        yield 'accroche trop longue' => [['tagline' => str_repeat('x', 161)], 'Accroche : 160 caractères au maximum.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testInvalidFieldsAreRefused(array $override, string $message): void
    {
        $this->admin->request('POST', '/api/admin/partners', ['json' => array_merge(['name' => 'OrthoNova'], $override)]);

        $this->assertBadRequest($message);
    }

    // ------------------------------------------------------------------ Droits

    public function testAdministrationIsRestrictedToAdmins(): void
    {
        $routes = [['GET', '/api/admin/partners'], ['POST', '/api/admin/partners'], ['PUT', '/api/admin/partners/1'], ['POST', '/api/admin/partners/1/withdraw'], ['POST', '/api/admin/partner-logos']];
        $user = $this->clientFor($this->createUser('alice@test.be'));
        foreach ($routes as [$method, $url]) {
            $this->assertSame(403, $user->request($method, $url, ['json' => ['name' => 'Pirate']])->getStatusCode(), "$method $url (utilisateur)");
        }
        $visitor = static::createClient();
        foreach ($routes as [$method, $url]) {
            $this->assertSame(401, $visitor->request($method, $url, ['json' => ['name' => 'Pirate']])->getStatusCode(), "$method $url (visiteur)");
        }
        $this->assertSame(0, $this->em->getRepository(Partner::class)->count([]));
    }

    public function testPublicRoutesNeedNoSession(): void
    {
        $logo = $this->logo();
        $this->post($this->valid(['logo' => $logo, 'startsOn' => $this->day(0), 'publish' => true]));

        $visitor = static::createClient();
        $this->assertSame('OrthoNova', $visitor->request('GET', '/api/partner/active')->toArray()['name']);
        $this->assertSame(200, $visitor->request('GET', '/api/partner/logo/' . $logo)->getStatusCode());
        $this->assertArrayNotHasKey('status', $visitor->request('GET', '/api/partner/active')->toArray());
    }

    public function testNoPartnerMeansEmptyResponse(): void
    {
        $this->assertActiveIsEmpty();
        $this->assertSame(['today' => $this->day(0), 'liveId' => null, 'partners' => []], $this->admin->request('GET', '/api/admin/partners')->toArray());
    }

    // ------------------------------------------------------------------ Outils

    private function valid(array $override = []): array
    {
        return array_merge([
            'name' => 'OrthoNova',
            'websiteUrl' => 'https://www.exemple-partenaire.be',
            'tagline' => 'Innover aujourd\'hui pour mieux soigner demain',
            'about' => 'Un partenaire engagé aux côtés des médecins assistants.',
            'commitments' => ['Solutions innovantes', ' ', 'Formation continue'],
            'logo' => $override['logo'] ?? $this->logo(),
        ], $override);
    }

    private function post(array $data): array
    {
        return $this->admin->request('POST', '/api/admin/partners', ['json' => $data])->toArray(false);
    }

    private function logo(): string
    {
        return $this->upload(self::SVG, 'logo.svg')['logo'];
    }

    private function upload(string $content, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $content);

        return $this->admin->request('POST', '/api/admin/partner-logos', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => new UploadedFile($path, $name, null, null, true)]],
        ])->toArray(false);
    }

    // Lu avec le client admin : static::createClient() remplacerait le client que vérifient les
    // assertions assertResponse*. L'accès anonyme est testé à part (testPublicRoutesNeedNoSession).
    private function active(): array
    {
        return $this->admin->request('GET', '/api/partner/active')->toArray();
    }

    private function assertActiveIsEmpty(): void
    {
        $this->assertSame(204, $this->admin->request('GET', '/api/partner/active')->getStatusCode());
    }

    private function assertBadRequest(string $message): void
    {
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString($message, $this->admin->getResponse()->toArray(false)['message']);
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify("$offset day")->format('Y-m-d');
    }

    /** PNG valide (niveaux de gris, 8 bits) construit sans GD. */
    private static function png(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $raw = str_repeat("\0" . str_repeat("\xff", $width), $height);

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw))
            . $chunk('IEND', '');
    }
}
