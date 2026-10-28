<?php

namespace App\Export\Job;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Entity\User;
use App\Export\ExportFailure;
use App\Export\ExportStep;
use App\Export\Monitoring\ExportWorkerWatch;
use App\Repository\ExportJobRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Alertes e-mail de l'administrateur (EXPORT_ALERT_EMAIL, par défaut ERROR_ALERT_EMAIL).
 *
 * - Échec ou blocage d'un export : une alerte par incident (même type, catégorie, étape et code)
 *   et par période EXPORT_ALERT_DEDUP_MINUTES ; les suivants sont marqués « doublon de … ».
 * - Envoi en échec : conservé (statut, erreur, tentatives) et retenté par la surveillance
 *   (5 tentatives, délai croissant) ou à la demande depuis l'administration.
 * - Exports prêts jamais téléchargés : au plus un récapitulatif par 24 h, seulement si leur nombre
 *   atteint EXPORT_NOT_DOWNLOADED_ALERT_THRESHOLD (0 = jamais d'e-mail, évènement seul).
 *
 * Contenu : identité du demandeur (id, e-mail), type, dates, étape, durée, catégorie, identifiant
 * de diagnostic et lien vers l'administration (connexion administrateur requise). Jamais le
 * fichier, jamais de donnée médicale.
 */
final class ExportAlerter
{
    public const MAX_ATTEMPTS = 5;
    /** Délai avant la tentative suivante (minutes), selon le nombre de tentatives déjà faites. */
    public const RETRY_DELAYS = [1 => 5, 2 => 15, 3 => 60, 4 => 240];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ExportJobRepository $jobs,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ExportSettings $settings,
        private ExportWorkerWatch $workerWatch,
    ) {
    }

    /** Appelée à chaque échec (worker, surveillance, route synchrone). */
    public function onFailure(ExportJob $job): void
    {
        // Panne du worker : l'étape en cours au moment de l'arrêt est fortuite, ce n'est pas un autre
        // incident (sinon un worker qui plante en boucle enverrait un e-mail par étape).
        $step = in_array($job->getErrorCategory(), [ExportFailure::WORKER_INTERRUPTED, ExportFailure::WORKER_UNAVAILABLE], true)
            ? null : $job->getErrorStep();
        $fingerprint = sha1(implode('|', [$job->getType(), $job->getErrorCategory(), $step, $job->getErrorCode()]));
        $job->setAlertFingerprint($fingerprint);

        // Causes fonctionnelles (année supprimée, accès refusé) : historique seulement, pas d'incident.
        if (in_array($job->getErrorCategory(), [ExportFailure::NOT_FOUND, ExportFailure::ACCESS_DENIED], true)) {
            $this->doctrine->getManager()->flush();

            return;
        }

        if ($this->settings->alertEmail === '') {
            $job->setAlertStatus(ExportJob::ALERT_DISABLED);
            $this->record($job, ExportJobEvent::ALERT_DISABLED, 'Aucune adresse d\'alerte configurée (EXPORT_ALERT_EMAIL)');

            return;
        }

        $since = $this->now()->modify('-' . $this->settings->alertDedupMinutes . ' minutes');
        $original = $this->jobs->findAlertSentSince($fingerprint, $since, (int) $job->getId());
        if ($original !== null) {
            $job->setAlertStatus(ExportJob::ALERT_SUPPRESSED)->setAlertDuplicateOf($original->getPublicId());
            $this->record($job, ExportJobEvent::ALERT_SUPPRESSED, 'Incident déjà signalé (alerte de ' . $original->getDiagnosticId() . ')');

            return;
        }
        // L'arrêt du worker a déjà été signalé par le gardien : même incident.
        if ($step === null && $this->workerWatch->workerAlertSentSince($since)) {
            $job->setAlertStatus(ExportJob::ALERT_SUPPRESSED);
            $this->record($job, ExportJobEvent::ALERT_SUPPRESSED, 'Incident déjà signalé (alerte « worker des exports arrêté »)');

            return;
        }

        $this->send($job);
    }

    /**
     * Nouvelle tentative contrôlée (surveillance ou bouton de l'administration).
     *
     * @return bool vrai si l'alerte est partie
     */
    public function retry(ExportJob $job, bool $force = false): bool
    {
        if ($job->getAlertStatus() !== ExportJob::ALERT_FAILED || $this->settings->alertEmail === '') {
            return false;
        }
        if (!$force) {
            $delay = self::RETRY_DELAYS[$job->getAlertAttempts()] ?? null;
            if ($delay === null || $job->getAlertLastAttemptAt() > $this->now()->modify("-$delay minutes")) {
                return false;
            }
        }

        return $this->send($job);
    }

    /**
     * Récapitulatif des exports prêts jamais téléchargés (au plus un par 24 h).
     *
     * @param list<ExportJob> $pending exports signalés non téléchargés et pas encore récapitulés
     */
    public function notDownloadedDigest(array $pending, bool $sentInLast24h): bool
    {
        $threshold = $this->settings->notDownloadedAlertThreshold;
        if ($threshold <= 0 || $this->settings->alertEmail === '' || $sentInLast24h || count($pending) < $threshold) {
            return false;
        }

        $lines = array_map(fn (ExportJob $job) => sprintf('- %s, utilisateur #%d, prêt le %s, expire le %s',
            $job->getDiagnosticId(), $job->getUserId(), $this->format($job->getFinishedAt()), $this->format($job->getExpiresAt())), $pending);
        try {
            $this->mailer->send((new Email())
                ->from('Medclick <service@medclick.be>')
                ->to($this->settings->alertEmail)
                ->subject(sprintf('[MedClick] %d export(s) Excel prêt(s) mais jamais téléchargé(s)', count($pending)))
                ->text("Exports générés avec succès mais jamais téléchargés après "
                    . $this->settings->notDownloadedAfterMinutes . " minutes :\n\n" . implode("\n", $lines)
                    . "\n\nDétail : " . $this->adminUrl() . "?status=ready\n\nCe récapitulatif est envoyé au plus une fois par 24 heures."));
        } catch (\Throwable $failure) {
            $this->logger->warning('[export] Récapitulatif « non téléchargés » non envoyé', ['failure' => $failure::class]);

            return false;
        }
        $now = $this->now();
        foreach ($pending as $job) {
            $job->setNotDownloadedAlertedAt($now);
        }
        $this->doctrine->getManager()->flush();

        return true;
    }

    private function send(ExportJob $job): bool
    {
        $now = $this->now();
        $job->incrementAlertAttempts()->setAlertLastAttemptAt($now);

        try {
            $this->mailer->send($this->failureEmail($job));
        } catch (\Throwable $failure) {
            // Erreur d'envoi (SMTP…) : aucune donnée médicale, la classe et le message suffisent.
            $error = $failure::class . ' : ' . mb_substr($failure->getMessage(), 0, 300);
            $job->setAlertStatus(ExportJob::ALERT_FAILED)->setAlertLastError($error);
            $final = $job->getAlertAttempts() >= self::MAX_ATTEMPTS;
            $this->record($job, ExportJobEvent::ALERT_FAILED, $final ? 'Échec définitif de l\'envoi' : 'Échec de l\'envoi, nouvelle tentative prévue', [
                'attempt' => $job->getAlertAttempts(), 'error' => $failure::class,
            ]);
            $this->logger->error("[export {$job->getCorrelationId()}] Alerte e-mail non envoyée", ['export' => $job->getPublicId(), 'failure' => $failure::class]);

            return false;
        }

        $job->setAlertStatus(ExportJob::ALERT_SENT)->setAlertSentAt($now)->setAlertLastError(null);
        $this->record($job, ExportJobEvent::ALERT_SENT, 'Alerte envoyée à l\'administrateur', ['attempt' => $job->getAlertAttempts()]);

        return true;
    }

    private function failureEmail(ExportJob $job): Email
    {
        $user = $this->doctrine->getManager()->find(User::class, $job->getUserId());
        $step = ExportStep::tryFrom((string) $job->getErrorStep());
        $stalled = in_array($job->getErrorCategory(), [ExportFailure::WORKER_INTERRUPTED, ExportFailure::TIMEOUT, ExportFailure::WORKER_UNAVAILABLE], true);
        $duration = $job->getDurationMs() !== null ? number_format($job->getDurationMs() / 1000, 1, ',', ' ') . ' s' : '-';

        $text = implode("\n", [
            $stalled ? 'Un export Excel est resté bloqué et a été arrêté.' : 'Un export Excel a échoué.',
            '',
            'Identifiant de diagnostic : ' . $job->getDiagnosticId(),
            'Utilisateur : #' . $job->getUserId() . ($user ? ' (' . $user->getEmail() . ')' : ' (compte supprimé)'),
            'Type d\'export : ' . $job->getType() . ', année de formation ' . ($job->getParameters()['yearOfFormation'] ?? '?'),
            'Demandé le : ' . $this->format($job->getRequestedAt()),
            'Échec le : ' . $this->format($job->getFinishedAt()),
            'Étape : ' . ($step ? $step->label() : (string) $job->getErrorStep()),
            'Durée avant l\'échec : ' . $duration,
            'Type d\'erreur : ' . $job->getErrorCategory() . ' — ' . $job->getErrorMessage(),
            'Code technique : ' . $job->getErrorCode(),
            'Corrélation (journaux) : ' . $job->getCorrelationId(),
            '',
            'Détail (connexion administrateur requise) : ' . $this->adminUrl() . '?id=' . $job->getPublicId(),
            '',
            'Les incidents identiques des ' . $this->settings->alertDedupMinutes . ' prochaines minutes ne feront pas l\'objet d\'un nouvel e-mail.',
        ]);

        return (new Email())
            ->from('Medclick <service@medclick.be>')
            ->to($this->settings->alertEmail)
            ->subject(sprintf('[MedClick] %s d\'un export Excel (%s) — %s',
                $stalled ? 'Blocage' : 'Échec', $job->getErrorCategory(), $job->getDiagnosticId()))
            ->text($text);
    }

    private function record(ExportJob $job, string $type, string $message, ?array $context = null): void
    {
        $em = $this->doctrine->getManager();
        $em->persist(new ExportJobEvent($job, $type, $this->now(), $job->getErrorStep(), $message, $context));
        $em->flush();
    }

    private function adminUrl(): string
    {
        return rtrim($this->settings->publicFrontendUrl, '/') . '/admin/exports';
    }

    private function format(?\DateTimeImmutable $date): string
    {
        return $date ? $date->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y H:i:s') . ' UTC' : '-';
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
