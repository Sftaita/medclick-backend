<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Surgeries;
use App\Service\SurgeryNomenclatureSelection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Modification d'une intervention (PUT/PATCH API Platform).
 *
 * - `surgeryId` désigne un AUTRE acte que celui déjà lié : relation nomenclature, code, nom et
 *   spécialité sont recalculés ensemble, comme à la création (/api/surgeries/addNewSurgery).
 * - `surgeryId` absent, vide ou égal à l'acte déjà lié : l'identité historique de l'intervention
 *   (relation, code, nom, spécialité) est conservée telle qu'enregistrée, même si la nomenclature
 *   a changé depuis et même si le payload contient un autre nom, code ou spécialité. Seuls la
 *   date, l'année, le rôle et les superviseurs sont alors modifiés.
 * - Intervention sans nomenclature (saisie historique libre) et sans `surgeryId` : comportement
 *   inchangé (champs du payload appliqués).
 * - Une spécialité « favorites » est remplacée par celle de la nomenclature liée (LOT 2D.3).
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
        private SurgeryNomenclatureSelection $selection,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Surgeries) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $request = $context['request'] ?? null;
        $payload = $request instanceof Request ? json_decode($request->getContent(), true) : null;

        try {
            $selected = $this->selection->find(is_array($payload) ? ($payload['surgeryId'] ?? null) : null);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException("Cette intervention n'est pas retrouvée en base de données");
        }

        // État enregistré avant la désérialisation du payload (clone fourni par API Platform).
        $previous = $context['previous_data'] ?? null;
        $previous = $previous instanceof Surgeries ? $previous : null;
        $current = $previous?->getNomenclature();

        if ($selected && SurgeryNomenclatureSelection::isAnotherAct($current, $selected)) {
            // Changement d'acte réellement demandé.
            SurgeryNomenclatureSelection::applyAct($data, $selected);
        } elseif ($previous && $current) {
            // Même acte ou aucun choix : identité historique conservée telle quelle.
            $data->setNomenclature($current)
                ->setCode($previous->getCode())
                ->setName($previous->getName())
                ->setSpeciality($previous->getSpeciality());
        }

        // « favorites » n'est pas une spécialité : l'ancien et le nouveau front l'envoient quand
        // l'intervention vient des favoris. La nomenclature liée reste la source de vérité.
        $nomenclature = $data->getNomenclature();
        if ($nomenclature && $data->getSpeciality() === 'favorites' && ($nomenclature->getSpeciality() ?? '') !== '') {
            $data->setSpeciality($nomenclature->getSpeciality());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
