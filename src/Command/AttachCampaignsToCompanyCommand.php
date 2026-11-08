<?php

declare(strict_types=1);

namespace App\Command;

use App\Advertising\Emplacements;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattache des publicités existantes, restées sans entreprise après la migration
 * Version20261109090000 (annonceur non renseigné), à une entreprise de la bibliothèque
 * (docs/PUBLICITES-PARTENAIRES.md, section 4).
 *
 * - Par défaut : simulation, uniquement des SELECT. Affiche chaque publicité, son état actuel,
 *   l'entreprise visée et l'effet visible (nom et logo de l'entreprise dans le pied du carrousel).
 * - --apply : écrit, dans une transaction, avec une mise à jour conditionnelle (la publicité doit
 *   être encore sans entreprise) ; relancer ne modifie plus rien.
 * - --detach : retour arrière, retire l'entreprise des publicités données (et le nom
 *   d'annonceur qu'elle avait reçu).
 * - --expect-name : garde-fou, chaque publicité doit contenir ce texte dans son nom.
 *
 * Aucune donnée personnelle : identifiants, noms de publicités et d'entreprises.
 */
#[AsCommand(name: 'app:attach-campaigns-to-company', description: "Rattache des publicités sans entreprise à une entreprise (simulation par défaut).")]
final class AttachCampaignsToCompanyCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('campaign', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Identifiant de publicité (répétable)')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, "Nom de l'entreprise (casse et espaces ignorés)")
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, "Identifiant de l'entreprise (si plusieurs portent le même nom)")
            ->addOption('expect-name', null, InputOption::VALUE_REQUIRED, 'Texte que doit contenir le nom de chaque publicité')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement (sinon simulation)')
            ->addOption('detach', null, InputOption::VALUE_NONE, "Retour arrière : retire l'entreprise de ces publicités");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $detach = (bool) $input->getOption('detach');
        $raw = (array) $input->getOption('campaign');
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), $raw))));
        if ($ids === [] || count(array_filter($raw, static fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) > 0) {
            $io->error('Indiquez au moins une publicité avec --campaign=<id> (nombres entiers).');

            return Command::INVALID;
        }

        $company = $this->company($io, $input->getOption('company'), $input->getOption('company-id'));
        if ($company === null) {
            return Command::INVALID;
        }

        $campaigns = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.campaign_name, m.status, m.start_date, m.end_date, m.placements, m.sponsor_name, m.logo, m.partner_id, p.name AS partner_name
             FROM marketing m LEFT JOIN partner p ON p.id = m.partner_id WHERE m.id IN (' . implode(',', $ids) . ') ORDER BY m.id',
        );
        $found = array_map('intval', array_column($campaigns, 'id'));
        if ($missing = array_diff($ids, $found)) {
            $io->error('Publicité introuvable : ' . implode(', ', $missing) . '.');

            return Command::FAILURE;
        }
        $expect = $input->getOption('expect-name');
        if (is_string($expect) && $expect !== '') {
            foreach ($campaigns as $c) {
                if (mb_stripos((string) $c['campaign_name'], $expect) === false) {
                    $io->error(sprintf('La publicité %d (« %s ») ne contient pas « %s » : rien n\'est modifié.', $c['id'], $c['campaign_name'], $expect));

                    return Command::FAILURE;
                }
            }
        }

        $rows = [];
        $todo = [];
        foreach ($campaigns as $c) {
            $current = $c['partner_id'] === null ? 'aucune' : sprintf('%s (#%d)', $c['partner_name'], $c['partner_id']);
            if ($detach) {
                $action = (int) $c['partner_id'] === $company['id'] ? 'retirer' : 'inchangée (pas cette entreprise)';
            } else {
                $action = match (true) {
                    $c['partner_id'] === null => 'rattacher',
                    (int) $c['partner_id'] === $company['id'] => 'déjà rattachée',
                    default => 'refusée (autre entreprise)',
                };
            }
            if (in_array($action, ['rattacher', 'retirer'], true)) {
                $todo[] = (int) $c['id'];
            }
            $emplacements = array_unique(array_filter(array_map(Emplacements::forCampaignPlacement(...), json_decode((string) $c['placements'], true) ?: [])));
            $rows[] = [
                $c['id'],
                $c['campaign_name'],
                $c['status'] . ($c['end_date'] !== null && $c['end_date'] < date('Y-m-d') ? ' (terminée)' : ''),
                implode(', ', array_map(static fn (string $e) => Emplacements::get($e)['label'], $emplacements)),
                $current,
                $action,
            ];
        }
        $io->title(($apply ? 'Application' : 'Simulation') . ($detach ? ' du retour arrière' : '') . sprintf(' : entreprise « %s » (#%d)', $company['name'], $company['id']));
        $io->table(['id', 'Publicité', 'Statut', 'Emplacements', 'Entreprise actuelle', 'Action'], $rows);
        if (!$detach) {
            $io->note(sprintf(
                "Effet visible : le pied de ces publicités affiche « %s »%s. Visuels, liens, dates, ordre et statistiques ne changent pas.",
                $company['name'],
                $company['logo'] ? ' et son logo (quand la publicité n\'a pas de logo propre)' : '',
            ));
        }

        if ($todo === []) {
            $io->success('Rien à modifier.');

            return Command::SUCCESS;
        }
        if (!$apply) {
            $io->warning(sprintf('%d publicité(s) à %s. Rien n\'est écrit : relancer avec --apply.', count($todo), $detach ? 'détacher' : 'rattacher'));

            return Command::SUCCESS;
        }

        $done = $this->connection->transactional(function (Connection $db) use ($todo, $company, $detach): int {
            $n = 0;
            foreach ($todo as $id) {
                $n += $detach
                    ? $db->executeStatement('UPDATE marketing SET partner_id = NULL, sponsor_name = CASE WHEN sponsor_name = ? THEN NULL ELSE sponsor_name END WHERE id = ? AND partner_id = ?', [$company['name'], $id, $company['id']])
                    : $db->executeStatement('UPDATE marketing SET partner_id = ?, sponsor_name = COALESCE(NULLIF(TRIM(sponsor_name), \'\'), ?) WHERE id = ? AND partner_id IS NULL', [$company['id'], $company['name'], $id]);
            }

            return $n;
        });
        $io->success(sprintf('%d publicité(s) %s.', $done, $detach ? 'détachée(s)' : 'rattachée(s)'));

        return Command::SUCCESS;
    }

    /** @return array{id: int, name: string, logo: bool}|null */
    private function company(SymfonyStyle $io, mixed $name, mixed $id): ?array
    {
        if (is_string($id) && ctype_digit($id)) {
            $rows = $this->connection->fetchAllAssociative('SELECT id, name, logo_id FROM partner WHERE id = ?', [(int) $id]);
        } elseif (is_string($name) && trim($name) !== '') {
            $rows = $this->connection->fetchAllAssociative('SELECT id, name, logo_id FROM partner WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY id', [$name]);
        } else {
            $io->error("Indiquez l'entreprise : --company=<nom> ou --company-id=<id>.");

            return null;
        }
        if (count($rows) !== 1) {
            $io->error($rows === []
                ? 'Entreprise introuvable.'
                : sprintf('Plusieurs entreprises portent ce nom (%s) : précisez --company-id.', implode(', ', array_map(static fn (array $r) => '#' . $r['id'], $rows))));

            return null;
        }

        return ['id' => (int) $rows[0]['id'], 'name' => (string) $rows[0]['name'], 'logo' => $rows[0]['logo_id'] !== null];
    }
}
