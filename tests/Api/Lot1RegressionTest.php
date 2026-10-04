<?php

namespace App\Tests\Api;

use App\Entity\Favorites;
use App\Entity\Marketing;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\Mime\Email;

/**
 * LOT 1 — fiabilisation fonctionnelle : un test par bug constaté à l'audit, plus les
 * comportements dont dépendent les fronts (distinction des 401, autorisation admin).
 */
class Lot1RegressionTest extends ApiTestBase
{
    // ---------------------------------------------------------------- Reset password

    public function testResetEmailLinkPointsToTheHashRouteOfThePwa(): void
    {
        $this->createUser('alice@test.be');
        $client = static::createClient();
        $client->disableReboot();

        $client->request('POST', '/api/forgottenPassword', ['json' => ['username' => 'alice@test.be']]);
        $this->assertResponseIsSuccessful();

        $token = $this->freshEm()->getRepository(User::class)->findOneBy(['email' => 'alice@test.be'])->getResetToken();
        $email = self::getMailerMessage();
        $this->assertInstanceOf(Email::class, $email);
        // La PWA utilise un routeur à hash : sans "#/", le lien mène à une 404.
        $this->assertStringContainsString('https://www.medclick.be/#/resetPassword/' . $token, $email->getHtmlBody());
    }

