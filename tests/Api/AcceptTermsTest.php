<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\ApiTestBase;

/**
 * Acceptation des conditions générales (fenêtre affichée à la première connexion) : l'enregistrement
 * de la date échouait en 500 depuis DBAL 4 (DateTimeImmutable dans une colonne « datetime »).
 */
class AcceptTermsTest extends ApiTestBase
{
    public function testAcceptTermsIsRecordedAndReturnedInTheNextToken(): void
    {
        $alice = $this->createUser('alice@test.be');
        $client = $this->clientFor($alice);

        $response = $client->request('PUT', '/api/acceptTerms');
        $this->assertResponseIsSuccessful();
        $data = $response->toArray();
        $this->assertSame($alice->getId(), $data['userId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['termsAcceptedDate']);

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $user = $em->find(User::class, $alice->getId());
        $this->assertTrue($user->getAcceptedTerms());
        $this->assertNotNull($user->getTermsAcceptedDate());

        // La connexion suivante ne réaffiche plus la fenêtre : claim acceptedTerms du JWT.
        $token = static::createClient()->request('POST', '/api/login_check', ['json' => [
            'username' => 'alice@test.be',
            'password' => self::PASSWORD,
        ]])->toArray()['token'];
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertTrue($claims['acceptedTerms']);
    }

    public function testAcceptTermsRequiresAuthentication(): void
    {
        static::createClient()->request('PUT', '/api/acceptTerms');
        $this->assertResponseStatusCodeSame(401);
    }
}
