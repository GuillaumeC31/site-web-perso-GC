<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use App\Enum\ContactSource;
use App\Enum\ContactStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des messages reçus (formulaire de contact public et questions des recruteurs).
 *
 * Filtres de la page Messages (décision du 2026-10-29) : « tous » = boîte de réception (hors archivés), « non
 * lus », « lus », « répondus » (dans la boîte), « archivés ». Un message archivé n'apparaît que dans « archivés ».
 *
 * Liens :
 * - Utilisé par : ContactController, RecruiterQuestionController, Admin\ContactMessageController, DashboardMetrics,
 *   Twig\DashboardExtension (pastille du menu), DataPurgeCommand
 *
 * @extends ServiceEntityRepository<ContactMessage>
 */
class ContactMessageRepository extends ServiceEntityRepository
{
    /** Filtres de la page Messages, dans l'ordre d'affichage. */
    public const FILTERS = ['all', 'unread', 'read', 'replied', 'archived'];

    /** Tris : plus récents d'abord (défaut), plus anciens d'abord. */
    public const SORTS = ['newest', 'oldest'];

    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    /**
     * Renvoie une page de messages selon le filtre, le type, la recherche et le tri.
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController::index()
     *
     * @param string             $filter  un des FILTERS
     * @param ContactSource|null $source  type (null : tous)
     * @param string             $search  mots cherchés dans le nom, l'entreprise ou le message (vide : aucun)
     * @param string             $sort    un des SORTS
     * @param int                $page    numéro de page (à partir de 1)
     * @param int                $perPage messages par page
     *
     * @return array{items: list<ContactMessage>, total: int}
     */
    public function findFiltered(string $filter, ?ContactSource $source, string $search, string $sort, int $page, int $perPage): array
    {
        $total = (int) $this->filtered($filter, $source, $search)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        /** @var list<ContactMessage> $items */
        $items = $this->filtered($filter, $source, $search)
            ->orderBy('m.createdAt', 'oldest' === $sort ? 'ASC' : 'DESC')
            ->addOrderBy('m.id', 'oldest' === $sort ? 'ASC' : 'DESC')
            ->setFirstResult((max(1, $page) - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Compte les messages de chaque filtre, avec le type et la recherche en cours.
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController::index() (nombre affiché sur chaque filtre)
     *
     * @return array<string, int> filtre => nombre
     */
    public function countByFilter(?ContactSource $source, string $search): array
    {
        $counts = [];
        foreach (self::FILTERS as $filter) {
            $counts[$filter] = (int) $this->filtered($filter, $source, $search)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        }

        return $counts;
    }

    /**
     * Renvoie les messages dont l'identifiant est donné (actions groupées).
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController::bulk()
     *
     * @param list<int> $ids identifiants cochés
     *
     * @return list<ContactMessage>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var list<ContactMessage> */
        return $this->createQueryBuilder('m')->where('m.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
    }

    /**
     * Compte les messages non lus de la boîte (hors archivés).
     *
     * Liens :
     * - Appelée par : DashboardMetrics (tuile Messages), Twig\DashboardExtension (pastille du menu « Messages »)
     */
    public function countNew(): int
    {
        return (int) $this->filtered('unread', null, '')->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Compte les messages lus mais sans réponse, dans la boîte (hors archivés).
     *
     * Liens :
     * - Appelée par : DashboardMetrics (tuile Messages)
     */
    public function countAwaitingReply(): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->where('m.archivedAt IS NULL')->andWhere('m.repliedAt IS NULL')->andWhere('m.status = :read')
            ->setParameter('read', ContactStatus::Read)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Supprime les messages reçus avant la date donnée (RGPD, 12 mois).
     *
     * Liens :
     * - Appelée par : DataPurgeCommand
     *
     * @return int nombre de messages supprimés
     */
    public function deleteBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.createdAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /**
     * Efface les adresses IP des messages reçus avant la date donnée (30 jours).
     *
     * Liens :
     * - Appelée par : DataPurgeCommand
     *
     * @return int nombre de lignes modifiées
     */
    public function clearIpAddressesBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('m')
            ->update()
            ->set('m.ipAddress', 'NULL')
            ->andWhere('m.ipAddress IS NOT NULL')
            ->andWhere('m.createdAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /**
     * Requête d'un filtre, d'un type et d'une recherche (sans tri ni pagination).
     *
     * @param string $filter un des FILTERS (inconnu : « all »)
     */
    private function filtered(string $filter, ?ContactSource $source, string $search): QueryBuilder
    {
        $query = $this->createQueryBuilder('m');
        match ($filter) {
            'archived' => $query->where('m.archivedAt IS NOT NULL'),
            'unread' => $query->where('m.archivedAt IS NULL')->andWhere('m.status = :status')->setParameter('status', ContactStatus::New),
            'read' => $query->where('m.archivedAt IS NULL')->andWhere('m.status = :status')->setParameter('status', ContactStatus::Read),
            'replied' => $query->where('m.archivedAt IS NULL')->andWhere('m.repliedAt IS NOT NULL'),
            default => $query->where('m.archivedAt IS NULL'),
        };
        if (null !== $source) {
            $query->andWhere('m.source = :source')->setParameter('source', $source);
        }
        $search = trim($search);
        if ('' !== $search) {
            // Caractères spéciaux de LIKE échappés : on cherche le texte tel quel
            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $query->andWhere('LOWER(m.name) LIKE :search OR LOWER(m.company) LIKE :search OR LOWER(m.message) LIKE :search')->setParameter('search', $like);
        }

        return $query;
    }
}
