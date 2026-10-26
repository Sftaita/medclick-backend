<?php

namespace App\Tests\Export\Support;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Collecte les e-mails réellement envoyés pendant un test (environnement test uniquement). Liste
 * statique : elle survit à la réinitialisation des services entre deux requêtes du client de test.
 */
final class SentEmailCollector implements EventSubscriberInterface
{
    /** @var list<Email> */
    public static array $emails = [];

    public static function getSubscribedEvents(): array
    {
        return [SentMessageEvent::class => 'onSent'];
    }

    public function onSent(SentMessageEvent $event): void
    {
        $message = $event->getMessage()->getOriginalMessage();
        if ($message instanceof Email) {
            self::$emails[] = $message;
        }
    }
}
