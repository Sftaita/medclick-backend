<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Surgeries;
use App\Service\InactiveNomenclatureException;
use App\Service\RepealedOnDateException;
use App\Service\SurgeryNomenclatureSelection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Modification d'une intervention (PUT/PATCH API Platform).
 *
 * - `surgeryId` désigne un AUTRE acte que celui déjà lié : relation nomenclature, code, nom et
 *   spécialité sont recalculés ensemble, comme à la création (/api/surgeries/addNewSurgery).
 * - `surgeryId` absent, vide ou égal à l'acte déjà lié : relation, code, nom et spécialité restent
 *   rigoureusement identiques aux valeurs enregistrées, même si la nomenclature a changé depuis,
 *   même si la spécialité enregistrée est « favorites », même sans nomenclature liée, et même si
 *   le payload contient un autre nom, code ou spécialité. Seuls la date, l'année, le rôle et les
 *   superviseurs sont alors modifiés.
 * - Les spécialités « favorites » historiques ne sont plus normalisées au PUT (ancien LOT 2D.3) :
 *   leur correction relève de `app:repair-surgery-nomenclature --scope=favorites`.
 *
 * - Étape 4 (ADR-210) : l'acte choisi pour un changement d'acte (ou une création POST
 *   /api/surgeries avec `surgeryId`) doit être utilisable à la date de l'intervention : actif, ou
 *   inactif avec une abrogation VÉRIFIÉE postérieure à cette date ; sinon 422 sur `name`.
 *   Un code inactif déjà lié reste accepté tant qu'il n'est pas changé ; seule une nouvelle date
 *   au-delà de son abrogation vérifiée est refusée (422 sur `date`).
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
            // Changement d'acte réellement demandé : l'acte doit être actif, puis les quatre champs
            // suivent l'acte choisi (la spécialité est celle de la nomenclature, jamais « favorites »).
            // Utilisable à la date (nouvelle) de l'intervention : actif, ou abrogé après cette date.
            try {
                SurgeryNomenclatureSelection::assertSelectable($selected, $data->getDate());
            } catch (InactiveNomenclatureException $e) {
                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation($e->getMessage(), null, [], $data, 'name', $payload['surgeryId'] ?? null),
                ]));
            }
            SurgeryNomenclatureSelection::applyAct($data, $selected);
        } elseif ($previous) {
            // Acte inchangé : une nouvelle date au-delà de l'abrogation vérifiée est refusée (sur
            // « date ») plutôt que de garder ou remplacer le code en silence.
            try {
                SurgeryNomenclatureSelection::assertDateKeepsAct($current, $previous->getDate(), $data->getDate());
            } catch (RepealedOnDateException $e) {
                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation($e->getMessage(), null, [], $data, 'date', $data->getDate()?->format('Y-m-d')),
                ]));
            }
            // Même acte ou aucun choix : les quatre champs reprennent STRICTEMENT les valeurs
            // enregistrées (y compris une spécialité « favorites », une nomenclature absente ou
            // un code différent du référentiel actuel). Aucune normalisation n'est appliquée.
            $data->setNomenclature($current)
                ->setCode($previous->getCode())
                ->setName($previous->getName())
                ->setSpeciality($previous->getSpeciality());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
