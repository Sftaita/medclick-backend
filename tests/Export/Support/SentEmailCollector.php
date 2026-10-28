<?php

namespace App\Tests\Export\Support;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * Collecte les e-mails réellement envoyés pendant un test (environnement test uniquement). Liste
 * statique : elle survit à la réinitialisation des services entre deux requêtes du client de test.
 * $fail = true : chaque envoi échoue (serveur SMTP indisponible simulé).
 */
final class SentEmailCollector implements EventSubscriberInterface
{
    /** @var list<Email> */
    public static array $emails = [];

    public static bool $fail = false;

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => 'onMessage', SentMessageEvent::class => 'onSent'];
    }

    public function onMessage(MessageEvent $event): void
    {
        if (self::$fail) {
            throw new TransportException('Échec d\'envoi simulé');
        }
    }

    public function onSent(SentMessageEvent $event): void
    {
        $message = $event->getMessage()->getOriginalMessage();
        if ($message instanceof Email) {
            self::$emails[] = $message;
        }
    }
}
