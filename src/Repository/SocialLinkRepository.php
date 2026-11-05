<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialLink;
use App\Enum\SocialNetwork;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des liens vers les réseaux sociaux (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\SocialLinkController (via AbstractCrudController), PositionManager, Twig\SocialLinksExtension,
 *   MaintenancePage, Seo\SeoContext, Legacy\LegacyMigrator
 *
 * @extends AbstractSortableRepository<SocialLink>
 */
class SocialLinkRepository extends AbstractSortableRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialLink::class);
    }

    /**
     * Renvoie les liens visibles à un endroit, dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : Twig\SocialLinksExtension::getLinks(), MaintenancePage::render(), Seo\SeoContext (sameAs)
     *
     * @param string $place un des SocialLink::PLACE_*
     *
     * @return list<SocialLink> liens à afficher (vide : aucun)
     */
    public function findVisibleFor(string $place): array
    {
        return array_values(array_filter($this->findAllOrdered(), static fn (SocialLink $link): bool => $link->isShownAt($place)));
    }

    /**
     * Renvoie le premier lien d'un réseau (reprise de l'ancien site).
     *
     * Liens :
     * - Appelée par : Legacy\LegacyMigrator::migrateSocialNetworks()
     *
     * @return SocialLink|null null s'il n'y en a aucun
     */
    public function findFirstLinkedIn(): ?SocialLink
    {
        return $this->findOneBy(['network' => SocialNetwork::LinkedIn], ['position' => 'ASC']);
    }
}
