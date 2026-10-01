<?php

namespace App\Service;

use App\Entity\ErrorLog;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enregistre les erreurs rencontrées par les utilisateurs dans la table error_log.
 *
 * - Regroupement par empreinte : une erreur déjà connue incrémente son compteur (et est
 *   rouverte si elle avait été marquée résolue).
 * - Données minimales (RGPD) : id utilisateur, chemin sans query string avec les tokens
 *   masqués, user-agent. Jamais de corps de requête, de mot de passe ni d'adresse IP.
 * - Ne lève jamais d'exception : en cas d'échec (base indisponible...), l'erreur est
 *   écrite dans les logs fichiers (Monolog).
 * - Alerte email optionnelle (ERROR_ALERT_EMAIL) à la première occurrence ou à la réouverture.
 */
final class ErrorRecorder
{
    private const MAX_MESSAGE = 2000;
    private const MAX_TRACE = 15000;

    public function __construct(
        private ManagerRegistry $doctrine,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
        private MailerInterface $mailer,
        #[Autowire('%env(ERROR_ALERT_EMAIL)%')]
        private string $alertEmail = '',
    ) {
    }

    public function recordException(\Throwable $exception, ?Request $request, string $source = ErrorLog::SOURCE_API, ?int $statusCode = 500): void
    {
        $this->store([
            'fingerprint' => sha1(implode('|', [$source, $exception::class, $exception->getFile(), $exception->getLine()])),
            'source' => $source,
            'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            'exception_class' => $exception::class,
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $this->relativePath($exception->getTraceAsString()),
            'status_code' => $statusCode,
            'context' => $exception->getPrevious() ? [
                'previous' => $exception->getPrevious()::class . ': ' . mb_substr($exception->getPrevious()->getMessage(), 0, 500),
            ] : null,
        ] + $this->requestData($request));
    }

    public function recordConsoleError(\Throwable $exception, string $commandName): void
    {
        $this->store([
            'fingerprint' => sha1(implode('|', [ErrorLog::SOURCE_CONSOLE, $exception::class, $exception->getFile(), $exception->getLine()])),
            'source' => ErrorLog::SOURCE_CONSOLE,
            'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            'exception_class' => $exception::class,
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $this->relativePath($exception->getTraceAsString()),
            'path' => 'bin/console ' . $commandName,
        ]);
    }

    /** Réponse >= 500 sans exception (contrôleur qui renvoie lui-même une erreur). */
    public function recordServerErrorResponse(Request $request, int $statusCode): void
    {
        $path = $this->sanitizePath($request->getPathInfo());

        $this->store([
            'fingerprint' => sha1(implode('|', [ErrorLog::SOURCE_API, 'response', $request->getMethod(), $path, $statusCode])),
            'source' => ErrorLog::SOURCE_API,
            'message' => sprintf('Réponse HTTP %d sans exception', $statusCode),
            'status_code' => $statusCode,
        ] + $this->requestData($request));
    }

    /**
     * Erreur remontée par un front (PWA ou admin).
     *
     * @param array{source: string, message: string, stack?: ?string, url?: ?string, context?: ?array} $error
     */
    public function recordClientError(array $error, Request $request): void
    {
        $message = $error['message'];
        // Regroupement : chiffres et identifiants variables neutralisés dans la signature.
        $signature = preg_replace('/\d+/', '#', mb_substr($message, 0, 300));
        $page = null;
        if (isset($error['url'])) {
            // Les fronts utilisent un routeur à hash (#/login) : la page est dans le fragment.
            $fragment = parse_url($error['url'], PHP_URL_FRAGMENT);
            $page = $this->sanitizePath((string) parse_url($error['url'], PHP_URL_PATH) . ($fragment ? '#' . $fragment : ''));
        }

        $this->store([
            'fingerprint' => sha1(implode('|', [$error['source'], $signature, $this->firstStackLine($error['stack'] ?? null)])),
            'source' => $error['source'],
            'message' => $message,
            'trace' => $error['stack'] ?? null,
            'context' => $error['context'] ?? null,
        ] + $this->requestData($request), $page);
    }

