<?php

namespace App\Command;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Repository\SurgeriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Utilisation:
// 1. Assurez-vous que cette classe est correctement définie dans votre espace de noms Symfony.
// 2. Ouvrez une console et naviguez vers la racine de votre projet Symfony.
// 3. Exécutez la commande: php bin/console app:update-surgeries
// 4. Après l'exécution, vous verrez le nombre de chirurgies sans correspondance de nomenclature.
class UpdateSurgeriesCommand extends Command
{
    protected static $defaultName = 'app:update-surgeries';

    // L'EntityManager est injecté dans la commande pour pouvoir interagir avec la base de données
    private $entityManager;
    private $surgeriesRepository;

    public function __construct(EntityManagerInterface $entityManager, SurgeriesRepository $surgeriesRepository)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->surgeriesRepository = $surgeriesRepository;
    }

    protected function configure()
    {
        $this->setDescription('Update surgeries with corresponding nomenclature, with a limit of 40,000 surgeries per run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Initialisation de SymfonyStyle pour avoir une interface utilisateur agréable
        $io = new SymfonyStyle($input, $output);

        // Récupération des dépôts nécessaires pour interroger la base de données
        $nomenclatureRepository = $this->entityManager->getRepository(Nomenclature::class);

        // Taille du lot et décalage initial
        $batchSize = 40000;
        $offset = 0;

        // Compteur pour le nombre de chirurgies sans nomenclature correspondante
        $unmatchedCount = 0;
        
        $io->progressStart(); // Initialisation de la barre de progression

        while (true) {
            // Récupérer un lot de chirurgies non traitées (sans nomenclature associée)
            $surgeries = $this->surgeriesRepository->findSurgeriesWithoutNomenclature($batchSize, $offset);

            if (count($surgeries) === 0) {
                break; // Sortir de la boucle si aucune chirurgie à traiter
            }

            foreach ($surgeries as $surgery) {
                // Tentative de trouver une nomenclature qui correspond au nom de la chirurgie
                $nomenclature = $nomenclatureRepository->findOneBy(['name' => $surgery->getName()]);

                // Si aucune nomenclature n'est trouvée par le nom, recherche par le préfixe du code
                if (!$nomenclature) {
                    $codePrefix = substr($surgery->getCode(), 0, 6);
                    $nomenclature = $nomenclatureRepository->findOneBy(['codeHospitalisation' => $codePrefix]);
                }

                // Si une nomenclature est trouvée, mise à jour de la chirurgie
                if ($nomenclature) {
                    $surgery->setNomenclature($nomenclature);
                    $this->entityManager->persist($surgery);
                } else {
                    // Sinon, augmentez le compteur des chirurgies sans correspondance
                    $unmatchedCount++;
                }
            }

            // Sauvegarde des modifications pour ce lot
            $this->entityManager->flush();
            $this->entityManager->clear(); // Évite la surcharge mémoire

            // Augmentez le décalage pour le prochain lot
            $offset += $batchSize;

            // Mise à jour de la progression
            $io->progressAdvance(count($surgeries));
        }

        // Fin de la progression
        $io->progressFinish();

        // Affichage des messages de réussite et du nombre de chirurgies sans correspondance
        $io->success('Surgeries updated successfully.');
        $io->note('Number of surgeries without matching nomenclature: ' . $unmatchedCount);

        // Retourne 0 pour indiquer que la commande s'est terminée avec succès
        return Command::SUCCESS;
    }
}
