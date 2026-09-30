<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Limite, par adresse IP, les routes publiques exposées aux abus (création de comptes en masse,
 * envoi d'emails de réinitialisation). Répond 429 lorsque la limite est atteinte.
 *
 * La connexion (/api/login_check) est protégée par "login_throttling" dans security.yaml.
 */
class PublicEndpointRateLimiter implements EventSubscriberInterface
{
    /**
     * @var array<string, RateLimiterFactory> nom de route => limiteur
     */
    private $limiters;

    public function __construct(RateLimiterFactory $registrationLimiter, RateLimiterFactory $passwordResetLimiter)
    {
        $this->limiters = [
            'api_users_post_collection' => $registrationLimiter,
            'forgotten_password' => $passwordResetLimiter,
            'reset_password' => $passwordResetLimiter,
        ];
    }

    public static function getSubscribedEvents()
    {
        // Après le routeur (32), avant le firewall (8).
        return [
            KernelEvents::REQUEST => ['limit', 16],
        ];
    }

    public function limit(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $factory = $this->limiters[$request->attributes->get('_route')] ?? null;

        if (!$factory) {
            return;
        }

        $limit = $factory->create($request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            $event->setResponse(new JsonResponse(
                ['message' => 'Trop de tentatives. Réessayez plus tard.'],
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $retryAfter]
            ));
        }
    }
}
