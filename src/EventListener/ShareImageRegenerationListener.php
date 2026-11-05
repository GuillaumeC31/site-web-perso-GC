<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Profile;
use App\Service\Seo\ShareImageGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Refait l'image de partage quand le nom, le titre, le sous-titre ou la photo du profil changent (admin ou
 * app:content:sync-textes).
 *
 * La production a lieu après l'enregistrement (postFlush). Un échec (GD absent, disque plein) ne bloque jamais
 * l'enregistrement du profil : il est seulement journalisé, sans donnée personnelle ; l'image précédente reste en
 * place et `app:seo:share-image` peut être relancée.
 *
 * Liens :
 * - Utilise : ShareImageGenerator::generate()
 * - Voir aussi : Command\ShareImageCommand, tests/Service/Seo/ShareImageGeneratorTest
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ShareImageRegenerationListener
{
    /** Champs du profil dessinés sur l'image. */
    private const DRAWN_FIELDS = ['fullName', 'jobTitle', 'tagline', 'photoFilename'];

    /** Vrai si le flush en cours modifie un champ dessiné. */
    private bool $profileChanged = false;

    /**
     * Reçoit le générateur et le journal.
     *
     * @param ShareImageGenerator $generator générateur de l'image de partage
     * @param LoggerInterface     $logger    journal applicatif (échec de production)
     */
    public function __construct(
        private readonly ShareImageGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Repère, avant l'écriture, un profil ajouté ou un champ dessiné modifié.
     *
     * Liens :
     * - Appelée par : Doctrine (évènement onFlush)
     *
     * @param OnFlushEventArgs $args accès à l'unité de travail
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Profile) {
                $this->profileChanged = true;
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Profile && [] !== array_intersect(self::DRAWN_FIELDS, array_keys($unitOfWork->getEntityChangeSet($entity)))) {
                $this->profileChanged = true;
            }
        }
    }

    /**
     * Refait l'image après l'écriture, si un champ dessiné a changé.
     *
     * Liens :
     * - Appelée par : Doctrine (évènement postFlush)
     * - Utilise : ShareImageGenerator::generate()
     *
     * @param PostFlushEventArgs $args évènement (non utilisé)
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->profileChanged) {
            return;
        }
        $this->profileChanged = false;

        try {
            $this->generator->generate();
        } catch (\RuntimeException $exception) {
            $this->logger->warning('Image de partage non refaite : {reason}', ['reason' => $exception->getMessage()]);
        }
    }
}
