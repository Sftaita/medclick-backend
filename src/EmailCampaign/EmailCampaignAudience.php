<?php

declare(strict_types=1);

namespace App\EmailCampaign;

use App\Entity\ConnectionHistory;
use App\Entity\User;
use App\Repository\EmailCampaignSendRepository;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sélection des destinataires d'une campagne, recalculée à chaque consultation.
 *
 * Éligible : compte activé (adresse confirmée), adresse valide, absente de la liste d'opposition,
 * pas encore de ligne pour cette campagne. Segments :
 * - actifs : dernière connexion (ou création du compte, sans connexion) depuis moins de 12 mois ;
 * - anciens : au-delà.
 *
 * L'administration ne reçoit que des compteurs ; les adresses ne sortent que vers la commande
 * d'envoi (docs/CAMPAGNES-EMAIL.md).
 */
final class EmailCampaignAudience
{
    public const ACTIVE = 'actifs';
    public const FORMER = 'anciens';
    public const ALL = 'tous';
    public const ACTIVE_MONTHS = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailUnsubscribeRepository $unsubscribes,
        private readonly EmailCampaignSendRepository $sends,
    ) {
    }

    /**
     * @return array{eligible: array{actifs: int, anciens: int, total: int, cgu_acceptees: int}, excluded: array{non_activés: int, adresse_invalide: int, désinscrits: int, déjà_envoyés: int}, total_accounts: int, active_since: string}
     */
    public function summary(EmailCampaignDefinition $campaign): array
    {
        $eligible = [self::ACTIVE => 0, self::FORMER => 0, 'total' => 0, 'cgu_acceptees' => 0];
        $excluded = ['non_activés' => 0, 'adresse_invalide' => 0, 'désinscrits' => 0, 'déjà_envoyés' => 0];
        $total = 0;
        foreach ($this->classify($campaign) as $row) {
            ++$total;
            if ($row['excluded'] !== null) {
                ++$excluded[$row['excluded']];
                continue;
            }
            ++$eligible[$row['segment']];
            ++$eligible['total'];
            if ($row['accepted_terms']) {
                ++$eligible['cgu_acceptees'];
            }
        }

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'total_accounts' => $total,
            'active_since' => $this->activeSince()->format(\DATE_ATOM),
        ];
    }

    /**
     * Adresses éligibles d'un segment (commande d'envoi uniquement).
     *
     * @return list<string>
     */
    public function recipients(EmailCampaignDefinition $campaign, string $segment): array
    {
        $emails = [];
        foreach ($this->classify($campaign) as $row) {
            if ($row['excluded'] === null && ($segment === self::ALL || $row['segment'] === $segment)) {
                $emails[] = $row['email'];
            }
        }

        return $emails;
    }

    private function activeSince(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('-%d months', self::ACTIVE_MONTHS));
    }

    /**
     * @return iterable<array{email: string, segment: string, excluded: ?string, accepted_terms: bool}>
     */
    private function classify(EmailCampaignDefinition $campaign): iterable
    {
        $unsubscribed = $this->unsubscribes->allAddresses();
        $alreadySent = $this->sends->campaignAddresses($campaign->slug);
        $activeSince = $this->activeSince();

        $rows = $this->em->createQueryBuilder()
            ->select('u.email AS email, u.token AS token, u.createdAt AS createdAt, u.acceptedTerms AS acceptedTerms, MAX(c.date) AS lastLogin')
            ->from(User::class, 'u')
            ->leftJoin(ConnectionHistory::class, 'c', 'ON', 'c.user = u')
            ->groupBy('u.id, u.email, u.token, u.createdAt, u.acceptedTerms')
            ->orderBy('u.id', \SortDirection::Ascending)
            ->getQuery()->getArrayResult();

        // Une adresse n'est comptée qu'une fois (comptes en double éventuels).
        $seen = [];
        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $last = $row['lastLogin'] ?? $row['createdAt'];
            $last = match (true) {
                $last instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($last),
                is_string($last) && $last !== '' => new \DateTimeImmutable($last),
                default => null,
            };

            yield [
                'email' => $email,
                'segment' => $last !== null && $last >= $activeSince ? self::ACTIVE : self::FORMER,
                'excluded' => match (true) {
                    $row['token'] !== null => 'non_activés',
                    !filter_var($email, FILTER_VALIDATE_EMAIL) => 'adresse_invalide',
                    isset($unsubscribed[$email]) => 'désinscrits',
                    isset($alreadySent[$email]) => 'déjà_envoyés',
                    default => null,
                },
                'accepted_terms' => (bool) $row['acceptedTerms'],
            ];
        }
    }
}
