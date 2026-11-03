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
 * Actes partagés entre spécialités (ADR-211) : autorise des spécialités SUPPLÉMENTAIRES pour des
 * codes existants, d'après un plan vérifié (ex. article 14 n) NomenSoft, « chirurgie orthopédique
 * (DP) et neurochirurgie (DA) »).
 *
 * Garanties :
 * - dry-run par défaut ; --apply écrit dans une seule transaction ;
 * - idempotent : une spécialité déjà autorisée est ignorée ;
 * - tout le plan est validé avant la moindre écriture (identifiant, codes hospitalier et ambulant
 *   et spécialité principale doivent concorder avec la base) ;
 * - aucun code créé, modifié ou supprimé (une seule ligne par code INAMI) ; interventions et favoris
 *   jamais modifiés ;
 * - --revert retire seulement les autorisations listées dans le plan (les interventions déjà
 *   enregistrées sous la spécialité supplémentaire gardent leur spécialité).
 */
#[AsCommand(name: 'app:nomenclature:share-speciality', description: 'Autorise des spécialités supplémentaires pour des codes existants, depuis un plan vérifié (dry-run par défaut).')]
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
            ->addArgument('plan', InputArgument::REQUIRED, 'Plan JSON vérifié { source, share: [{ id, codeHospitalisation, codeAmbulant, speciality, specialities }] }')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement le plan (sinon dry-run)')
            ->addOption('revert', null, InputOption::VALUE_NONE, 'Retire les autorisations du plan (retour arrière)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $revert = (bool) $input->getOption('revert');

        try {
            $plan = $this->readPlan((string) $input->getArgument('plan'));
            $items = $this->validate($plan);
        } catch (\InvalidArgumentException $e) {
            $io->error('Plan refusé, aucune écriture : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->title(($revert ? 'Retour arrière' : 'Partage') . ($apply ? '' : ' (simulation)') . ' — ' . ($plan['source']['label'] ?? 'plan'));
        $labels = $revert
            ? ['todo' => $apply ? 'retiré' : 'à retirer', 'skip' => 'non autorisé (ignoré)']
            : ['todo' => $apply ? 'partagé' : 'à partager', 'skip' => 'déjà autorisé (ignoré)'];
        $result = [$labels['todo'] => 0, $labels['skip'] => 0];

        $run = function () use ($items, $apply, $revert, $labels, &$result): void {
            foreach ($items as [$nomenclature, $speciality]) {
                $allowed = $nomenclature->allowsSpeciality($speciality);
                if ($revert ? !$allowed : $allowed) {
                    ++$result[$labels['skip']];
                    continue;
                }
                ++$result[$labels['todo']];
                if (!$apply) {
                    continue;
                }
                $revert ? $nomenclature->unshareWith($speciality) : $nomenclature->shareWith($speciality);
                $this->logger->notice($revert ? 'Nomenclature : spécialité supplémentaire retirée' : 'Nomenclature : spécialité supplémentaire autorisée', [
                    'nomenclature_id' => $nomenclature->getId(),
                    'code' => $nomenclature->getCodeHospitalisation(),
                    'speciality' => $speciality,
                ]);
            }
            if ($apply) {
                $this->em->flush();
            }
        };
        $apply ? $this->em->wrapInTransaction($run) : $run();

        $io->table(['Résultat', 'Nombre'], array_map(null, array_keys($result), array_values($result)));
        $io->success($apply ? 'Plan appliqué.' : 'Simulation terminée : aucune écriture (relancer avec --apply).');

        return Command::SUCCESS;
    }

    private function readPlan(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("fichier introuvable : $path");
        }
        $plan = json_decode((string) file_get_contents($path), true);
        if (!is_array($plan) || !isset($plan['source'], $plan['share']) || !is_array($plan['share']) || $plan['share'] === []) {
            throw new \InvalidArgumentException('structure attendue : { source, share: [ … ] } (non vide)');
        }

        return $plan;
    }

    /** @return list<array{0: Nomenclature, 1: string}> */
    private function validate(array $plan): array
    {
        $items = [];
        $seen = [];
        foreach ($plan['share'] as $i => $item) {
            $where = "share[$i]";
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
}
