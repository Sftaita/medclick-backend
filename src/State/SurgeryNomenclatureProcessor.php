<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Surgeries;
use App\Repository\NomenclatureRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Modification d'une intervention (PUT/PATCH API Platform) : si le payload contient un
 * `surgeryId` (intervention choisie dans la nomenclature), la relation nomenclature, le code,
 * le nom et la spécialité sont recalculés comme à la création (/api/surgeries/addNewSurgery).
 * Sans `surgeryId` (ou vide), l'intervention garde sa nomenclature et son code.
 * Une spécialité « favorites » est remplacée par celle de la nomenclature liée (LOT 2D.3).
 *
 * `surgeryId` n'est pas une propriété de l'entité : il est lu dans le corps de la requête.
 *
 * @implements ProcessorInterface<Surgeries, Surgeries>
 */
final class SurgeryNomenclatureProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private NomenclatureRepository $nomenclatureRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'] ?? null;
        $payload = $request instanceof Request ? json_decode($request->getContent(), true) : null;
        $surgeryId = is_array($payload) ? ($payload['surgeryId'] ?? null) : null;

        if ($data instanceof Surgeries && $surgeryId !== null && $surgeryId !== '') {
            $reference = filter_var($surgeryId, FILTER_VALIDATE_INT) !== false
                ? $this->nomenclatureRepository->find((int) $surgeryId)
                : null;

            if (!$reference) {
                throw new BadRequestHttpException("Cette intervention n'est pas retrouvée en base de données");
            }

            $data->setNomenclature($reference)
                ->setCode($reference->getCodeHospitalisation() . '' . $reference->getN())
                ->setName($reference->getName())
                ->setSpeciality($reference->getSpeciality());
        }

        // « favorites » n'est pas une spécialité : l'ancien et le nouveau front l'envoient quand
        // l'intervention vient des favoris. La nomenclature liée reste la source de vérité.
        $nomenclature = $data instanceof Surgeries ? $data->getNomenclature() : null;
        if ($nomenclature && $data->getSpeciality() === 'favorites' && ($nomenclature->getSpeciality() ?? '') !== '') {
            $data->setSpeciality($nomenclature->getSpeciality());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