    public function testResetPasswordThenLoginWithTheNewPassword(): void
    {
        $user = $this->createUser('alice@test.be')
            ->setResetToken('bon-token')
            ->setResetTokenRequestedAt(new \DateTime('-5 minutes'));
        $this->em->flush();

        static::createClient()->request('POST', '/api/resetPassword', ['json' => [
            'email' => 'alice@test.be', 'token' => 'bon-token', 'password' => 'nouveaumdp',
        ]]);
        $this->assertResponseIsSuccessful();

        static::createClient()->request('POST', '/api/login_check', ['json' => ['username' => 'alice@test.be', 'password' => 'nouveaumdp']]);
        $this->assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------- Favoris

    public function testFavoriteCanBeLoadedForEditingWithTheRouteUsedByThePwa(): void
    {
        [$alice, $favorite, $nomenclature] = $this->createFavorite();

        $data = $this->clientFor($alice)->request('GET', '/api/favorites/getById/' . $favorite->getId())->toArray();

        $this->assertResponseIsSuccessful();
        // Champs lus par FavoritePage : { shortcut, SurgeryName, speciality, surgeryId }.
        $this->assertSame('PTH', $data['shortcut']);
        $this->assertSame('Prothèse totale de hanche', $data['SurgeryName']);
        $this->assertSame('ortho', $data['speciality']);
        $this->assertSame($nomenclature->getId(), $data['surgeryId']);
        $this->assertSame($favorite->getId(), $data['id']);
    }

    public function testFavoriteOfAnotherUserIsNotReturned(): void
    {
        [, $favorite] = $this->createFavorite();
        $bob = $this->createUser('bob@test.be');

        $this->clientFor($bob)->request('GET', '/api/favorites/getById/' . $favorite->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testFavoriteEditingRequiresAuthentication(): void
    {
        [, $favorite] = $this->createFavorite();

        static::createClient()->request('GET', '/api/favorites/getById/' . $favorite->getId());

        $this->assertResponseStatusCodeSame(401);
    }

    // ---------------------------------------------------------------- Marketing

    public function testCampaignClickIsCounted(): void
    {
        $campaign = $this->createCampaign();

        // Appel fait par la PWA au clic sur la publicité (page de connexion : pas de JWT).
        static::createClient()->request('PUT', '/api/marketing/incrementCampaign/' . $campaign->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->freshEm()->find(Marketing::class, $campaign->getId())->getClicks());
    }

    public function testCampaignClicksAreRateLimited(): void
    {
        $campaign = $this->createCampaign();
        $client = static::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 20; $i++) {
            $client->request('PUT', '/api/marketing/incrementCampaign/' . $campaign->getId());
        }
        $this->assertResponseIsSuccessful();

        $client->request('PUT', '/api/marketing/incrementCampaign/' . $campaign->getId());
        $this->assertResponseStatusCodeSame(429);
        $this->assertSame(20, $this->freshEm()->find(Marketing::class, $campaign->getId())->getClicks());
    }

    public function testClickOnUnknownCampaignReturns404(): void
    {
        static::createClient()->request('PUT', '/api/marketing/incrementCampaign/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------- Intervention sans position

    public function testAddingSurgeryWithoutPositionIsRejectedWithoutServerError(): void
    {
        $alice = $this->createUser('alice@test.be');
        $year = $this->createYear($alice);
        $nomenclature = $this->createNomenclature();

        $response = $this->clientFor($alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'year' => $year->getId(),
            'surgeryId' => $nomenclature->getId(),
            'date' => '2025-11-02',
            'position' => null,
        ]]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertNotEmpty($response->toArray(false)['message']);
        $this->assertSame(0, $this->freshEm()->getRepository(Surgeries::class)->count([]));
    }

    public function testAddingSurgeryWithInvalidDateIsRejectedWithoutServerError(): void
    {
        $alice = $this->createUser('alice@test.be');
        $year = $this->createYear($alice);

        $this->clientFor($alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'year' => $year->getId(),
            'surgeryId' => $this->createNomenclature()->getId(),
            'date' => 'pas-une-date',
            'position' => 1,
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAddingSurgeryWithValidPositionStillWorks(): void
    {
        $alice = $this->createUser('alice@test.be');
        $year = $this->createYear($alice);

        foreach ([1, '2', 3] as $position) {
            $this->clientFor($alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
                'year' => $year->getId(),
                'surgeryId' => $this->createNomenclature()->getId(),
                'date' => '2025-11-02',
                'position' => $position,
                'firstHand' => '7',
                'secondHand' => '8',
            ]]);
            $this->assertResponseIsSuccessful();
        }

        $this->assertSame(3, $this->freshEm()->getRepository(Surgeries::class)->count([]));
    }

    // ---------------------------------------------------------------- Création d'année

    public function testYearCreation(): void
    {
        $alice = $this->createUser('alice@test.be');

        $response = $this->clientFor($alice)->request('POST', '/api/years/create', ['json' => $this->yearPayload()]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNotEmpty($response->toArray()['message']);
    }

    public function testDuplicateYearReturnsAMessageTheFrontCanDisplay(): void
    {
        $alice = $this->createUser('alice@test.be');
        $client = $this->clientFor($alice);
        $client->request('POST', '/api/years/create', ['json' => $this->yearPayload()]);

        $response = $client->request('POST', '/api/years/create', ['json' => $this->yearPayload()]);

        $this->assertResponseStatusCodeSame(409);
        $data = $response->toArray(false);
        // YearPage affiche error.response.data.message.
        $this->assertSame('Cette année est déjà enregistrée pour cet utilisateur', $data['message']);
        $this->assertSame($data['message'], $data['error'], 'La clé historique "error" est conservée.');
    }

    public function testInvalidYearReturnsAMessageTheFrontCanDisplay(): void
    {
        $alice = $this->createUser('alice@test.be');

        $response = $this->clientFor($alice)->request('POST', '/api/years/create', ['json' => ['yearOfFormation' => '1']]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertNotEmpty($response->toArray(false)['message']);
    }

    // ---------------------------------------------------------------- 401 : cas distingués par les fronts

    public function testUnauthorizedResponsesAreDistinguishable(): void
    {
        $this->createUser('alice@test.be');
        $inactive = $this->createUser('inactif@test.be')->setToken(str_repeat('a', 32));
        $this->em->flush();
        $client = static::createClient();

        // Mauvais mot de passe / compte non activé : 401 sur /api/login_check uniquement.
        $client->request('POST', '/api/login_check', ['json' => ['username' => 'alice@test.be', 'password' => 'mauvais']]);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Invalid credentials.', $client->getResponse()->toArray(false)['message']);

        // Compte non activé : Symfony (hide_user_not_found) masque volontairement le statut du compte
        // pour ne pas révéler son existence ; le front affiche un message couvrant les deux cas.
        $client->request('POST', '/api/login_check', ['json' => ['username' => $inactive->getEmail(), 'password' => self::PASSWORD]]);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Invalid credentials.', $client->getResponse()->toArray(false)['message']);

        // Jeton expiré / invalide / absent sur une route protégée : session à terminer côté front.
        $expired = static::getContainer()->get(JWTEncoderInterface::class)->encode(['username' => 'alice@test.be', 'exp' => time() - 60]);
        $client->request('GET', '/api/years', ['headers' => ['Authorization' => 'Bearer ' . $expired]]);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Expired JWT Token', $client->getResponse()->toArray(false)['message']);

        $client->request('GET', '/api/years', ['headers' => ['Authorization' => 'Bearer pas.un.jeton']]);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Invalid JWT Token', $client->getResponse()->toArray(false)['message']);

        $client->request('GET', '/api/years');
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('JWT Token not found', $client->getResponse()->toArray(false)['message']);
    }

    // ---------------------------------------------------------------- Autorisation admin (backend = source de vérité)

    public function testAdminEndpointsRefuseNonAdminUsers(): void
    {
        $client = $this->clientFor($this->createUser('alice@test.be'));

        foreach (['/api/admin/users', '/api/admin/history/quick', '/api/admin/marketing', '/api/admin/nomenclature/ortho', '/api/admin/fetchUserById/1', '/api/admin/errors'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAdminEndpointsAcceptAdmins(): void
    {
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $client = $this->clientFor($admin);

        foreach (['/api/admin/users', '/api/admin/history/quick', '/api/admin/marketing', '/api/admin/nomenclature/ortho', '/api/admin/fetchUserById/' . $admin->getId()] as $url) {
            $client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array{0: User, 1: Favorites, 2: \App\Entity\Nomenclature} */
    private function createFavorite(): array
    {
        $alice = $this->createUser('alice@test.be');
        $nomenclature = $this->createNomenclature();
        $favorite = (new Favorites())
            ->setUser($alice)
            ->setSurgery($nomenclature)
            ->setShortcut('PTH')
            ->setSurgeryName('Prothèse totale de hanche')
            ->setCodeHospitalisation('2890851')
            ->setSpeciality('ortho');
        $this->em->persist($favorite);
        $this->em->flush();

        return [$alice, $favorite, $nomenclature];
    }

    private function createCampaign(): Marketing
    {
        $campaign = (new Marketing())
            ->setCampaignName('Campagne de test')
            ->setStatus('active')
            ->setStartDate(new \DateTime('-1 day'))
            ->setSmartphoneFormat('a.png')
            ->setTabletPortraitFormat('b.png')
            ->setTabletLandscapeFormat('c.png')
            ->setScreen14InchFormat('d.png')
            ->setLargeScreenFormat('e.png');
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function yearPayload(): array
    {
        return ['yearOfFormation' => '2', 'hospital' => 'CHU Liège', 'master' => 'Dr Maître', 'dateOfStart' => '2026-10-01'];
    }

    private function freshEm()
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
