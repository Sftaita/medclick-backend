<?php

namespace App\EventListener;

use App\Entity\ErrorLog;
use App\Service\ErrorRecorder;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Capture les erreurs serveur rencontrées par les utilisateurs.
 *
 * L'exception est mémorisée quand elle survient, puis enregistrée APRÈS l'envoi de la
 * réponse (kernel.terminate) et seulement si le statut final est >= 500 : les erreurs
 * « normales » (validation 422, accès refusé 403, 404...) ne sont pas comptées, et
 * l'utilisateur n'attend pas l'écriture en base.
 */
final class ErrorCaptureSubscriber implements EventSubscriberInterface
{
    private const ATTRIBUTE = '_captured_exception';

    public function __construct(private ErrorRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité haute : mémoriser l'exception d'origine avant sa transformation.
            KernelEvents::EXCEPTION => ['rememberException', 255],
            KernelEvents::TERMINATE => ['recordServerError', -255],
            ConsoleEvents::ERROR => ['recordConsoleError', -255],
        ];
    }

    public function rememberException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::ATTRIBUTE, $event->getThrowable());
        }
    }

    public function recordServerError(TerminateEvent $event): void
    {
        $status = $event->getResponse()->getStatusCode();
        if ($status < 500) {
            return;
        }

        $request = $event->getRequest();
        $exception = $request->attributes->get(self::ATTRIBUTE);

        if ($exception instanceof \Throwable) {
            $this->recorder->recordException($exception, $request, ErrorLog::SOURCE_API, $status);
        } else {
            $this->recorder->recordServerErrorResponse($request, $status);
        }
    }

    public function recordConsoleError(ConsoleErrorEvent $event): void
    {
        $this->recorder->recordConsoleError($event->getError(), $event->getCommand()?->getName() ?? 'inconnue');
    }
}
