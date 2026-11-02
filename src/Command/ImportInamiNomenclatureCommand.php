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
 * Actualisation de la nomenclature INAMI à partir d'un plan vérifié (étape 5, ADR-210).
 *
 * Le plan (JSON) est produit par le rapprochement avec les tableaux officiels NomenSoft ; il ne
 * contient que des changements établis :
 * - « add » : nouveaux codes (spécialité, codes, lettre-clé, libellé officiel, classification) ;
 * - « repeal » : codes existants dont l'abrogation officielle est établie (date d'effet et acte).
 *
 * Garanties :
 * - dry-run par défaut ; --apply écrit dans une seule transaction ;
 * - idempotent : un code déjà présent (code hospitalier ou ambulant, toute spécialité) n'est jamais
 *   recréé ; une abrogation déjà enregistrée à l'identique est ignorée ;
 * - aucune suppression ; pour un code existant, seuls le statut, la date officielle d'abrogation,
 *   sa source et sa vérification changent (identifiant, codes, libellé, spécialité, type et sous-type
 *   inchangés ; interventions et favoris liés jamais modifiés) ;
 * - une abrogation différente déjà saisie n'est jamais écrasée (conflit signalé, code ignoré) ;
 * - tout le plan est validé avant la moindre écriture (identifiant et code doivent concorder).
 */
#[AsCommand(name: 'app:nomenclature:import-inami', description: 'Actualise la nomenclature INAMI depuis un plan vérifié (dry-run par défaut).')]
final class ImportInamiNomenclatureCommand extends Command
{
    private const SPECIALITIES = ['ortho', 'dig', 'general', 'uro', 'vasc', 'thor', 'plastic', 'neuro', 'transp'];
    private const SUBTYPES = ['shoulder', 'humerus', 'elbow', 'forearm', 'wristhand', 'back', 'pelvic', 'hip', 'proximalFemur',
        'midFemur', 'distalFemur', 'knee', 'limb', 'ankle', 'foot'];

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
            ->addArgument('plan', InputArgument::REQUIRED, 'Plan JSON vérifié (ajouts et abrogations établies)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement le plan (sinon dry-run)')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Écrit le compte rendu détaillé (JSON) dans ce fichier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        try {
            $plan = $this->readPlan((string) $input->getArgument('plan'));
            [$adds, $repeals] = $this->validate($plan);
        } catch (\InvalidArgumentException $e) {
            $io->error('Plan refusé, aucune écriture : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->title(($apply ? 'Import' : 'Simulation (dry-run)') . ' — ' . ($plan['source']['label'] ?? 'plan INAMI'));
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'source' => $plan['source'], 'add' => [], 'repeal' => []];

        $run = function () use ($adds, $repeals, $apply, &$report): void {
            $now = $this->clock->now();
            foreach ($adds as $add) {
                $report['add'][] = $this->add($add, $apply);
            }
            foreach ($repeals as [$item, $nomenclature]) {
                $report['repeal'][] = $this->repeal($item, $nomenclature, $now, $apply);
            }
            if ($apply) {
                $this->em->flush();
            }
        };
        $apply ? $this->em->wrapInTransaction($run) : $run();

        $count = static fn (array $rows, string $result) => count(array_filter($rows, fn ($r) => $r['result'] === $result));
        $io->table(['Opération', 'Résultat', 'Nombre'], [
            ['ajout', $apply ? 'ajouté' : 'à ajouter', $count($report['add'], $apply ? 'ajouté' : 'à ajouter')],
            ['ajout', 'déjà présent (ignoré)', $count($report['add'], 'déjà présent')],
            ['abrogation', $apply ? 'désactivé' : 'à désactiver', $count($report['repeal'], $apply ? 'désactivé' : 'à désactiver')],
            ['abrogation', 'déjà appliquée (ignorée)', $count($report['repeal'], 'déjà appliquée')],
            ['abrogation', 'conflit (ignorée)', $count($report['repeal'], 'conflit')],
        ]);

        if ($path = $input->getOption('report')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $io->text("Compte rendu : $path");
        }
        $io->success($apply ? 'Plan appliqué.' : 'Simulation terminée : aucune écriture (relancer avec --apply).');

        return Command::SUCCESS;
    }

    private function readPlan(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("fichier introuvable : $path");
        }
        $plan = json_decode((string) file_get_contents($path), true);
        if (!is_array($plan) || !isset($plan['source'], $plan['add'], $plan['repeal']) || !is_array($plan['add']) || !is_array($plan['repeal'])) {
            throw new \InvalidArgumentException('structure attendue : { source, add: [], repeal: [] }');
        }

