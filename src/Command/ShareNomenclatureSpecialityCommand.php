<?php

namespace App\Command;

use App\Entity\Nomenclature;
use App\Entity\NomenclatureHistoricalSpeciality;
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
 * Actes relevant de plusieurs spécialités (ADR-211), d'après un plan vérifié (article 14 n) NomenSoft,
 * « chirurgie orthopédique (DP) et neurochirurgie (DA) »). Trois listes, trois effets distincts :
 * - `share` : autorise la spécialité pour une NOUVELLE saisie (références exactes uniquement) ;
 * - `withdraw` : retire cette autorisation si elle existe (référence à corriger d'abord) ;
 * - `classify` : classe au récapitulatif de cette spécialité les interventions DÉJÀ enregistrées
 *   sous celle-ci, sans ouvrir aucun droit de saisie.
 *
 * Garanties :
 * - dry-run par défaut ; --apply écrit dans une seule transaction ;
 * - idempotent : ce qui est déjà dans l'état demandé est ignoré ;
 * - tout le plan est validé avant la moindre écriture (identifiant, codes hospitalier et ambulant
 *   et spécialité principale doivent concorder avec la base) ;
 * - aucun code créé, modifié ou supprimé (une seule ligne par code INAMI) ; interventions et favoris
 *   jamais modifiés ;
 * - --revert retire les autorisations de `share` et les classements de `classify` (les interventions
 *   gardent leur spécialité) et ne rétablit jamais une autorisation de `withdraw`.
 */
#[AsCommand(name: 'app:nomenclature:share-speciality', description: 'Autorise, retire ou classe des spécialités supplémentaires pour des codes existants, depuis un plan vérifié (dry-run par défaut).')]
final class ShareNomenclatureSpecialityCommand extends Command
{
    private const SPECIALITIES = ['ortho', 'dig', 'general', 'uro', 'vasc', 'thor', 'plastic', 'neuro', 'transp'];

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
            ->addArgument('plan', InputArgument::REQUIRED, 'Plan JSON vérifié { source, share: [], withdraw: [], classify: [] } ; lignes { id, codeHospitalisation, codeAmbulant, speciality, specialities }')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement le plan (sinon dry-run)')
            ->addOption('revert', null, InputOption::VALUE_NONE, 'Retire les autorisations de « share » et les classements de « classify » (retour arrière)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $revert = (bool) $input->getOption('revert');

        try {
            $plan = $this->readPlan((string) $input->getArgument('plan'));
            $share = $this->validate($plan['share'] ?? [], 'share');
            $withdraw = $this->validate($plan['withdraw'] ?? [], 'withdraw');
            $classify = $this->validate($plan['classify'] ?? [], 'classify');
            $this->assertDisjoint($share, $withdraw);
        } catch (\InvalidArgumentException $e) {
            $io->error('Plan refusé, aucune écriture : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->title(($revert ? 'Retour arrière' : 'Partage') . ($apply ? '' : ' (simulation)') . ' — ' . ($plan['source']['label'] ?? 'plan'));
        $result = [];
        $count = static function (string $label) use (&$result): void {
            $result[$label] = ($result[$label] ?? 0) + 1;
        };
        $done = static fn (string $doing, string $did): string => $apply ? $did : $doing;

        $run = function () use ($share, $withdraw, $classify, $apply, $revert, $count, $done): void {
            // Toutes les lignes de résultat apparaissent, même à zéro.
            foreach ($revert ? ['removeShare', 'removeClassify'] : ['share', 'withdraw', 'classify'] as $operation) {
                foreach ($this->labels($operation, $done) as $label) {
                    $count($label);
                }
            }
            $operations = $revert
                ? [['removeShare', $share], ['removeClassify', $classify]]
                : [['share', $share], ['withdraw', $withdraw], ['classify', $classify]];
            foreach ($operations as [$operation, $items]) {
                [$todo, $skip] = $this->labels($operation, $done);
                foreach ($items as [$nomenclature, $speciality]) {
                    if (!$this->needsChange($operation, $nomenclature, $speciality)) {
                        $count($skip);
                        continue;
                    }
                    $count($todo);
                    if ($apply) {
                        $this->change($operation, $nomenclature, $speciality);
                    }
                }
            }
            if ($apply) {
                $this->em->flush();
            }
        };
        $apply ? $this->em->wrapInTransaction($run) : $run();

        // Chaque libellé a été compté une fois pour l'afficher même à zéro.
        $io->table(['Résultat', 'Nombre'], array_map(static fn (string $label, int $n) => [$label, $n - 1], array_keys($result), array_values($result)));
        $io->success($apply ? 'Plan appliqué.' : 'Simulation terminée : aucune écriture (relancer avec --apply).');

        return Command::SUCCESS;
    }

    /** @return array{0: string, 1: string} libellés « à faire » et « rien à faire » */
    private function labels(string $operation, callable $done): array
    {
        return match ($operation) {
            'share' => [$done('à partager', 'partagé'), 'déjà autorisé (ignoré)'],
            'withdraw' => [$done('autorisation à retirer', 'autorisation retirée'), 'déjà sans autorisation (ignoré)'],
            'classify' => [$done('classement à ajouter', 'classement ajouté'), 'déjà classé (ignoré)'],
            'removeShare' => [$done('à retirer', 'retiré'), 'non autorisé (ignoré)'],
            'removeClassify' => [$done('classement à retirer', 'classement retiré'), 'non classé (ignoré)'],
        };
    }

    private function needsChange(string $operation, Nomenclature $nomenclature, string $speciality): bool
    {
        return match ($operation) {
            'share' => !$nomenclature->allowsSpeciality($speciality),
            'withdraw', 'removeShare' => $nomenclature->allowsSpeciality($speciality),
            'classify' => $this->classification($nomenclature, $speciality) === null,
            'removeClassify' => $this->classification($nomenclature, $speciality) !== null,
        };
    }

    private function change(string $operation, Nomenclature $nomenclature, string $speciality): void
    {
        match ($operation) {
            'share' => $nomenclature->shareWith($speciality),
            'withdraw', 'removeShare' => $nomenclature->unshareWith($speciality),
            'classify' => $this->em->persist(new NomenclatureHistoricalSpeciality($nomenclature, $speciality)),
            'removeClassify' => $this->em->remove($this->classification($nomenclature, $speciality)),
        };
        $this->logger->notice('Nomenclature : ' . $operation . ' (plan de spécialités)', [
            'nomenclature_id' => $nomenclature->getId(),
            'code' => $nomenclature->getCodeHospitalisation(),
            'speciality' => $speciality,
        ]);
    }

    private function classification(Nomenclature $nomenclature, string $speciality): ?NomenclatureHistoricalSpeciality
    {
        return $this->em->getRepository(NomenclatureHistoricalSpeciality::class)->findOneBy(['nomenclature' => $nomenclature, 'speciality' => $speciality]);
    }

    private function readPlan(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("fichier introuvable : $path");
        }
        $plan = json_decode((string) file_get_contents($path), true);
        if (!is_array($plan) || !isset($plan['source'], $plan['share']) || !is_array($plan['share']) || $plan['share'] === []) {
            throw new \InvalidArgumentException('structure attendue : { source, share: [ … ] (non vide), withdraw: [ … ], classify: [ … ] }');
        }
        foreach (['withdraw', 'classify'] as $list) {
            if (isset($plan[$list]) && !is_array($plan[$list])) {
                throw new \InvalidArgumentException("« $list » doit être une liste");
            }
        }

        return $plan;
    }

    /** @return list<array{0: Nomenclature, 1: string}> */
    private function validate(array $lines, string $list): array
    {
        $items = [];
        $seen = [];
        foreach ($lines as $i => $item) {
            $where = "{$list}[$i]";
            $id = $item['id'] ?? null;
            if (!is_int($id) || isset($seen[$id])) {
                throw new \InvalidArgumentException("$where : identifiant entier unique requis");
            }
            $seen[$id] = true;
            $nomenclature = $this->nomenclatures->find($id);
            if (!$nomenclature) {
                throw new \InvalidArgumentException("$where : nomenclature $id introuvable");
            }
            foreach (['codeHospitalisation' => $nomenclature->getCodeHospitalisation(), 'codeAmbulant' => $nomenclature->getCodeAmbulant(), 'speciality' => $nomenclature->getSpeciality()] as $field => $actual) {
                if (($item[$field] ?? null) !== $actual) {
                    throw new \InvalidArgumentException(sprintf('%s : la nomenclature %d porte %s « %s », le plan indique « %s »', $where, $id, $field, $actual ?? '', is_scalar($item[$field] ?? null) ? $item[$field] : ''));
                }
            }
            $specialities = $item['specialities'] ?? null;
            if (!is_array($specialities) || $specialities === []) {
                throw new \InvalidArgumentException("$where : « specialities » (liste non vide) requis");
            }
            foreach ($specialities as $speciality) {
                if (!in_array($speciality, self::SPECIALITIES, true)) {
                    throw new \InvalidArgumentException("$where : spécialité inconnue « " . (is_scalar($speciality) ? $speciality : '?') . ' »');
                }
                if ($speciality === $nomenclature->getSpeciality()) {
                    throw new \InvalidArgumentException("$where : « $speciality » est déjà la spécialité principale");
                }
                $items[] = [$nomenclature, $speciality];
            }
        }

        return $items;
    }

    /** Une même autorisation ne peut pas être à la fois accordée et retirée par un plan. */
    private function assertDisjoint(array $share, array $withdraw): void
    {
        $granted = [];
        foreach ($share as [$nomenclature, $speciality]) {
            $granted[$nomenclature->getId() . '|' . $speciality] = true;
        }
        foreach ($withdraw as [$nomenclature, $speciality]) {
            if (isset($granted[$nomenclature->getId() . '|' . $speciality])) {
                throw new \InvalidArgumentException("la nomenclature {$nomenclature->getId()} est à la fois dans « share » et « withdraw »");
            }
        }
    }
}
