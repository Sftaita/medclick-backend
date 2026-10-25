<?php

namespace App\Controller\AdminControllers;

use App\Entity\Partner;
use App\Entity\PartnerLogo;
use App\Repository\PartnerLogoRepository;
use App\Repository\PartnerRepository;
use App\Service\PartnerLogoValidator;
use App\Service\PartnerManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration du partenaire (ROLE_ADMIN : access_control ^/api/admin). Brouillon,
 * publication (immédiate ou programmée), retrait, reprogrammation, historique, logo.
 */
#[Route('/api/admin')]
class PartnerAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
        private readonly PartnerManager $manager,
    ) {
    }

    /** Historique complet et partenaire affiché aujourd'hui. */
    #[Route('/partners', name: 'admin_partners', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'today' => $this->manager->today()->format('Y-m-d'),
            'liveId' => $this->manager->findLive()?->getId(),
            'partners' => array_map(fn (Partner $p) => $this->manager->toArray($p, true), $this->partners->findHistory()),
        ]);
    }

    #[Route('/partners/{id<\d+>}', name: 'admin_partner', methods: ['GET'])]
    public function show(Partner $partner): JsonResponse
    {
        return new JsonResponse($this->manager->toArray($partner, true));
    }

    /** Nouveau partenaire : brouillon, ou publié si « publish » vaut true. */
    #[Route('/partners', name: 'admin_partner_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $partner = new Partner();

        return $this->save($partner, $request, Response::HTTP_CREATED);
    }

    /**
     * Modification. Un partenaire publié reste publié (périodes recontrôlées) ; un brouillon est
     * publié si « publish » vaut true. Rien n'est enregistré si un contrôle échoue.
     */
    #[Route('/partners/{id<\d+>}', name: 'admin_partner_update', methods: ['PUT'])]
    public function update(Partner $partner, Request $request): JsonResponse
    {
        if ($partner->getStatus() === Partner::STATUS_WITHDRAWN) {
            return $this->error('Ce partenaire a été retiré : utilisez « Reprogrammer » pour l\'afficher de nouveau.', Response::HTTP_CONFLICT);
        }

        return $this->save($partner, $request, Response::HTTP_OK);
    }

    /** Arrête l'affichage (partenaire en ligne ou programmé). L'historique est conservé. */
    #[Route('/partners/{id<\d+>}/withdraw', name: 'admin_partner_withdraw', methods: ['POST'])]
    public function withdraw(Partner $partner): JsonResponse
    {
        if ($partner->getStatus() !== Partner::STATUS_PUBLISHED) {
            return $this->error('Seul un partenaire publié peut être retiré.', Response::HTTP_CONFLICT);
        }
        $partner->withdraw();
        $this->em->flush();

        return new JsonResponse($this->manager->toArray($partner, true));
    }

    /** Reprogrammation : copie (logo et textes) en brouillon, à dater puis publier. */
    #[Route('/partners/{id<\d+>}/reschedule', name: 'admin_partner_reschedule', methods: ['POST'])]
    public function reschedule(Partner $partner): JsonResponse
    {
        $copy = $partner->copyAsDraft();
        $this->em->persist($copy);
        $this->em->flush();

        return new JsonResponse($this->manager->toArray($copy, true), Response::HTTP_CREATED);
    }

    /** Suppression : brouillons uniquement (un partenaire publié reste dans l'historique). */
    #[Route('/partners/{id<\d+>}', name: 'admin_partner_delete', methods: ['DELETE'])]
    public function delete(Partner $partner): JsonResponse
    {
        if ($partner->getStatus() !== Partner::STATUS_DRAFT) {
            return $this->error('Seul un brouillon peut être supprimé ; retirez plutôt le partenaire.', Response::HTTP_CONFLICT);
        }
        $this->em->remove($partner);
        $this->em->flush();

        return new JsonResponse(['message' => 'Brouillon supprimé.']);
    }

    /**
     * Envoi d'un logo (multipart, champ « file ») : contrôlé, stocké une seule fois par contenu.
     * Il n'est affiché qu'une fois associé à un partenaire publié.
     */
    #[Route('/partner-logos', name: 'admin_partner_logo_upload', methods: ['POST'])]
    public function uploadLogo(Request $request, PartnerLogoValidator $validator, PartnerLogoRepository $logos): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('Aucun fichier reçu (1 Mo au maximum).');
        }
        if ($file->getSize() > PartnerLogoValidator::MAX_BYTES) {
            return $this->error('Le logo dépasse 1 Mo.');
        }
        $data = (string) file_get_contents($file->getPathname());
        try {
            $info = $validator->validate($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $logo = $logos->findOneBy(['sha256' => hash('sha256', $data)]);
        if ($logo === null) {
            $logo = new PartnerLogo($data, $info['mime'], $info['width'], $info['height']);
            $this->em->persist($logo);
            $this->em->flush();
        }

        return new JsonResponse([
            'logo' => $logo->getFileName(),
            'mimeType' => $logo->getMimeType(),
            'width' => $logo->getWidth(),
            'height' => $logo->getHeight(),
            'size' => $logo->getSize(),
        ], Response::HTTP_CREATED);
    }

    private function save(Partner $partner, Request $request, int $status): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        try {
            $this->manager->apply($partner, $data);
            if ($partner->getStatus() === Partner::STATUS_PUBLISHED || (is_array($data) && ($data['publish'] ?? false) === true)) {
                $this->manager->publish($partner);
            }
        } catch (\InvalidArgumentException $e) {
            // Aucune écriture : l'entité modifiée en mémoire n'est pas enregistrée.
            $this->em->clear();

            return $this->error($e->getMessage());
        }
        $this->em->persist($partner);
        $this->em->flush();

        return new JsonResponse($this->manager->toArray($partner, true), $status);
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
