<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Ligne qui peut être créée par les tests de bout en bout et porte alors un repère précis (colonne test_trace) :
 * le nettoyage de fin de suite ne supprime QUE ces lignes, jamais selon une plage horaire (décision du
 * 2026-10-29 : les actions réelles faites pendant une suite restent intactes).
 *
 * Liens :
 * - Implémentée par : ChatMessage, UnansweredQuestion, AiUsage, EmailLog, EmailTextRevision (Trait\TestTraceTrait)
 * - Marquée par : EventListener\TestTraceListener (requête de test, Service\Dev\TestContext)
 * - Nettoyée par : Service\Dev\TestTraceCleaner
 */
interface TestTraceableInterface
{
    /**
     * Indique si la ligne a été créée par un test.
     */
    public function isTestTrace(): bool;

    /**
     * Marque la ligne comme créée par un test.
     *
     * Liens :
     * - Appelée par : EventListener\TestTraceListener::prePersist()
     */
    public function markAsTestTrace(): static;
}
