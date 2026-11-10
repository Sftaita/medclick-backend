<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EmailUnsubscribe;
use App\Repository\EmailCampaignSendRepository;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Désinscription des campagnes e-mail (public, sans compte), par le jeton propre à chaque envoi :
 * le lien ne contient pas l'adresse.
 *
 * - GET : page de confirmation (un simple affichage ne désinscrit pas : les antivirus des
 *   messageries ouvrent les liens pour les analyser) ;
 * - POST : enregistre la désinscription. Sert aussi à la désinscription « en un clic » des
 *   messageries (en-tête List-Unsubscribe-Post, RFC 8058), qui envoie
 *   « List-Unsubscribe=One-Click ».
 *
 * Limité en débit par adresse IP (App\Security\PublicEndpointRateLimiter).
 */
final class EmailUnsubscribeController extends AbstractController
{
    #[Route('/email/desinscription/{token}', name: 'email_unsubscribe_form', methods: ['GET'], requirements: ['token' => '[a-f0-9]{48}'])]
    public function form(string $token, EmailCampaignSendRepository $sends, EmailUnsubscribeRepository $unsubscribes): Response
    {
        $send = $sends->findOneBy(['unsubscribeToken' => $token]);
        if ($send === null) {
            return $this->page('invalid', $token, Response::HTTP_NOT_FOUND);
        }

        return $this->page($unsubscribes->isUnsubscribed($send->getEmail()) ? 'done' : 'confirm', $token);
    }

    #[Route('/email/desinscription/{token}', name: 'email_unsubscribe_confirm', methods: ['POST'], requirements: ['token' => '[a-f0-9]{48}'])]
    public function confirm(string $token, Request $request, EmailCampaignSendRepository $sends, EmailUnsubscribeRepository $unsubscribes, EntityManagerInterface $em): Response
    {
        $send = $sends->findOneBy(['unsubscribeToken' => $token]);
        if ($send === null) {
            return $this->page('invalid', $token, Response::HTTP_NOT_FOUND);
        }

        if (!$unsubscribes->isUnsubscribed($send->getEmail())) {
            $oneClick = $request->request->get('List-Unsubscribe') === 'One-Click';
            $em->persist(new EmailUnsubscribe($send->getEmail(), $oneClick ? EmailUnsubscribe::SOURCE_ONE_CLICK : EmailUnsubscribe::SOURCE_LINK, $send->getCampaign()));
            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Double clic : déjà enregistrée par la requête précédente.
            }
        }

        return $this->page('done', $token);
    }

    private function page(string $state, string $token, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('email/desinscription.html.twig', ['state' => $state, 'token' => $token], new Response('', $status));
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
