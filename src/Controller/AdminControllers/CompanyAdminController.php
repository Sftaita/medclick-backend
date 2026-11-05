<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Advertising\Emplacements;
use App\Entity\Marketing;
use App\Entity\Partner;
use App\Entity\PartnerPlacementAssignment as Assignment;
use App\Repository\MarketingImageRepository;
use App\Repository\MarketingRepository;
use App\Repository\PartnerPlacementAssignmentRepository;
use App\Repository\PartnerRepository;
use App\Service\EmplacementManager;
use App\Service\MarketingImageStore;
use App\Service\PartnerManager;
use App\Service\PartnerPlacementConflict;
use App\Service\PartnerPlacementManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bibliothèque des entreprises (ROLE_ADMIN) : une fiche par entreprise (table partner), quels
 * que soient ses contenus. Une entreprise n'est pas une publicité : ses publicités et ses
 * attributions se gèrent dans les emplacements (EmplacementAdminController).
 *
 * Logo : POST /api/admin/partner-logos ; visuel de présentation : POST /api/admin/marketing-images.
 */
#[Route('/api/admin/companies', requirements: ['id' => '\d+'])]
final class CompanyAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
        private readonly MarketingRepository $campaigns,
        private readonly PartnerPlacementAssignmentRepository $assignments,
        private readonly PartnerManager $profiles,
        private readonly PartnerPlacementManager $placements,
        private readonly EmplacementManager $emplacements,
        private readonly MarketingImageRepository $images,
        private readonly MarketingImageStore $imageStore,
    ) {
    }

    #[Route('', name: 'admin_companies', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $contents = $this->contentsByCompany();
        $companies = array_map(
            fn (Partner $p) => $this->toArray($p, $contents[$p->getId()] ?? []),
            $this->partners->findBy([], ['name' => 'ASC', 'id' => 'ASC']),
        );

        return new JsonResponse(['companies' => $companies]);
    }

    /** Fiche, avec tous ses contenus (publicités et attributions) par emplacement. */
    #[Route('/{id}', name: 'admin_company', methods: ['GET'])]
    public function show(Partner $partner): JsonResponse
    {
        $contents = $this->contentsByCompany()[$partner->getId()] ?? [];

        return new JsonResponse($this->toArray($partner, $contents) + ['contents' => $contents]);
    }

    #[Route('', name: 'admin_company_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->save(new Partner(), $request, Response::HTTP_CREATED);
    }

    /** Modification de la fiche : visible aussitôt partout où l'entreprise est diffusée. */
    #[Route('/{id}', name: 'admin_company_update', methods: ['PUT'])]
    public function update(Partner $partner, Request $request): JsonResponse
    {
        return $this->save($partner, $request, Response::HTTP_OK);
    }

    /** Suppression : seulement une entreprise sans aucun contenu (son historique est conservé). */
    #[Route('/{id}', name: 'admin_company_delete', methods: ['DELETE'])]
    public function delete(Partner $partner): JsonResponse
    {
        $used = ($this->assignments->countByPartner()[$partner->getId()] ?? 0) > 0
            || $this->campaigns->count(['partner' => $partner]) > 0
            || $partner->getStatus() !== Partner::STATUS_DRAFT;
        if ($used) {
            return new JsonResponse(['message' => "Cette entreprise a des publicités ou des attributions : son historique est conservé."], Response::HTTP_CONFLICT);
        }
        $cover = $partner->getCoverImage();
        $this->em->remove($partner);
        $this->em->flush();
        $this->imageStore->release($cover !== null ? [$cover] : []);

        return new JsonResponse(['message' => 'Entreprise supprimée.']);
    }

    /**
     * Opération globale, depuis la fiche : remplace cette entreprise par une autre sur tous les
     * emplacements où elle est en ligne ou programmée à partir de startsOn.
     * { companyId, startsOn, endsOn, confirm } : sans confirm, renvoie seulement la liste des
     * emplacements concernés (rien n'est modifié). Avec confirm : publié aussitôt.
     */
    #[Route('/{id}/replace-everywhere', name: 'admin_company_replace', methods: ['POST'])]
    public function replaceEverywhere(Partner $partner, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_int($data['companyId'] ?? null) || ($target = $this->partners->find($data['companyId'])) === null || $target === $partner) {
            return new JsonResponse(['message' => "Choisissez l'entreprise qui la remplace."], Response::HTTP_BAD_REQUEST);
        }
        $start = is_string($data['startsOn'] ?? null) ? $data['startsOn'] : $this->emplacements->today();
        $concerned = [];
        foreach ($this->assignments->findHistory() as $assignment) {
            if ($assignment->getPartner() === $partner && $assignment->getStatus() === Assignment::PUBLISHED
                && ($assignment->getEndsOn() === null || $assignment->getEndsOn()->format('Y-m-d') >= $start)
                && !Assignment::isShared($assignment->getPlacement())) {
                $concerned[$assignment->getPlacement()] = Emplacements::STAT_LABELS[$assignment->getPlacement()];
            }
        }
        if ($concerned === []) {
            return new JsonResponse(['message' => "Cette entreprise n'est en ligne ni programmée sur aucun emplacement exclusif."], Response::HTTP_BAD_REQUEST);
        }
        $preview = ['placements' => array_map(static fn (string $p, string $l) => ['placement' => $p, 'label' => $l], array_keys($concerned), $concerned)];
        if (($data['confirm'] ?? false) !== true) {
            return new JsonResponse($preview);
        }
        try {
            $this->placements->assign([
                'placements' => array_keys($concerned),
                'partnerId' => $target->getId(),
                'startsOn' => $start,
                'endsOn' => $data['endsOn'] ?? null,
                'publish' => true,
                'replace' => true,
            ]);
        } catch (PartnerPlacementConflict|\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($preview + ['done' => true]);
    }

    private function save(Partner $partner, Request $request, int $status): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $previousCover = $partner->getCoverImage();
        try {
            $this->profiles->applyProfile($partner, $data);
            if ($partner->getCoverImage() !== null && $partner->getCoverImage() !== $previousCover && $this->images->findByPath($partner->getCoverImage()) === null) {
                throw new \InvalidArgumentException('Visuel introuvable : importez-le de nouveau.');
            }
            $this->applyContact($partner, $data);
            foreach ($this->partners->findAll() as $other) {
                if ($other !== $partner && mb_strtolower(trim($other->getName())) === mb_strtolower($partner->getName())) {
                    throw new \InvalidArgumentException(sprintf("L'entreprise « %s » existe déjà : modifiez sa fiche plutôt que d'en créer une autre.",$other->getName()));
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->em->clear();

            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        // Nom repris dans les rapports des publicités de l'entreprise.
        foreach ($partner->getId() === null ? [] : $this->campaigns->findBy(['partner' => $partner]) as $campaign) {
            $campaign->setSponsorName($partner->getName());
        }
        $this->em->persist($partner);
        $this->em->flush();
        if ($previousCover !== null && $previousCover !== $partner->getCoverImage()) {
            $this->imageStore->release([$previousCover]);
        }

        return new JsonResponse($this->toArray($partner, $this->contentsByCompany()[$partner->getId()] ?? []), $status);
    }

    /** @throws \InvalidArgumentException */
    private function applyContact(Partner $partner, array $data): void
    {
        $value = static function (string $key, int $max, string $label) use ($data): ?string {
            $v = $data[$key] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) {
                return null;
            }
            if (!is_string($v) || mb_strlen(trim($v)) > $max) {
                throw new \InvalidArgumentException("$label : $max caractères au maximum.");
            }

            return trim($v);
        };
        $email = $value('contactEmail', 180, 'E-mail du contact');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail du contact invalide.');
        }
        $partner->setContactName($value('contactName', 120, 'Nom du contact'))
            ->setContactEmail($email)
            ->setContactPhone($value('contactPhone', 40, 'Téléphone du contact'));
    }

    /**
     * Contenus de chaque entreprise : publicités (par emplacement) et attributions.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function contentsByCompany(): array
    {
        $today = $this->emplacements->today();
        $result = [];
        foreach ($this->campaigns->findBy([], ['id' => 'DESC']) as $campaign) {
            if ($campaign->getPartner() === null) {
                continue;
            }
            $keys = array_values(array_unique(array_filter(array_map(Emplacements::forCampaignPlacement(...), $campaign->getPlacements()))));
            foreach ($keys as $key) {
                $result[$campaign->getPartner()->getId()][] = [
                    'type' => 'ad',
                    'id' => $campaign->getId(),
                    'emplacement' => $key,
                    'emplacementLabel' => Emplacements::get($key)['label'],
                    'name' => $campaign->getCampaignName(),
                    'state' => EmplacementManager::campaignState($campaign, $today),
                    'startsOn' => $campaign->getStartDate()?->format('Y-m-d'),
                    'endsOn' => $campaign->getEndDate()?->format('Y-m-d'),
                ];
            }
        }
        foreach ($this->assignments->findHistory() as $assignment) {
            if ($assignment->getPartner() === null) {
                continue;
            }
            $key = Emplacements::forPartnerPlacement($assignment->getPlacement());
            $result[$assignment->getPartner()->getId()][] = [
                'type' => 'partner',
                'id' => $assignment->getId(),
                'emplacement' => $key,
                'emplacementLabel' => Emplacements::STAT_LABELS[$assignment->getPlacement()],
                'name' => Emplacements::STAT_LABELS[$assignment->getPlacement()],
                'state' => EmplacementManager::assignmentState($assignment, $today),
                'startsOn' => $assignment->getStartsOn()?->format('Y-m-d'),
                'endsOn' => $assignment->getEndsOn()?->format('Y-m-d'),
            ];
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $contents */
    private function toArray(Partner $partner, array $contents): array
    {
        $count = static fn (array $states) => count(array_filter($contents, static fn (array $c) => in_array($c['state'], $states, true)));
        $used = [];
        foreach ($contents as $content) {
            if (in_array($content['state'], ['live', 'scheduled'], true)) {
                $used[$content['emplacementLabel']] = true;
            }
        }

        return [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'websiteUrl' => $partner->getWebsiteUrl(),
            'tagline' => $partner->getTagline(),
            'about' => $partner->getAbout(),
            'commitments' => $partner->getCommitments(),
            'logo' => $partner->getLogo()?->getFileName(),
            'coverImage' => $partner->getCoverImage(),
            'contactName' => $partner->getContactName(),
            'contactEmail' => $partner->getContactEmail(),
            'contactPhone' => $partner->getContactPhone(),
            'emplacements' => array_keys($used),
            'counts' => ['live' => $count(['live']), 'scheduled' => $count(['scheduled']), 'ended' => $count(['ended', 'inactive']), 'draft' => $count(['draft'])],
            'deletable' => $contents === [] && $partner->getStatus() === Partner::STATUS_DRAFT,
            'updatedAt' => $partner->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
