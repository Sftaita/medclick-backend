<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Marketing;
use App\Entity\MarketingImage;
use App\Service\MarketingImageProcessor;
use App\Tests\ApiTestBase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Visuels importés des campagnes (/api/admin/marketing-images, /api/marketing/image/…) :
 * formats acceptés et optimisation, rejet des fichiers invalides, droits, association aux
 * campagnes, suppression des visuels inutilisés, compatibilité des anciennes campagnes (URL
 * externes, images collées tronquées de « Test Arthrex »).
 */
class MarketingImageTest extends ApiTestBase
{
    private Client $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));
    }

    public static function formats(): iterable
    {
        yield 'PNG transparent' => ['png', 'visuel.png'];
        yield 'JPEG' => ['jpeg', 'visuel.jpg'];
        yield 'WebP' => ['webp', 'visuel.webp'];
    }

    #[DataProvider('formats')]
    public function testEachFormatIsImportedAsWebpAndServedPublicly(string $format, string $name): void
    {
        $upload = $this->upload(self::image($format, 1200, 800), $name);

        $this->assertResponseStatusCodeSame(201);
        $this->assertMatchesRegularExpression('#^/api/marketing/image/[a-f0-9]{64}\.webp$#', $upload['url']);
        $this->assertSame([1200, 800], [$upload['width'], $upload['height']]);

        // Public (page de connexion, sans session), servi comme une image inerte.
        $client = static::createClient();
        $response = $client->request('GET', $upload['url']);
        $this->assertResponseStatusCodeSame(200);
        $headers = $response->getHeaders();
        $this->assertSame('image/webp', $headers['content-type'][0]);
        $this->assertSame('nosniff', $headers['x-content-type-options'][0]);
        $this->assertStringContainsString('sandbox', $headers['content-security-policy'][0]);
        $this->assertStringContainsString('immutable', $headers['cache-control'][0]);
        $body = $response->getContent();
        $this->assertSame('WEBP', substr($body, 8, 4));
        $this->assertSame(hash('sha256', $body) . '.webp', basename($upload['url']));
        $this->assertSame([1200, 800], array_slice(getimagesizefromstring($body), 0, 2));
    }

    public function testSameImageIsStoredOnce(): void
    {
        $image = self::image('png', 800, 600);
        $first = $this->upload($image, 'a.png');
        $second = $this->upload($image, 'b.png');

        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(1, $this->imageCount());
    }

    public function testLargeImageIsReducedAndMetadataRemoved(): void
    {
        $jpeg = self::withExif(self::image('jpeg', 4000, 2000), 1, 'Position GPS secrète');
        $upload = $this->upload($jpeg, 'photo.jpg');

        $this->assertSame([2560, 1280], [$upload['width'], $upload['height']]);
        $this->assertSame(strlen($jpeg), $upload['originalSize']);
        $stored = $this->storedImage($upload['url'])->getData();
        $this->assertStringNotContainsString('GPS', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    public function testJpegOrientationIsApplied(): void
    {
        // Photo de téléphone : pixels en paysage, EXIF « tourner de 90° » (orientation 6).
        $upload = $this->upload(self::withExif(self::image('jpeg', 900, 600), 6), 'telephone.jpg');

        $this->assertSame([600, 900], [$upload['width'], $upload['height']]);
    }

    public static function invalidFiles(): iterable
    {
        yield 'texte renommé en .png' => [fn () => 'ceci n\'est pas une image', 'faux.png', 'Format refusé : envoyez une image PNG, JPEG ou WebP.'];
        yield 'signature PNG suivie de contenu arbitraire' => [fn () => "\x89PNG\r\n\x1a\n" . str_repeat('<?php echo 1; ?>', 50), 'faux.png', 'Image illisible ou endommagée.'];
        yield 'SVG' => [fn () => '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="900"><script>alert(1)</script></svg>', 'visuel.svg', 'Format refusé : envoyez une image PNG, JPEG ou WebP.'];
        yield 'GIF' => [fn () => self::image('gif', 900, 900), 'visuel.gif', 'Format refusé : envoyez une image PNG, JPEG ou WebP.'];
        yield 'PHP déguisé en JPEG' => [fn () => "\xFF\xD8\xFF\xE0<?php echo 'MedClick'; ?>", 'shell.jpg', 'Image illisible ou endommagée.'];
        yield 'trop petite' => [fn () => self::image('png', 400, 900), 'petit.png', 'Image trop petite (400 × 900 px) : 480 px minimum par côté.'];
        yield 'trop grande' => [fn () => self::image('png', 8001, 480), 'large.png', 'Image trop grande (8001 × 480 px) : 8000 px par côté et 25 mégapixels au maximum.'];
        yield 'PNG animé' => [fn () => self::animatedPng(), 'anime.png', 'Les images animées ne sont pas acceptées.'];
        yield 'plus de 5 Mo' => [fn () => self::image('png', 600, 600) . str_repeat("\0", 5242880), 'lourd.png', 'Le visuel dépasse 5 Mo.'];
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFilesAreRefused(\Closure $content, string $name, string $message): void
    {
        $response = $this->upload($content(), $name);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame($message, $response['message']);
        $this->assertSame(0, $this->imageCount());
    }

    public function testProcessorRefusesMoreThan5MbWhateverThePhpLimits(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Le visuel dépasse 5 Mo.'));

        static::getContainer()->get(MarketingImageProcessor::class)->process(self::image('png', 600, 600) . str_repeat("\0", 5242880));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->admin->request('POST', '/api/admin/marketing-images', ['headers' => ['Content-Type' => 'multipart/form-data']]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testImportIsReservedToAdmins(): void
    {
        $url = $this->upload(self::image('png', 800, 600), 'a.png')['url'];
        $file = basename($url);

        $anonymous = static::createClient();
        $anonymous->request('POST', '/api/admin/marketing-images');
        $this->assertResponseStatusCodeSame(401);
        $anonymous->request('DELETE', '/api/admin/marketing-images/' . $file);
        $this->assertResponseStatusCodeSame(401);

        $resident = $this->clientFor($this->createUser('resident@test.be'));
        $resident->request('POST', '/api/admin/marketing-images', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => self::uploadedFile(self::image('png', 800, 600), 'b.png')]],
        ]);
        $this->assertResponseStatusCodeSame(403);
        $resident->request('DELETE', '/api/admin/marketing-images/' . $file);
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(1, $this->imageCount());
    }

    public function testArbitraryPathsAreNotServed(): void
    {
        $client = static::createClient();
        foreach (['/api/marketing/image/../../.env', '/api/marketing/image/' . str_repeat('a', 64) . '.php', '/api/marketing/image/' . str_repeat('a', 64) . '.webp'] as $path) {
            $client->request('GET', $path);
            $this->assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testCampaignUsesImportedVisualsAndServesThem(): void
    {
        $main = $this->upload(self::image('jpeg', 1920, 1080), 'principale.jpg')['url'];
        $phone = $this->upload(self::image('png', 1080, 1920), 'smartphone.png')['url'];

        $this->admin->request('POST', '/api/admin/marketing', ['json' => self::campaign(['large_screen_format' => $main, 'smartphone_format' => $phone])]);
        $this->assertResponseStatusCodeSame(201);

        $active = $this->admin->request('GET', '/api/marketing/active')->toArray();
        $this->assertSame($main, $active['formats']['large_screen']);
        $this->assertSame($phone, $active['formats']['smartphone']);
        $this->assertNull($active['formats']['tablet_portrait']);
    }

    public function testUnknownImportedVisualIsRefused(): void
    {
        $response = $this->admin->request('POST', '/api/admin/marketing', ['json' => self::campaign(['large_screen_format' => '/api/marketing/image/' . str_repeat('b', 64) . '.webp'])]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Image principale : visuel introuvable, importez-le de nouveau.', $response->toArray(false)['message']);
    }

    public function testReplacedOrRemovedVisualIsDeletedUnlessAnotherCampaignUsesIt(): void
    {
        $shared = $this->upload(self::image('png', 900, 900), 'commun.png')['url'];
        $own = $this->upload(self::image('jpeg', 1080, 1920), 'propre.jpg')['url'];
        $first = $this->createCampaignWith(['large_screen_format' => $shared, 'smartphone_format' => $own]);
        $this->createCampaignWith(['large_screen_format' => $shared]);
        $replacement = $this->upload(self::image('webp', 1920, 1080), 'nouvelle.webp')['url'];

        $this->admin->request('PUT', '/api/admin/marketing/' . $first, ['json' => self::campaign(['large_screen_format' => $replacement, 'smartphone_format' => null])]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($this->storedImage($own), 'visuel retiré : supprimé');
        $this->assertNotNull($this->storedImage($shared), 'visuel encore utilisé par l\'autre campagne : conservé');
        $this->assertNotNull($this->storedImage($replacement));
    }

    public function testDeletedCampaignTakesItsVisualsWithIt(): void
    {
        $main = $this->upload(self::image('png', 900, 900), 'a.png')['url'];
        $id = $this->createCampaignWith(['large_screen_format' => $main]);

        $this->admin->request('DELETE', '/api/admin/marketing/' . $id);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(0, $this->imageCount());
        static::createClient()->request('GET', $main);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAbandonedImportIsDiscardedButAUsedVisualIsKept(): void
    {
        $used = $this->upload(self::image('png', 900, 900), 'a.png')['url'];
        $this->createCampaignWith(['large_screen_format' => $used]);
        $abandoned = $this->upload(self::image('jpeg', 900, 900), 'b.jpg')['url'];

        $this->admin->request('DELETE', '/api/admin/marketing-images/' . basename($abandoned));
        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->storedImage($abandoned));

        $response = $this->admin->request('DELETE', '/api/admin/marketing-images/' . basename($used));
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('Ce visuel est utilisé par une campagne : il est conservé.', $response->toArray(false)['message']);
        $this->assertNotNull($this->storedImage($used));
    }

    public function testUnusedVisualsOlderThan24HoursArePurgedAtTheNextImport(): void
    {
        $old = $this->upload(self::image('png', 900, 900), 'ancien.png')['url'];
        $recent = $this->upload(self::image('jpeg', 900, 900), 'recent.jpg')['url'];
        $this->em->getConnection()->executeStatement('UPDATE marketing_image SET created_at = :date WHERE sha256 = :sha', [
            'date' => (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'),
            'sha' => basename($old, '.webp'),
        ]);

        $this->upload(self::image('webp', 900, 900), 'autre.webp');

        $this->assertNull($this->storedImage($old));
        $this->assertNotNull($this->storedImage($recent), 'import récent : un formulaire peut encore l\'enregistrer');
    }

    /**
     * Ancienne campagne (URL externes, images collées tronquées) : modifiable sans perte, ses
     * statistiques, dates, durée et règles de diffusion sont conservées, et ses visuels se
     * remplacent un par un par des imports.
     */
    public function testLegacyCampaignStaysEditableAndIsReplacedProgressively(): void
    {
        $pasted = 'data:image/jpeg;base64,' . str_repeat('A', 470);
        $legacy = (new Marketing())
            ->setCampaignName('Test Arthrex')
            ->setStatus('inactive')
            ->setStartDate(new \DateTime('2025-01-21'))
            ->setEndDate(new \DateTime('2025-01-22'))
            ->setViews(39)
            ->setClicks(3)
            ->setDuration(10)
            ->setRedirectUrl('https://www.exemple.be')
            ->setSmartphoneFormat($pasted)
            ->setTabletPortraitFormat('https://cdn.exemple.be/portrait.jpg')
            ->setScreen14InchFormat($pasted)
            ->setLargeScreenFormat('https://cdn.exemple.be/large.jpg');
        $this->em->persist($legacy);
        $this->em->flush();
        $id = $legacy->getId();
        $values = $this->admin->request('GET', '/api/admin/marketing/' . $id)->toArray();

        // 1. Renvoyée telle quelle (nom modifié) : acceptée, rien n'est perdu.
        $this->admin->request('PUT', '/api/admin/marketing/' . $id, ['json' => ['campaign_name' => 'Test Arthrex (archive)'] + $values]);
        $this->assertResponseStatusCodeSame(200);
        $campaign = $this->freshEm()->find(Marketing::class, $id);
        $this->assertSame([$pasted, 'https://cdn.exemple.be/portrait.jpg', null, $pasted, 'https://cdn.exemple.be/large.jpg'], array_values(\App\Service\MarketingImageStore::formatValues($campaign)));
        $this->assertSame([39, 3, 10, 'inactive', '2025-01-21', '2025-01-22', 'https://www.exemple.be'], [
            $campaign->getViews(), $campaign->getClicks(), $campaign->getDuration(), $campaign->getStatus(),
            $campaign->getStartDate()->format('Y-m-d'), $campaign->getEndDate()->format('Y-m-d'), $campaign->getRedirectUrl(),
        ]);

        // 2. Image principale remplacée par un import, image collée retirée : le reste est conservé.
        $main = $this->upload(self::image('jpeg', 1920, 1080), 'principale.jpg')['url'];
        $this->admin->request('PUT', '/api/admin/marketing/' . $id, ['json' => ['large_screen_format' => $main, 'smartphone_format' => null] + $values]);
        $this->assertResponseStatusCodeSame(200);
        $campaign = $this->freshEm()->find(Marketing::class, $id);
        $this->assertSame([null, 'https://cdn.exemple.be/portrait.jpg', null, $pasted, $main], array_values(\App\Service\MarketingImageStore::formatValues($campaign)));
        $this->assertSame([39, 3], [$campaign->getViews(), $campaign->getClicks()]);

        // 3. Une NOUVELLE image collée reste refusée.
        $this->admin->request('PUT', '/api/admin/marketing/' . $id, ['json' => ['tablet_landscape_format' => $pasted] + $values]);
        $this->assertResponseStatusCodeSame(400);
    }

    // ------------------------------------------------------------------ Outils

    private static function campaign(array $override = []): array
    {
        return array_merge([
            'campaign_name' => 'Campagne importée',
            'status' => 'active',
            'start_date' => (new \DateTime('-1 day'))->format('Y-m-d'),
            'end_date' => null,
            'duration' => 5,
            'redirect_url' => 'https://exemple.be',
        ], $override);
    }

    private function createCampaignWith(array $formats): int
    {
        $this->admin->request('POST', '/api/admin/marketing', ['json' => self::campaign($formats)]);
        $this->assertResponseStatusCodeSame(201);

        return (int) $this->freshEm()->createQuery('SELECT MAX(m.id) FROM App\Entity\Marketing m')->getSingleScalarResult();
    }

    private function upload(string $content, string $name): array
    {
        return $this->admin->request('POST', '/api/admin/marketing-images', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => self::uploadedFile($content, $name)]],
        ])->toArray(false);
    }

    private static function uploadedFile(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'visuel');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function storedImage(string $url): ?MarketingImage
    {
        return $this->freshEm()->getRepository(MarketingImage::class)->findOneBy(['sha256' => basename($url, '.webp')]);
    }

    private function imageCount(): int
    {
        return $this->freshEm()->getRepository(MarketingImage::class)->count([]);
    }

    private function freshEm(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }

    /** Image de test (dégradé et, en PNG, transparence) encodée dans le format demandé. */
    private static function image(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 47, 91, 234, $format === 'png' ? 60 : 0));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 3), imagecolorallocate($image, 255, 255, 255));
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image, null, 90),
            'webp' => imagewebp($image, null, 90),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }

    /** JPEG avec un segment EXIF (orientation, description éventuelle) inséré après SOI. */
    private static function withExif(string $jpeg, int $orientation, ?string $description = null): string
    {
        $entries = [pack('vvVvv', 0x0112, 3, 1, $orientation, 0)];
        $extra = '';
        if ($description !== null) {
            $text = $description . "\0";
            $entries[] = pack('vvVV', 0x010E, 2, strlen($text), 8 + 2 + 12 * 2 + 4);
            $extra = $text;
        }
        $tiff = 'II' . pack('vV', 42, 8) . pack('v', count($entries)) . implode('', $entries) . pack('V', 0) . $extra;
        $app1 = "Exif\0\0" . $tiff;

        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** PNG valide auquel est ajouté un chunk acTL (APNG) avant les données. */
    private static function animatedPng(): string
    {
        $png = self::image('png', 600, 600);
        $data = pack('NN', 2, 0);
        $chunk = pack('N', strlen($data)) . 'acTL' . $data . pack('N', crc32('acTL' . $data));

        return substr($png, 0, 33) . $chunk . substr($png, 33);
    }
}
