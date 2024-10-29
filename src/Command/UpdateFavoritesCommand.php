<?php

namespace App\Command;

use App\Entity\Favorites;
use App\Entity\Nomenclature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


// Utilisation:
// 1. Assurez-vous que cette classe est correctement définie dans votre espace de noms Symfony.
// 2. Ouvrez une console et naviguez vers la racine de votre projet Symfony.
// 3. Exécutez la commande: php bin/console app:update-favorites
// 4. Après l'exécution, vous verrez le nombre de favoris sans correspondance de nomenclature.
class UpdateFavoritesCommand extends Command
{
    protected static $defaultName = 'app:update-favorites';

    // L'EntityManager est injecté dans la commande pour pouvoir interagir avec la base de données
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure()
    {
        $this->setDescription('Update favorites with corresponding nomenclature.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Initialisation de SymfonyStyle pour avoir une interface utilisateur agréable
        $io = new SymfonyStyle($input, $output);

        // Récupération des dépôts nécessaires pour interroger la base de données
        $favoritesRepository = $this->entityManager->getRepository(Favorites::class);
        $nomenclatureRepository = $this->entityManager->getRepository(Nomenclature::class);

        // Récupération de toutes les chirurgies
        $favorites = $favoritesRepository->findAll();

        // Compteur pour le nombre de chirurgies sans nomenclature correspondante
        $unmatchedCount = 0;

        // Boucle sur chaque chirurgie
        foreach ($favorites as $favorite) {
            // Tentative de trouver une nomenclature qui correspond au nom de la chirurgie
            $nomenclature = $nomenclatureRepository->findOneBy(['name' => $favorite->getSurgeryName()]);

            // Si aucune nomenclature n'est trouvée par le nom, recherche par le préfixe du code
            if (!$nomenclature) {
                $codePrefix = substr($favorite->getCodeHospitalisation(), 0, 6);
                $nomenclature = $nomenclatureRepository->findOneBy(['codeHospitalisation' => $codePrefix]);
            }

            // Si une nomenclature est trouvée, mise à jour de la chirurgie
            if ($nomenclature) {
                $favorite->setSurgery($nomenclature);
                $this->entityManager->persist($favorite);
            } else {
                // Sinon, augmentez le compteur des chirurgies sans correspondance
                $unmatchedCount++;
            }
        }

        // Sauvegarde des modifications dans la base de données
        $this->entityManager->flush();

        // Affichage des messages de réussite et du nombre de chirurgies sans correspondance
        $io->success('Surgeries updated successfully.');
        $io->note('Number of surgeries without matching nomenclature: ' . $unmatchedCount);

        // Retourne 0 pour indiquer que la commande s'est terminée avec succès
        return 0;
    }
}

