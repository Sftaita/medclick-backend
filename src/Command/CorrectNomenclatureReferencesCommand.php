<?php

namespace App\Command;

use App\Entity\Nomenclature;
use App\Repository\NomenclatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Corrige les codes hospitalier et ambulant de lignes de nomenclature d'après un plan vérifié (ADR-212).
 *
 * Garanties :
 * - dry-run par défaut ; --apply écrit dans une seule transaction ; --revert rétablit l'état « avant » ;
 * - idempotent : une ligne déjà dans l'état demandé est ignorée ;
 * - tout le plan est validé avant la moindre écriture : identifiant, spécialité, valeur et libellé
 *   doivent concorder, et chaque ligne doit être exactement dans l'état « avant » ou « après » ;
 * - un code ne peut se retrouver sur plusieurs lignes que s'il est listé dans `knownDuplicates` ;
 * - seules les deux colonnes de codes changent : identifiant, spécialité, libellé, valeur, type, région
 *   et statut sont conservés ; interventions et favoris (copies figées, ADR-007) ne sont jamais écrits ;
 * - chaque correction est journalisée avant/après sur le canal `nomenclature`.
 */
#[AsCommand(name: 'app:nomenclature:correct-references', description: 'Corrige les codes INAMI de lignes de nomenclature depuis un plan vérifié (dry-run par défaut).')]
final class CorrectNomenclatureReferencesCommand extends Command
{
    private const CODE = '/^\d{6}$/';

