<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Entity\Partner;
use App\Repository\MarketingImageRepository;
use App\Repository\PartnerPlacementAssignmentRepository;
use App\Repository\PartnerRepository;
use App\Service\MarketingImageStore;
use App\Service\PartnerManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fiches des firmes partenaires (ROLE_ADMIN) : nom, logo, accroche, texte, engagements, site
 * et visuel. Aucune diffusion ici : elle se décide par emplacement (PartnerPlacementAdminController).
 * Logo : POST /api/admin/partner-logos ; visuel : POST /api/admin/marketing-images.
 */
#[Route('/api/admin/partner-firms')]
final class PartnerFirmAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
        private readonly PartnerPlacementAssignmentRepository $assignments,
        private readonly PartnerManager $manager,
        private readonly MarketingImageRepository $images,
        private readonly MarketingImageStore $imageStore,
    ) {
    }

    #[Route('', name: 'admin_partner_firms', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $counts = $this->assignments->countByPartner();
        $firms = array_map(fn (Partner $p) => $this->toArray($p, $counts[$p->getId()] ?? 0), $this->partners->findBy([], ['name' => 'ASC', 'id' => 'ASC']));

        return new JsonResponse(['firms' => $firms]);
    }

    #[Route('', name: 'admin_partner_firm_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->save(new Partner(), $request, Response::HTTP_CREATED);
    }

    /** Modification de la fiche : visible aussitôt partout où la firme est diffusée. */
    #[Route('/{id<\d+>}', name: 'admin_partner_firm_update', methods: ['PUT'])]
    public function update(Partner $partner, Request $request): JsonResponse
    {
        return $this->save($partner, $request, Response::HTTP_OK);
    }

    /** Suppression : seulement une firme qui n'a jamais été attribuée à un emplacement. */
    #[Route('/{id<\d+>}', name: 'admin_partner_firm_delete', methods: ['DELETE'])]
    public function delete(Partner $partner): JsonResponse
    {
        if (($this->assignments->countByPartner()[$partner->getId()] ?? 0) > 0 || $partner->getStatus() !== Partner::STATUS_DRAFT) {
            return new JsonResponse(['message' => 'Cette firme a déjà été diffusée : son historique est conservé. Retirez plutôt ses affectations.'], Response::HTTP_CONFLICT);
        }
        $cover = $partner->getCoverImage();
        $this->em->remove($partner);
        $this->em->flush();
        $this->imageStore->release($cover !== null ? [$cover] : []);

        return new JsonResponse(['message' => 'Firme supprimée.']);
    }

    private function save(Partner $partner, Request $request, int $status): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $previousCover = $partner->getCoverImage();
        try {
            $this->manager->applyProfile($partner, $data);
            if ($partner->getCoverImage() !== null && $partner->getCoverImage() !== $previousCover && $this->images->findByPath($partner->getCoverImage()) === null) {
                throw new \InvalidArgumentException('Visuel introuvable : importez-le de nouveau.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->em->clear();

            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->em->persist($partner);
        $this->em->flush();
        if ($previousCover !== null && $previousCover !== $partner->getCoverImage()) {
            $this->imageStore->release([$previousCover]);
        }

        return new JsonResponse($this->toArray($partner, $this->assignments->countByPartner()[$partner->getId()] ?? 0), $status);
    }

    private function toArray(Partner $partner, int $assignments): array
    {
        return [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'websiteUrl' => $partner->getWebsiteUrl(),
            'tagline' => $partner->getTagline(),
            'about' => $partner->getAbout(),
            'commitments' => $partner->getCommitments(),
            'logo' => $partner->getLogo()?->getFileName(),
            'coverImage' => $partner->getCoverImage(),
            'assignments' => $assignments,
            'updatedAt' => $partner->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
