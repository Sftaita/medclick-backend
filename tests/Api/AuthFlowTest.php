<?php

namespace App\Tests\Api;

use App\Entity\ConnectionHistory;
use App\Entity\User;
use App\Tests\ApiTestBase;

/**
 * Inscription/activation, mot de passe oublié et historique de connexion
 * (audit P0, points 4 et 5).
 */
class AuthFlowTest extends ApiTestBase
{
    // ---------------------------------------------------------------- Mot de passe oublié

    public function testForgottenPasswordAnswersTheSameForUnknownEmail(): void
    {
        $this->createUser('alice@test.be');

        $known = static::createClient()->request('POST', '/api/forgottenPassword', ['json' => ['username' => 'alice@test.be']]);
        $this->assertResponseIsSuccessful();

        $unknown = static::createClient()->request('POST', '/api/forgottenPassword', ['json' => ['username' => 'inconnu@test.be']]);
        $this->assertResponseIsSuccessful();

        $this->assertSame($known->toArray(), $unknown->toArray());

        $user = $this->reloadUser('alice@test.be');
        $this->assertNotNull($user->getResetToken());
        $this->assertNotNull($user->getResetTokenRequestedAt());
    }

    public function testForgottenPasswordRequiresEmail(): void
    {
        static::createClient()->request('POST', '/api/forgottenPassword', ['json' => []]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordWithValidToken(): void
    {
        $this->createUserWithResetToken('alice@test.be', 'bon-token', new \DateTime('-10 minutes'));

        static::createClient()->request('POST', '/api/resetPassword', ['json' => [
            'email' => 'alice@test.be',
            'token' => 'bon-token',
            'password' => 'nouveaumdp',
        ]]);

        $this->assertResponseIsSuccessful();
        $user = $this->reloadUser('alice@test.be');
        $this->assertTrue(password_verify('nouveaumdp', $user->getPassword()));
        $this->assertNull($user->getResetToken(), 'Le token doit être à usage unique.');
    }

    public function testResetPasswordWithWrongTokenIsRejectedWithoutCancellingTheRequest(): void
    {
        $this->createUserWithResetToken('alice@test.be', 'bon-token', new \DateTime('-10 minutes'));

        static::createClient()->request('POST', '/api/resetPassword', ['json' => [
            'email' => 'alice@test.be',
            'token' => 'mauvais-token',
            'password' => 'nouveaumdp',
        ]]);

        $this->assertResponseStatusCodeSame(400);
        $user = $this->reloadUser('alice@test.be');
        $this->assertTrue(password_verify(self::PASSWORD, $user->getPassword()));
        $this->assertSame('bon-token', $user->getResetToken());
    }

    public function testResetPasswordWithExpiredTokenIsRejected(): void
    {
        $this->createUserWithResetToken('alice@test.be', 'bon-token', new \DateTime('-2 hours'));

        static::createClient()->request('POST', '/api/resetPassword', ['json' => [
            'email' => 'alice@test.be',
            'token' => 'bon-token',
            'password' => 'nouveaumdp',
        ]]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertTrue(password_verify(self::PASSWORD, $this->reloadUser('alice@test.be')->getPassword()));
    }

    public function testResetPasswordRejectsTooShortPassword(): void
    {
        $this->createUserWithResetToken('alice@test.be', 'bon-token', new \DateTime('-10 minutes'));

        static::createClient()->request('POST', '/api/resetPassword', ['json' => [
            'email' => 'alice@test.be',
            'token' => 'bon-token',
            'password' => '123',
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordForUnknownEmailIsRejected(): void
    {
        static::createClient()->request('POST', '/api/resetPassword', ['json' => [
            'email' => 'inconnu@test.be',
            'token' => 'x',
            'password' => 'nouveaumdp',
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    // ---------------------------------------------------------------- Activation

    public function testRegistrationThenActivation(): void
    {
        static::createClient()->request('POST', '/api/users', ['json' => [
            'email' => 'nouveau@test.be',
            'password' => 'motdepasse',
            'firstname' => 'Nou',
            'lastname' => 'Veau',
        ]]);
        $this->assertResponseStatusCodeSame(201);

        $token = $this->reloadUser('nouveau@test.be')->getToken();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        // Compte non activé : connexion refusée.
        static::createClient()->request('POST', '/api/login_check', ['json' => [
            'username' => 'nouveau@test.be',
            'password' => 'motdepasse',
        ]]);
        $this->assertResponseStatusCodeSame(401);

        static::createClient()->request('GET', '/activation/' . $token);
        $this->assertResponseRedirects('https://www.medclick.be/#/login');

        $user = $this->reloadUser('nouveau@test.be');
        $this->assertNull($user->getToken());
        $this->assertNotNull($user->getValidatedAt());

        // Second clic sur le même lien : pas d'erreur, retour à la connexion.
        static::createClient()->request('GET', '/activation/' . $token);
        $this->assertResponseRedirects('https://www.medclick.be/#/login');
    }

    public function testActivationWithMalformedTokenReturns404(): void
    {
        static::createClient()->request('GET', '/activation/trop-court');

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------- Historique de connexion

    public function testOnlySuccessfulLoginsAreRecorded(): void
    {
        $this->createUser('alice@test.be');

        static::createClient()->request('POST', '/api/login_check', ['json' => [
            'username' => 'alice@test.be',
            'password' => 'mauvais',
        ]]);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(0, $this->freshEm()->getRepository(ConnectionHistory::class)->count([]));

        $this->clientFor($this->reloadUser('alice@test.be'));
        $this->assertSame(1, $this->freshEm()->getRepository(ConnectionHistory::class)->count([]));
    }

    // ---------------------------------------------------------------- Helpers

    private function createUserWithResetToken(string $email, string $token, \DateTime $requestedAt): User
    {
        $user = $this->createUser($email)
            ->setResetToken($token)
            ->setResetTokenRequestedAt($requestedAt);
        $this->em->flush();

        return $user;
    }

    private function reloadUser(string $email): User
    {
        return $this->freshEm()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function freshEm()
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
