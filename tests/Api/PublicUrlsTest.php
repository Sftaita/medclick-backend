<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\ApiTestBase;
use Symfony\Component\Mime\Email;

/**
 * Adresses publiques des e-mails et de la redirection d'activation : valeurs de production par
 * défaut (aucune régression), valeurs de l'environnement quand PUBLIC_FRONTEND_URL / PUBLIC_API_URL
 * sont définies (staging) — et alors aucun lien vers la production.
 */
class PublicUrlsTest extends ApiTestBase
{
    private const STAGING = 'https://staging.medclick.be';

    protected function tearDown(): void
    {
        foreach (['PUBLIC_FRONTEND_URL', 'PUBLIC_API_URL'] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Production (défaut)

    public function testProductionDefaultsForActivationEmailAndRedirect(): void
    {
        [$token, $html] = $this->register();

        $this->assertStringContainsString('href="https://api-medclick.easymed.fun/activation/' . $token . '"', $html);
        static::createClient()->request('GET', '/activation/' . $token);
        $this->assertResponseRedirects('https://www.medclick.be/#/login');
    }

    public function testProductionDefaultForResetEmail(): void
    {
        [$token, $html] = $this->forgottenPassword();

        $this->assertStringContainsString('https://www.medclick.be/#/resetPassword/' . $token, $html);
    }

    // ---------------------------------------------------------------- Staging

    public function testStagingUrlsForActivationEmailAndRedirect(): void
    {
        $this->useStaging();
        [$token, $html] = $this->register();

        $this->assertStringContainsString('href="' . self::STAGING . '/activation/' . $token . '"', $html);
        $this->assertNoProductionLink($html);
        static::createClient()->request('GET', '/activation/' . $token);
        $this->assertResponseRedirects(self::STAGING . '/#/login');

        // Lien déjà utilisé : même redirection vers le staging.
        static::createClient()->request('GET', '/activation/' . $token);
        $this->assertResponseRedirects(self::STAGING . '/#/login');
    }

    public function testStagingUrlForResetEmail(): void
    {
        $this->useStaging();
        [$token, $html] = $this->forgottenPassword();

        $this->assertStringContainsString(self::STAGING . '/#/resetPassword/' . $token, $html);
        $this->assertNoProductionLink($html);
    }

    // ---------------------------------------------------------------- Helpers

    private function useStaging(): void
    {
        foreach (['PUBLIC_FRONTEND_URL' => self::STAGING, 'PUBLIC_API_URL' => self::STAGING] as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("$name=$value");
        }
        // Nouveau noyau : la configuration est relue avec ces variables.
        self::ensureKernelShutdown();
        self::bootKernel();
    }

    private function assertNoProductionLink(string $html): void
    {
        $this->assertStringNotContainsString('www.medclick.be/#', $html);
        $this->assertStringNotContainsString('easymed.fun', $html);
    }

    /** Inscription : [token d'activation, HTML de l'e-mail]. */
    private function register(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->request('POST', '/api/users', ['json' => [
            'email' => 'nouveau@test.be',
            'password' => 'motdepasse',
            'firstname' => 'Nou',
            'lastname' => 'Veau',
        ]]);
        $this->assertResponseStatusCodeSame(201);

        $email = self::getMailerMessage();
        $this->assertInstanceOf(Email::class, $email);
        $token = $this->user('nouveau@test.be')->getToken();

        return [$token, $email->getHtmlBody()];
    }

    /** Mot de passe oublié : [token de réinitialisation, HTML de l'e-mail]. */
    private function forgottenPassword(): array
    {
        $this->createUser('alice@test.be');
        $client = static::createClient();
        $client->disableReboot();
        $client->request('POST', '/api/forgottenPassword', ['json' => ['username' => 'alice@test.be']]);
        $this->assertResponseIsSuccessful();

        $email = self::getMailerMessage();
        $this->assertInstanceOf(Email::class, $email);

        return [$this->user('alice@test.be')->getResetToken(), $email->getHtmlBody()];
    }

    private function user(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
