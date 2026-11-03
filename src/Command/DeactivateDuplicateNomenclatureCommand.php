<?php

namespace App\Command;

use App\Entity\Nomenclature;
use App\Repository\NomenclatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Désactivation ADMINISTRATIVE de lignes qui doublonnent un acte porté par une autre ligne (ADR-212).
 *
 * Ce n'est PAS une abrogation officielle : `inami_repealed_at`, `inami_repeal_source` et
 * `inami_repeal_verified_at` restent vides, et la justification est dans `deactivation_reason`.
 * Sans abrogation vérifiée, une ligne inactive n'est proposée à aucune date (ADR-210).
 *
 * Garanties :
 * - dry-run par défaut ; --apply écrit dans une seule transaction ; idempotent ;
 * - --revert rétablit exactement l'état antérieur (actif, sans date technique ni justification) ;
 * - tout le plan est validé avant la moindre écriture ; une ligne dans un autre état est refusée ;
 * - la ligne qui porte l'acte doit être active, de même libellé et valeur, avec ses codes corrigés ;
 * - aucune suppression ni fusion ; interventions et favoris jamais modifiés ni recodés.
 */
#[AsCommand(name: 'app:nomenclature:deactivate-duplicates', description: 'Désactive administrativement des lignes en doublon depuis un plan vérifié (dry-run par défaut).')]
final class DeactivateDuplicateNomenclatureCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private NomenclatureRepository $nomenclatures,
        private ClockInterface $clock,
        #[Target('nomenclature')] private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plan', InputArgument::REQUIRED, 'Plan JSON vérifié { source, deactivate: [{ id, codeHospitalisation, codeAmbulant, n, speciality, name, sameActAs, sameActCodes, reason }] }')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement le plan (sinon dry-run)')
            ->addOption('revert', null, InputOption::VALUE_NONE, 'Rétablit les lignes du plan à leur état actif');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $revert = (bool) $input->getOption('revert');

        try {
            $plan = $this->readPlan((string) $input->getArgument('plan'));
            $items = $this->validate($plan['deactivate'], $revert);
        } catch (\InvalidArgumentException $e) {
            $io->error('Plan refusé, aucune écriture : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->title(($revert ? 'Retour arrière' : 'Désactivation administrative') . ($apply ? '' : ' (simulation)') . ' — ' . ($plan['source']['label'] ?? 'plan'));
        $changed = $ignored = 0;
        $run = function () use ($items, $apply, $revert, &$changed, &$ignored): void {
            $now = $this->clock->now();
            foreach ($items as [$item, $nomenclature]) {
                if ($nomenclature->isActive() === $revert) {
                    ++$ignored;
                    continue;
                }
                ++$changed;
                if (!$apply) {
                    continue;
                }
                $revert ? $nomenclature->activate() : $nomenclature->deactivateAdministratively($now, $item['reason']);
                $this->logger->notice('Nomenclature : ' . ($revert ? 'désactivation administrative annulée' : 'désactivation administrative'), [
                    'nomenclature_id' => $nomenclature->getId(),
                    'code' => $nomenclature->getCodeHospitalisation(),
                    'meme_acte_que' => $item['sameActAs'] ?? null,
                    'justification' => $item['reason'],
                ]);
            }
            if ($apply) {
                $this->em->flush();
            }
        };
        $apply ? $this->em->wrapInTransaction($run) : $run();

        $label = $revert ? ($apply ? 'rétablie' : 'à rétablir') : ($apply ? 'désactivée' : 'à désactiver');
        $io->table(['Résultat', 'Nombre'], [[$label, $changed], ['déjà dans cet état (ignorée)', $ignored]]);
        $io->success($apply ? 'Plan appliqué.' : 'Simulation terminée : aucune écriture (relancer avec --apply).');

        return Command::SUCCESS;
    }

    private function readPlan(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("fichier introuvable : $path");
        }
        $plan = json_decode((string) file_get_contents($path), true);
        if (!is_array($plan) || !isset($plan['source'], $plan['deactivate']) || !is_array($plan['deactivate']) || $plan['deactivate'] === []) {
            throw new \InvalidArgumentException('structure attendue : { source, deactivate: [ … ] (non vide) }');
        }

        return $plan;
    }

    /** @return list<array{0: array, 1: Nomenclature}> */
    private function validate(array $lines, bool $revert): array
    {
        $items = [];
        $seen = [];
        foreach ($lines as $i => $item) {
            $where = "deactivate[$i]";
            $id = $item['id'] ?? null;
            if (!is_int($id) || isset($seen[$id])) {
                throw new \InvalidArgumentException("$where : identifiant entier unique requis");
            }
            $seen[$id] = true;
            $reason = $item['reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 255) {
                throw new \InvalidArgumentException("$where : justification requise (255 caractères au plus)");
            }
            $nomenclature = $this->nomenclatures->find($id);
            if (!$nomenclature) {
                throw new \InvalidArgumentException("$where : nomenclature $id introuvable");
            }
            foreach (['codeHospitalisation' => $nomenclature->getCodeHospitalisation(), 'codeAmbulant' => $nomenclature->getCodeAmbulant(), 'n' => $nomenclature->getN(), 'speciality' => $nomenclature->getSpeciality(), 'name' => $nomenclature->getName()] as $field => $actual) {
                if (($item[$field] ?? null) !== $actual) {
                    throw new \InvalidArgumentException("$where : la nomenclature $id a un autre « $field » que le plan");
                }
            }
            if ($nomenclature->getInamiRepealedAt() !== null || $nomenclature->getInamiRepealSource() !== null || $nomenclature->getInamiRepealVerifiedAt() !== null) {
                throw new \InvalidArgumentException("$where : la nomenclature $id porte des données d'abrogation officielle : à examiner");
            }
            if ($nomenclature->getSharedSpecialities() !== []) {
                throw new \InvalidArgumentException("$where : la nomenclature $id est autorisée dans une autre spécialité : à retirer d'abord");
            }
            $consistent = $nomenclature->isActive()
                ? $nomenclature->getDeactivationReason() === null && $nomenclature->getDeactivatedAt() === null
                : $nomenclature->getDeactivationReason() === $reason;
            if (!$consistent) {
                throw new \InvalidArgumentException("$where : la nomenclature $id est dans un autre état que celui du plan : à examiner");
            }
            if (!$revert) {
                $this->assertActIsCarriedElsewhere($item, $nomenclature, $where);
            }
            $items[] = [$item, $nomenclature];
        }

        return $items;
    }

    /** La ligne conservée doit porter le même acte, être active et avoir ses codes corrigés. */
    private function assertActIsCarriedElsewhere(array $item, Nomenclature $nomenclature, string $where): void
    {
        $other = is_int($item['sameActAs'] ?? null) ? $this->nomenclatures->find($item['sameActAs']) : null;
        if (!$other || $other->getId() === $nomenclature->getId()) {
            throw new \InvalidArgumentException("$where : ligne « sameActAs » introuvable");
        }
        if (!$other->isActive() || $other->getName() !== $nomenclature->getName() || $other->getN() !== $nomenclature->getN() || $other->getSpeciality() !== $nomenclature->getSpeciality()) {
            throw new \InvalidArgumentException("$where : la ligne {$other->getId()} ne porte pas le même acte actif");
        }
        $codes = ['codeHospitalisation' => $other->getCodeHospitalisation(), 'codeAmbulant' => $other->getCodeAmbulant()];
        if (($item['sameActCodes'] ?? null) !== $codes) {
            throw new \InvalidArgumentException("$where : la ligne {$other->getId()} n'a pas les codes corrigés attendus : appliquer d'abord la correction");
        }
    }
}