    public function __construct(
        private EntityManagerInterface $em,
        private NomenclatureRepository $nomenclatures,
        #[Target('nomenclature')] private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plan', InputArgument::REQUIRED, 'Plan JSON vérifié { source, correct: [{ id, speciality, n, name, before, after }], knownDuplicates: { code: [ids] } }')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement le plan (sinon dry-run)')
            ->addOption('revert', null, InputOption::VALUE_NONE, 'Rétablit les codes « avant » des lignes du plan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $revert = (bool) $input->getOption('revert');

        try {
            $plan = $this->readPlan((string) $input->getArgument('plan'));
            $items = $this->validate($plan['correct']);
            $this->assertNoNewDuplicate($items, $plan['knownDuplicates'] ?? [], $revert);
        } catch (\InvalidArgumentException $e) {
            $io->error('Plan refusé, aucune écriture : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->title(($revert ? 'Retour arrière' : 'Correction') . ($apply ? '' : ' (simulation)') . ' — ' . ($plan['source']['label'] ?? 'plan'));
        $changed = $ignored = 0;
        $run = function () use ($items, $apply, $revert, &$changed, &$ignored): void {
            foreach ($items as [$item, $nomenclature]) {
                $from = $item[$revert ? 'after' : 'before'];
                $to = $item[$revert ? 'before' : 'after'];
                if (self::codes($nomenclature) === $to) {
                    ++$ignored;
                    continue;
                }
                ++$changed;
                if (!$apply) {
                    continue;
                }
                $nomenclature->setCodeHospitalisation($to['codeHospitalisation'])->setCodeAmbulant($to['codeAmbulant']);
                $this->logger->notice('Nomenclature INAMI : ' . ($revert ? 'correction annulée' : 'référence corrigée'), [
                    'nomenclature_id' => $nomenclature->getId(),
                    'avant' => $from,
                    'apres' => $to,
                ]);
            }
            if ($apply) {
                $this->em->flush();
            }
        };
        $apply ? $this->em->wrapInTransaction($run) : $run();

        $label = $revert ? ($apply ? 'rétablie' : 'à rétablir') : ($apply ? 'corrigée' : 'à corriger');
        $io->table(['Résultat', 'Nombre'], [[$label, $changed], ['déjà dans cet état (ignorée)', $ignored]]);
        $io->success($apply ? 'Plan appliqué.' : 'Simulation terminée : aucune écriture (relancer avec --apply).');

        return Command::SUCCESS;
    }

    /** @return array{codeHospitalisation: ?string, codeAmbulant: ?string} */
    private static function codes(Nomenclature $nomenclature): array
    {
        return ['codeHospitalisation' => $nomenclature->getCodeHospitalisation(), 'codeAmbulant' => $nomenclature->getCodeAmbulant()];
    }

    private function readPlan(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("fichier introuvable : $path");
        }
        $plan = json_decode((string) file_get_contents($path), true);
        if (!is_array($plan) || !isset($plan['source'], $plan['correct']) || !is_array($plan['correct']) || $plan['correct'] === []) {
            throw new \InvalidArgumentException('structure attendue : { source, correct: [ … ] (non vide), knownDuplicates: { … } }');
        }

        return $plan;
    }

    /** @return list<array{0: array, 1: Nomenclature}> */
    private function validate(array $lines): array
    {
        $items = [];
        $seen = [];
        foreach ($lines as $i => $item) {
            $where = "correct[$i]";
            $id = $item['id'] ?? null;
            if (!is_int($id) || isset($seen[$id])) {
                throw new \InvalidArgumentException("$where : identifiant entier unique requis");
            }
            $seen[$id] = true;
            foreach (['before', 'after'] as $state) {
                $item[$state] = [
                    'codeHospitalisation' => $item[$state]['codeHospitalisation'] ?? null,
                    'codeAmbulant' => $item[$state]['codeAmbulant'] ?? null,
                ];
                foreach ($item[$state] as $code) {
                    if (!is_string($code) || !preg_match(self::CODE, $code)) {
                        throw new \InvalidArgumentException("$where : « $state » exige deux codes de 6 chiffres");
                    }
                }
            }
            if ($item['before'] === $item['after'] || $item['after']['codeHospitalisation'] === $item['after']['codeAmbulant']) {
                throw new \InvalidArgumentException("$where : correction sans effet ou codes identiques");
            }
            $nomenclature = $this->nomenclatures->find($id);
            if (!$nomenclature) {
                throw new \InvalidArgumentException("$where : nomenclature $id introuvable");
            }
            foreach (['speciality' => $nomenclature->getSpeciality(), 'n' => $nomenclature->getN(), 'name' => $nomenclature->getName()] as $field => $actual) {
                if (($item[$field] ?? null) !== $actual) {
                    throw new \InvalidArgumentException("$where : la nomenclature $id a un autre « $field » que le plan");
                }
            }
            $current = self::codes($nomenclature);
            if ($current !== $item['before'] && $current !== $item['after']) {
                throw new \InvalidArgumentException(sprintf('%s : la nomenclature %d porte %s / %s, ni « avant » ni « après » : à examiner', $where, $id, $current['codeHospitalisation'] ?? '-', $current['codeAmbulant'] ?? '-'));
            }
            $items[] = [$item, $nomenclature];
        }

        return $items;
    }

    /**
     * État final de TOUTE la nomenclature : un code sur plusieurs lignes doit être listé dans le plan
     * avec exactement ces lignes.
     *
     * @param list<array{0: array, 1: Nomenclature}> $items
     */
    private function assertNoNewDuplicate(array $items, array $known, bool $revert): void
    {
        if ($revert) {
            return; // L'état « avant » est celui qui existait déjà.
        }
        $after = [];
        foreach ($items as [$item, $nomenclature]) {
            $after[$nomenclature->getId()] = $item['after'];
        }
        $usage = [];
        $rows = $this->em->createQuery('SELECT n.id, n.codeHospitalisation, n.codeAmbulant FROM App\Entity\Nomenclature n')->getArrayResult();
        foreach ($rows as $row) {
            $codes = $after[$row['id']] ?? $row;
            foreach ([$codes['codeHospitalisation'], $codes['codeAmbulant']] as $code) {
                if ($code !== null && $code !== '') {
                    $usage[$code][$row['id']] = $row['id'];
                }
            }
        }
        foreach ($usage as $code => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $allowed = $known[$code] ?? null;
            if ($allowed !== $ids) {
                throw new \InvalidArgumentException(sprintf('le code %s serait porté par les lignes %s, non prévu par le plan', $code, implode(', ', $ids)));
            }
        }
    }
}
