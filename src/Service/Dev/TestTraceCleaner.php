<?php

declare(strict_types=1);

namespace App\Service\Dev;

use App\Command\E2eRecruiterJourneyCommand;
use App\Command\E2eRemoteDecisionCommand;
use Doctrine\DBAL\Connection;

/**
 * Retire de la base de développement les traces laissées par les tests de bout en bout, les captures et les
 * mesures (règle du 2026-10-26 : aucun test ne laisse de trace), sans toucher aux données réelles.
 *
 * Règle (décision du 2026-10-29) : seules les lignes identifiées PRÉCISÉMENT comme venant des tests sont
 * supprimées, jamais selon une plage horaire. Une action réelle faite pendant une suite (envoi d'e-mail, connexion,
 * message, question à l'assistant…) reste intacte. Repères :
 * - journal de sécurité : navigateur ou client de test (« HeadlessChrome », Lighthouse, « Playwright/ ») ou compte
 *   de test « e2e_axe » (TestContext) ;
 * - recruteurs, messages de contact, e-mails en échec : adresse du domaine des tests « example.test » ;
 * - échanges et consommation de l'assistant, questions sans réponse, historique des envois et des textes
 *   d'e-mails : colonne test_trace, posée à la création par EventListener\TestTraceListener (requête de test) ou
 *   par EmailLog (destinataire en example.test).
 * Les battements de cœur ne sont plus remis en état : un e-mail de test ne note plus « dernier e-mail parti »
 * (ResilientMailer), et aucune autre tâche planifiée ne tourne pendant une suite.
 *
 * Liens :
 * - Utilisé par : Command\E2eTracesCommand (tests/e2e/support/global-teardown.js, scripts de captures)
 * - Voir aussi : TestContext (mêmes repères), tests/e2e/support/global-teardown.js (e-mails Mailpit « tag:e2e-trace »)
 * - Testé par : tests/Service/Dev/TestTraceCleanerTest
 */
final readonly class TestTraceCleaner
{
    /** Tables dont les lignes de test portent la colonne test_trace. */
    private const MARKED_TABLES = ['chat_message', 'ai_usage', 'unanswered_question', 'email_log', 'email_text_revision'];

    /** Motifs SQL des adresses du domaine réservé aux tests (RFC 2606). */
    private const TEST_EMAIL_PATTERNS = ['%@'.TestContext::TEST_DOMAIN, '%.'.TestContext::TEST_DOMAIN];

    /**
     * Reçoit la connexion à la base.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Supprime les traces de test (repères précis seulement).
     *
     * Liens :
     * - Appelée par : E2eTracesCommand (action purge)
     *
     * @return array<string, int> table => lignes supprimées
     */
    public function purge(): array
    {
        $counts = [];
        foreach ($this->conditions() as $table => $condition) {
            $counts[$table] = (int) $this->connection->executeStatement('DELETE FROM '.$table.' WHERE '.$condition);
        }

        return $counts;
    }

    /**
     * Compte les traces de test restantes (vérification de fin de suite), dont le compte admin de test.
     *
     * Liens :
     * - Appelée par : E2eTracesCommand (action check), tests/Service/Dev/TestTraceCleanerTest
     *
     * @return array<string, int> table => traces restantes (0 partout : base propre)
     */
    public function count(): array
    {
        $counts = [];
        foreach ($this->conditions() as $table => $condition) {
            $counts[$table] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE '.$condition);
        }
        $counts['app_user'] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE username = ?', [TestContext::TEST_ADMIN]);
        $counts['reglages_fictifs'] = $this->countFictiveSettings();

        return $counts;
    }

    /**
     * Compte les réglages fictifs des suites restés actifs : code PIN fictif de la validation à distance, coordonnées
     * privées fictives du profil. Depuis la base dédiée aux tests (2026-10-29), ils ne doivent jamais apparaître dans la
     * base de développement : le contrôle de fin de suite le vérifie (lecture seule).
     *
     * @return int 0 si aucun réglage fictif n'est actif
     */
    private function countFictiveSettings(): int
    {
        $fictive = 0;
        foreach ($this->connection->fetchFirstColumn('SELECT remote_pin_hash FROM site_settings WHERE remote_pin_hash IS NOT NULL') as $hash) {
            if (password_verify(E2eRemoteDecisionCommand::FICTIVE_PIN, (string) $hash)) {
                ++$fictive;
            }
        }

        return $fictive + (int) $this->connection->fetchOne('SELECT COUNT(*) FROM profile WHERE private_email = ? OR private_phone = ?', [E2eRecruiterJourneyCommand::FICTIVE_EMAIL, E2eRecruiterJourneyCommand::FICTIVE_PHONE]);
    }

    /**
     * Condition SQL des lignes de test de chaque table, dans l'ordre de suppression.
     *
     * @return array<string, string> table => condition
     */
    private function conditions(): array
    {
        $conditions = ['security_event' => $this->securityCondition()];
        foreach (self::MARKED_TABLES as $table) {
            $conditions[$table] = 'test_trace = 1';
        }
        // Échanges, évènements et signaux des recruteurs de test : supprimés avec eux (cascade)
        $conditions['recruiter'] = $this->emailCondition('email');
        $conditions['contact_message'] = $this->emailCondition('email');
        $conditions['failed_email'] = $this->emailCondition('recipient');

        return $conditions;
    }

    /**
     * Condition des lignes du journal de sécurité produites par les tests.
     */
    private function securityCondition(): string
    {
        $parts = array_map(fn (string $marker): string => 'user_agent LIKE '.$this->connection->quote('%'.$marker.'%'), TestContext::TEST_USER_AGENTS);
        $parts[] = 'username = '.$this->connection->quote(TestContext::TEST_ADMIN);

        return '('.implode(' OR ', $parts).')';
    }

    /**
     * Condition « adresse du domaine de test » sur une colonne.
     */
    private function emailCondition(string $column): string
    {
        return '('.implode(' OR ', array_map(fn (string $pattern): string => $column.' LIKE '.$this->connection->quote($pattern), self::TEST_EMAIL_PATTERNS)).')';
    }
}
