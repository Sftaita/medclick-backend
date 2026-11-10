<?php

declare(strict_types=1);

namespace App\Command;

use App\EmailCampaign\CampaignSender;
use App\EmailCampaign\EmailCampaignAudience;
use App\EmailCampaign\EmailCampaignCatalog;
use App\EmailCampaign\EmailCampaignException;
use App\EmailCampaign\EmailCampaignMailer;
use App\Entity\EmailCampaignSend;
use App\Repository\EmailCampaignSendRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Envoi d'une campagne e-mail (docs/CAMPAGNES-EMAIL.md).
 *
 *   --test=adresse   un seul e-mail test (même rendu que la campagne) ;
 *   sans option      simulation : compteurs des destinataires, AUCUN envoi ;
 *   --confirm        envoi collectif, refusé tant que EMAIL_CAMPAIGN_BULK_ENABLED ne vaut pas 1.
 *
 * Un message par destinataire ; chaque adresse est réservée en base avant l'envoi (jamais deux
 * envois, même si la commande est relancée ou lancée deux fois). Un envoi interrompu n'est jamais
 * renvoyé automatiquement ; un envoi en échec seulement avec --retry-failed.
 */
#[AsCommand(name: 'app:email-campaign:send', description: 'Envoie un e-mail test, simule ou lance une campagne e-mail.')]
final class SendEmailCampaignCommand extends Command
{
    public function __construct(
        private readonly EmailCampaignCatalog $catalog,
        private readonly EmailCampaignAudience $audience,
        private readonly EmailCampaignMailer $mailer,
        private readonly CampaignSender $sender,
        private readonly EmailCampaignSendRepository $sends,
        #[Autowire('%env(bool:EMAIL_CAMPAIGN_BULK_ENABLED)%')] private readonly bool $bulkEnabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('campaign', InputArgument::REQUIRED, 'Identifiant de la campagne (ex. recherche-inami)')
            ->addOption('test', null, InputOption::VALUE_REQUIRED, 'Envoie uniquement un e-mail test à cette adresse')
            ->addOption('audience', null, InputOption::VALUE_REQUIRED, 'actifs, anciens ou tous', EmailCampaignAudience::ACTIVE)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d\'envois de cette exécution', '100')
            ->addOption('pause-ms', null, InputOption::VALUE_REQUIRED, 'Pause entre deux envois (millisecondes)', '1500')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Lance réellement l\'envoi collectif')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Relance aussi les envois en échec de cette campagne');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $campaign = $this->catalog->get((string) $input->getArgument('campaign'));
        if ($campaign === null) {
            $io->error('Campagne inconnue.');

            return Command::INVALID;
        }

        $io->definitionList(
            ['Campagne' => $campaign->name],
            ['Objet' => $campaign->subject],
            ['Expéditeur' => CampaignSender::NAME . ' <' . $this->sender->address() . '>'],
        );
        if (($problem = $this->sender->problem()) !== null) {
            $io->error($problem);

            return Command::FAILURE;
        }

        $test = $input->getOption('test');
        if (is_string($test)) {
            try {
                $send = $this->mailer->sendTest($campaign, $test, null);
            } catch (EmailCampaignException $refused) {
                $io->error($refused->getMessage());

                return Command::FAILURE;
            }
            if ($send->getStatus() !== EmailCampaignSend::STATUS_SENT) {
                $io->error('Échec de l\'envoi test : ' . $send->getError());

                return Command::FAILURE;
            }
            $io->success(sprintf('E-mail test envoyé à %s (envoi n° %d).', $send->getEmail(), $send->getId()));

            return Command::SUCCESS;
        }

        $segment = (string) $input->getOption('audience');
        if (!in_array($segment, [EmailCampaignAudience::ACTIVE, EmailCampaignAudience::FORMER, EmailCampaignAudience::ALL], true)) {
            $io->error('--audience : actifs, anciens ou tous.');

            return Command::INVALID;
        }
        $limit = max(1, (int) $input->getOption('limit'));
        $pauseMs = max(0, (int) $input->getOption('pause-ms'));

        $summary = $this->audience->summary($campaign);
        $io->section('Destinataires');
        $io->table(['Éligibles : actifs', 'anciens', 'total', 'Exclus : non activés', 'adresse invalide', 'désinscrits', 'déjà envoyés'], [[
            $summary['eligible'][EmailCampaignAudience::ACTIVE], $summary['eligible'][EmailCampaignAudience::FORMER], $summary['eligible']['total'],
            $summary['excluded']['non_activés'], $summary['excluded']['adresse_invalide'], $summary['excluded']['désinscrits'], $summary['excluded']['déjà_envoyés'],
        ]]);
        $recipients = $this->audience->recipients($campaign, $segment);
        $retries = $input->getOption('retry-failed')
            ? $this->sends->findBy(['campaign' => $campaign->slug, 'kind' => EmailCampaignSend::KIND_CAMPAIGN, 'status' => EmailCampaignSend::STATUS_FAILED])
            : [];
        $io->text(sprintf('Segment « %s » : %d adresse(s) à envoyer, %d échec(s) à relancer ; au plus %d dans cette exécution.', $segment, count($recipients), count($retries), $limit));

        if (!$input->getOption('confirm')) {
            $io->note('Simulation : aucun e-mail envoyé. Ajouter --confirm pour envoyer.');

            return Command::SUCCESS;
        }
        if (!$this->bulkEnabled) {
            $io->error('Envoi collectif verrouillé (EMAIL_CAMPAIGN_BULK_ENABLED=0). Aucun e-mail envoyé.');

            return Command::FAILURE;
        }

        $sent = $failed = $skipped = 0;
        $done = 0;
        foreach ($retries as $send) {
            if ($done >= $limit) {
                break;
            }
            try {
                $result = $this->mailer->retry($campaign, $send);
            } catch (EmailCampaignException) {
                ++$skipped;
                continue;
            }
            ++$done;
            $result->getStatus() === EmailCampaignSend::STATUS_SENT ? ++$sent : ++$failed;
            usleep($pauseMs * 1000);
        }
        foreach ($recipients as $email) {
            if ($done >= $limit) {
                break;
            }
            try {
                $result = $this->mailer->sendCampaign($campaign, $email);
            } catch (EmailCampaignException) {
                ++$skipped;
                continue;
            }
            if ($result === null) {
                ++$skipped;
                continue;
            }
            ++$done;
            $result->getStatus() === EmailCampaignSend::STATUS_SENT ? ++$sent : ++$failed;
            usleep($pauseMs * 1000);
        }

        $io->success(sprintf('%d envoyé(s), %d échec(s), %d ignoré(s) (déjà traités ou désinscrits).', $sent, $failed, $skipped));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