        return $plan;
    }

    /** @return array{0: list<array>, 1: list<array{0: array, 1: Nomenclature}>} */
    private function validate(array $plan): array
    {
        $adds = [];
        foreach ($plan['add'] as $i => $add) {
            $where = "add[$i]";
            foreach (['speciality', 'codeHospitalisation', 'n', 'name', 'reference'] as $field) {
                if (!isset($add[$field]) || !is_string($add[$field]) || trim($add[$field]) === '') {
                    throw new \InvalidArgumentException("$where : « $field » requis");
                }
            }
            if (!in_array($add['speciality'], self::SPECIALITIES, true)) {
                throw new \InvalidArgumentException("$where : spécialité inconnue « {$add['speciality']} »");
            }
            foreach (['codeHospitalisation', 'codeAmbulant'] as $field) {
                if (($add[$field] ?? '') !== '' && !preg_match('/^\d{6}$/', $add[$field])) {
                    throw new \InvalidArgumentException("$where : « $field » doit compter 6 chiffres");
                }
            }
            if (!preg_match('/^[A-Z]\d+(,\d+)?$/', $add['n'])) {
                throw new \InvalidArgumentException("$where : lettre-clé invalide « {$add['n']} »");
            }
            if (mb_strlen($add['name']) > 500 || mb_strlen($add['reference']) > 255) {
                throw new \InvalidArgumentException("$where : libellé (500) ou référence (255) trop long");
            }
            $type = $add['type'] ?? null;
            $subType = $add['subType'] ?? null;
            if ($add['speciality'] === 'ortho' && (!in_array($type, ['1', '2'], true) || !in_array($subType, self::SUBTYPES, true))) {
                throw new \InvalidArgumentException("$where : un code orthopédique exige un type (1 ou 2) et une région de la liste fermée");
            }
            if ($add['speciality'] !== 'ortho' && ($type !== null || $subType !== null)) {
                throw new \InvalidArgumentException("$where : type et région réservés à l'orthopédie");
            }
            $adds[] = $add;
        }

        $repeals = [];
        $seen = [];
        foreach ($plan['repeal'] as $i => $item) {
            $where = "repeal[$i]";
            $id = $item['id'] ?? null;
            if (!is_int($id) || isset($seen[$id])) {
                throw new \InvalidArgumentException("$where : identifiant entier unique requis");
            }
            $seen[$id] = true;
            $date = is_string($item['repealedAt'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $item['repealedAt']) : false;
            if (!$date || $date->format('Y-m-d') !== $item['repealedAt']) {
                throw new \InvalidArgumentException("$where : date d'abrogation AAAA-MM-JJ requise");
            }
            if (!is_string($item['source'] ?? null) || trim($item['source']) === '' || mb_strlen($item['source']) > 255) {
                throw new \InvalidArgumentException("$where : source officielle requise (255 caractères au plus)");
            }
            $nomenclature = $this->nomenclatures->find($id);
            if (!$nomenclature) {
                throw new \InvalidArgumentException("$where : nomenclature $id introuvable");
            }
            if ($nomenclature->getCodeHospitalisation() !== ($item['codeHospitalisation'] ?? null)) {
                throw new \InvalidArgumentException("$where : la nomenclature $id porte le code {$nomenclature->getCodeHospitalisation()}, pas {$item['codeHospitalisation']}");
            }
            $repeals[] = [$item + ['date' => $date], $nomenclature];
        }

        return [$adds, $repeals];
    }

    private function add(array $add, bool $apply): array
    {
        $codes = array_values(array_filter([$add['codeHospitalisation'], $add['codeAmbulant'] ?? '']));
        $existing = $this->em->createQuery('SELECT n.id FROM App\Entity\Nomenclature n WHERE n.codeHospitalisation IN (:codes) OR n.codeAmbulant IN (:codes)')
            ->setParameter('codes', $codes)->setMaxResults(1)->getOneOrNullResult();
        $row = ['code' => $add['codeHospitalisation'], 'speciality' => $add['speciality']];
        if ($existing) {
            return $row + ['result' => 'déjà présent', 'id' => $existing['id']];
        }
        if (!$apply) {
            return $row + ['result' => 'à ajouter'];
        }

        $nomenclature = (new Nomenclature())
            ->setSpeciality($add['speciality'])
            ->setCodeHospitalisation($add['codeHospitalisation'])
            ->setCodeAmbulant(($add['codeAmbulant'] ?? '') !== '' ? $add['codeAmbulant'] : null)
            ->setN($add['n'])
            ->setName($add['name'])
            ->setType($add['type'] ?? null)
            ->setSubType($add['subType'] ?? null);
        $this->em->persist($nomenclature);
        $this->em->flush();
        $this->logger->notice('Nomenclature INAMI : code ajouté (import)', ['nomenclature_id' => $nomenclature->getId(), 'code' => $add['codeHospitalisation'], 'speciality' => $add['speciality'], 'reference' => $add['reference']]);

        return $row + ['result' => 'ajouté', 'id' => $nomenclature->getId()];
    }

    private function repeal(array $item, Nomenclature $nomenclature, \DateTimeImmutable $now, bool $apply): array
    {
        $row = ['id' => $nomenclature->getId(), 'code' => $nomenclature->getCodeHospitalisation(), 'repealedAt' => $item['repealedAt']];
        $recorded = $nomenclature->getInamiRepealedAt()?->format('Y-m-d');
        $alreadyDone = !$nomenclature->isActive() && $recorded === $item['repealedAt']
            && $nomenclature->getInamiRepealVerifiedAt() !== null && $nomenclature->getInamiRepealSource() === trim($item['source']);
        if ($alreadyDone) {
            return $row + ['result' => 'déjà appliquée'];
        }
        // Une autre date déjà saisie (administration) n'est jamais écrasée.
        if ($recorded !== null && $recorded !== $item['repealedAt']) {
            return $row + ['result' => 'conflit', 'detail' => "date déjà enregistrée : $recorded"];
        }
        if (!$apply) {
            return $row + ['result' => 'à désactiver'];
        }

        $nomenclature->setInamiRepealedAt($item['date'])
            ->setInamiRepealSource($item['source'])
            ->verifyRepeal($now)
            ->deactivate($now);
        $this->logger->notice('Nomenclature INAMI : abrogation officielle importée', ['nomenclature_id' => $nomenclature->getId(), 'repealed_at' => $item['repealedAt'], 'source' => $item['source']]);

        return $row + ['result' => 'désactivé'];
    }
}