    private function store(array $row, ?string $pathOverride = null): void
    {
        try {
            if ($pathOverride !== null) {
                $row['path'] = $pathOverride;
            }
            $row['message'] = mb_substr($row['message'], 0, self::MAX_MESSAGE);
            if (isset($row['trace'])) {
                $row['trace'] = mb_substr($row['trace'], 0, self::MAX_TRACE);
            }

            $connection = $this->doctrine->getConnection();
            $now = new \DateTimeImmutable();

            $existing = $connection->fetchAssociative(
                'SELECT id, resolved_at FROM error_log WHERE fingerprint = ?',
                [$row['fingerprint']]
            );

            if ($existing) {
                $connection->executeStatement(
                    'UPDATE error_log SET occurrences = occurrences + 1, last_seen_at = ?, resolved_at = NULL,
                        message = ?, path = ?, method = ?, status_code = ?, user_id = ?, user_agent = ? WHERE id = ?',
                    [$now, $row['message'], $row['path'] ?? null, $row['method'] ?? null, $row['status_code'] ?? null,
                        $row['user_id'] ?? null, $row['user_agent'] ?? null, $existing['id']],
                    [Types::DATETIME_IMMUTABLE]
                );

                if ($existing['resolved_at'] !== null) {
                    $this->alert($row, 'Erreur réapparue');
                }

                return;
            }

            $connection->insert('error_log', [
                'fingerprint' => $row['fingerprint'],
                'source' => $row['source'],
                'message' => $row['message'],
                'exception_class' => $row['exception_class'] ?? null,
                'file' => $row['file'] ?? null,
                'line' => $row['line'] ?? null,
                'trace' => $row['trace'] ?? null,
                'method' => $row['method'] ?? null,
                'path' => $row['path'] ?? null,
                'status_code' => $row['status_code'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'user_agent' => $row['user_agent'] ?? null,
                'context' => $row['context'] ?? null,
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ], [
                'context' => Types::JSON,
                'first_seen_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
            ]);

            $this->alert($row, 'Nouvelle erreur');
        } catch (\Throwable $failure) {
            // Dernier recours : ne jamais faire échouer la requête de l'utilisateur.
            $this->logger->critical('ErrorRecorder : impossible d\'enregistrer l\'erreur en base.', [
                'error' => $row['message'] ?? null,
                'path' => $row['path'] ?? null,
                'failure' => $failure->getMessage(),
            ]);
        }
    }

    private function alert(array $row, string $title): void
    {
        if ($this->alertEmail === '') {
            return;
        }

        try {
            $this->mailer->send((new Email())
                ->from('Medclick <service@medclick.be>')
                ->to($this->alertEmail)
                ->subject(sprintf('[MedClick] %s (%s)', $title, $row['source']))
                ->text(sprintf(
                    "%s\n\nSource : %s\nRequête : %s %s\nStatut : %s\nEmplacement : %s\n\nDétail : /api/admin/errors (tableau d'administration)",
                    $row['message'],
                    $row['source'],
                    $row['method'] ?? '-',
                    $row['path'] ?? '-',
                    $row['status_code'] ?? '-',
                    isset($row['file']) ? $row['file'] . ':' . $row['line'] : '-'
                )));
        } catch (\Throwable $failure) {
            $this->logger->warning('ErrorRecorder : alerte email non envoyée.', ['failure' => $failure->getMessage()]);
        }
    }

    private function requestData(?Request $request): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return [
            'method' => $request?->getMethod(),
            'path' => $request ? $this->sanitizePath($request->getPathInfo()) : null,
            'user_id' => $user instanceof User ? $user->getId() : null,
            'user_agent' => $request ? mb_substr((string) $request->headers->get('User-Agent'), 0, 255) : null,
        ];
    }

    /** Masque les tokens (activation, reset...) présents dans les chemins. */
    private function sanitizePath(string $path): string
    {
        return mb_substr(preg_replace('#/[A-Za-z0-9_-]{20,}#', '/{token}', $path), 0, 500);
    }

    private function relativePath(string $text): string
    {
        return str_replace([\dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, \dirname(__DIR__, 2) . '/'], '', $text);
    }

    private function firstStackLine(?string $stack): string
    {
        foreach (preg_split('/\R/', (string) $stack) as $line) {
            $line = trim($line);
            if ($line !== '' && str_contains($line, 'at ')) {
                // Sans numéros de colonne/ligne de bundle, qui changent à chaque build.
                return preg_replace('/:\d+(:\d+)?\)?$/', '', $line);
            }
        }

        return '';
    }
}
