<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Certification;
use App\Entity\CvEntry;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\KeyFigure;
use App\Entity\Language;
use App\Entity\Profile;
use App\Entity\Skill;
use App\Entity\SkillPillar;
use App\Service\Cv\PublicCvPdfCache;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Vide le cache des PDF publics du CV dès qu'un élément du contenu du CV est ajouté, modifié ou supprimé, que la
 * modification vienne de l'admin ou de app:content:sync-textes (tous deux passent par Doctrine).
 *
 * La purge a lieu après l'enregistrement (postFlush) : un PDF produit entre-temps ne peut pas garder l'ancien
 * contenu. La clé du cache dépend aussi du contenu (PublicCvPdfCache) : cette purge évite surtout de garder des
 * fichiers périmés.
 *
 * Liens :
 * - Utilise : PublicCvPdfCache::clear()
 * - Entités suivies : celles lues par Service\Content\CvContentProvider (self::CV_ENTITIES)
 * - Voir aussi : tests/Service/Cv/PublicCvPdfCacheTest.php
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CvPdfCacheInvalidationListener
{
    /** Entités qui composent le CV (Service\Content\CvContentProvider). */
    private const CV_ENTITIES = [
        Profile::class,
        Experience::class,
        CvEntry::class,
        Education::class,
        Certification::class,
        Language::class,
        KeyFigure::class,
        SkillPillar::class,
        Skill::class,
    ];

    /** Vrai si le flush en cours touche le contenu du CV. */
    private bool $cvChanged = false;

    /**
     * Reçoit le cache des PDF publics.
     *
     * @param PublicCvPdfCache $pdfCache cache à vider
     */
    public function __construct(private readonly PublicCvPdfCache $pdfCache)
    {
    }

    /**
     * Repère, avant l'écriture, si une entité du CV est ajoutée, modifiée ou supprimée.
     *
     * Liens :
     * - Appelée par : Doctrine (évènement onFlush)
     *
     * @param OnFlushEventArgs $args accès à l'unité de travail
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $entities = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];

        foreach ($entities as $entity) {
            foreach (self::CV_ENTITIES as $class) {
                if ($entity instanceof $class) {
                    $this->cvChanged = true;

                    return;
                }
            }
        }
    }

    /**
     * Vide le cache après l'écriture, si le contenu du CV a changé.
     *
     * Liens :
     * - Appelée par : Doctrine (évènement postFlush)
     * - Utilise : PublicCvPdfCache::clear()
     *
     * @param PostFlushEventArgs $args évènement (non utilisé)
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->cvChanged) {
            $this->cvChanged = false;
            $this->pdfCache->clear();
        }
    }
}
