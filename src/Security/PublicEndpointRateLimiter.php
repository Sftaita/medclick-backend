<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limite, par adresse IP, les routes publiques exposées aux abus (création de comptes en masse,
 * envoi d'emails de réinitialisation). Répond 429 lorsque la limite est atteinte.
 *
 * La connexion (/api/login_check) est protégée par "login_throttling" dans security.yaml.
 */
class PublicEndpointRateLimiter implements EventSubscriberInterface
{
    /**
     * @var array<string, RateLimiterFactoryInterface> nom de route => limiteur
     */
    private $limiters;

    public function __construct(
        #[Target('registration.limiter')] RateLimiterFactoryInterface $registrationLimiter,
        #[Target('password_reset.limiter')] RateLimiterFactoryInterface $passwordResetLimiter,
        #[Target('client_errors.limiter')] RateLimiterFactoryInterface $clientErrorsLimiter,
        #[Target('marketing_click.limiter')] RateLimiterFactoryInterface $marketingClickLimiter,
    )
    {
        $this->limiters = [
            '_api_/users{._format}_post' => $registrationLimiter,
            'forgotten_password' => $passwordResetLimiter,
            'reset_password' => $passwordResetLimiter,
            'client_errors' => $clientErrorsLimiter,
            'increment_campaign_click' => $marketingClickLimiter,
        ];
    }

    public static function getSubscribedEvents(): array
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
