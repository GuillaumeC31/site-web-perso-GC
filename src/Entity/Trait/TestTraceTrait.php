<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Repère « créée par un test » (colonne test_trace), pour les entités TestTraceableInterface.
 *
 * Liens :
 * - Utilisé par : ChatMessage, UnansweredQuestion, AiUsage, EmailLog, EmailTextRevision
 * - Voir aussi : EventListener\TestTraceListener, Service\Dev\TestTraceCleaner
 */
trait TestTraceTrait
{
    /** Vrai si la ligne a été créée par les tests de bout en bout (seul critère du nettoyage). */
    #[ORM\Column(options: ['default' => false])]
    private bool $testTrace = false;

    /** Indique si la ligne a été créée par un test. */
    public function isTestTrace(): bool
    {
        return $this->testTrace;
    }

    /** Marque la ligne comme créée par un test. */
    public function markAsTestTrace(): static
    {
        $this->testTrace = true;

        return $this;
    }
}
