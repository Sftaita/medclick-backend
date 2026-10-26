<?php

namespace App\Tests\Export;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Entity\User;
use App\Export\Job\GenerateExportHandler;
use App\Export\Job\Message\GenerateExport;
use App\Export\Monitoring\ExportMonitor;
use App\Tests\Excel\ExcelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Base des tests des exports asynchrones : file Messenger en mémoire traitée à la demande
 * (processQueue), horloge simulée (mockTime), dossier des fichiers propre à chaque test.
 */
abstract class ExportTestCase extends ExcelTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-27 09:00:00');
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir') . '/var/exports-test');
    }

    /**
     * Client authentifié, sans redémarrage du noyau entre les requêtes : la file en mémoire et
     * le gestionnaire d'entités restent ceux du test.
     */
    protected function clientFor(User $user): Client
    {
        $client = parent::clientFor($user);
        $client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        return $client;
    }

    protected function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.exports');
    }

    /** Traite les demandes en attente, comme le worker. Renvoie le nombre de messages traités. */
    protected function processQueue(): int
    {
        $handler = static::getContainer()->get(GenerateExportHandler::class);
        $count = 0;
        while ($envelopes = iterator_to_array($this->transport()->get())) {
            foreach ($envelopes as $envelope) {
                $this->transport()->ack($envelope);
                $handler($envelope->getMessage());
                ++$count;
            }
        }

        return $count;
    }

    protected function handle(ExportJob $job): void
    {
        static::getContainer()->get(GenerateExportHandler::class)(new GenerateExport($job->getId()));
    }

    protected function monitor(): array
    {
        return static::getContainer()->get(ExportMonitor::class)->run();
    }

    protected function job(string $publicId): ExportJob
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->clear();

        return $this->em->getRepository(ExportJob::class)->findOneBy(['publicId' => $publicId]);
    }

    /** @return list<string> types d'évènements de l'export, dans l'ordre */
    protected function eventTypes(string $publicId): array
    {
        return array_map(fn (ExportJobEvent $e) => $e->getType(),
            $this->em->getRepository(ExportJobEvent::class)->findBy(['job' => $this->job($publicId)], ['id' => 'ASC']));
    }

    protected function requestExport(Client $client, int $yearId): array
    {
        $response = $client->request('POST', '/api/exports', ['json' => ['yearId' => $yearId]]);
        self::assertContains($response->getStatusCode(), [200, 202], $response->getContent(false));

        return $response->toArray();
    }

    /**
     * Contenu brut d'une réponse (StreamedResponse comprise).
     */
    protected function body(Client $client, string $url): array
    {
        ob_start();
        $response = $client->request('GET', $url);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $echoed = ob_get_clean();

        return ['status' => $status, 'content' => $content !== '' ? $content : $echoed, 'headers' => $response->getHeaders(false)];
    }

    /** @return list<Email> e-mails envoyés depuis le début du test */
    protected function sentEmails(): array
    {
        $logger = static::getContainer()->get('mailer.message_logger_listener');

        return array_values(array_filter(array_map(fn (MessageEvent $e) => $e->getMessage(),
            array_filter($logger->getEvents()->getEvents(), fn (MessageEvent $e) => !$e->isQueued())), fn ($m) => $m instanceof Email));
    }

    /** Avance l'horloge simulée de $seconds. */
    protected function travel(int $seconds): void
    {
        self::mockTime(\DateTimeImmutable::createFromInterface(\Symfony\Component\Clock\Clock::get()->now())->modify("+$seconds seconds"));
    }
}
