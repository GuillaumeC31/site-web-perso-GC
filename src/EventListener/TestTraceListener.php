<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\TestTraceableInterface;
use App\Service\Dev\TestContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Pose le repère test_trace sur toute ligne TestTraceableInterface créée pendant une requête de test (navigateur
 * de test, compte de test : Service\Dev\TestContext, développement seulement). Le nettoyage de fin de suite ne
 * supprime que ces lignes.
 *
 * Liens :
 * - Utilise : Service\Dev\TestContext::isTestRequest()
 * - Voir aussi : Service\Dev\TestTraceCleaner
 * - Testé par : tests/Service/Dev/TestContextTest
 */
#[AsDoctrineListener(event: Events::prePersist)]
final readonly class TestTraceListener
{
    /**
     * Reçoit le service qui reconnaît les requêtes de test.
     */
    public function __construct(private TestContext $testContext)
    {
    }

    /**
     * Marque la nouvelle ligne si elle vient d'une requête de test.
     *
     * Liens :
     * - Appelée par : Doctrine (avant chaque insertion)
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TestTraceableInterface && !$entity->isTestTrace() && $this->testContext->isTestRequest()) {
            $entity->markAsTestTrace();
        }
    }
}
