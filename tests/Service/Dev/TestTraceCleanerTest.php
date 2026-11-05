<?php

declare(strict_types=1);

namespace App\Tests\Service\Dev;

use App\Service\Dev\TestTraceCleaner;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nettoyage des traces des tests de bout en bout : seules les lignes repérées précisément (navigateur ou compte de
 * test, adresse en example.test, colonne test_trace) sont supprimées, jamais selon une date (décision du
 * 2026-10-29). Une action réelle faite PENDANT une suite reste intacte.
 *
 * Liens :
 * - Couvre : App\Service\Dev\TestTraceCleaner
 */
final class TestTraceCleanerTest extends KernelTestCase
{
    /** Date « pendant la suite » : traces de test et actions réelles mêlées, à la même minute. */
    private const DURING_SUITE = '2026-10-29 16:11:00';

    /**
     * Vérifie que chaque ligne réelle créée pendant la suite est gardée, et que chaque trace de test est supprimée.
     */
    public function testRealRowsCreatedDuringTheSuiteAreKept(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $cleaner = new TestTraceCleaner($db);
        $at = self::DURING_SUITE;

        // Actions réelles, faites pendant la suite (même minute que les traces de test)
        $db->insert('security_event', ['occurred_at' => $at, 'type' => 'password_failure', 'username' => 'admin', 'ip_address' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Edg/140']);
        $db->insert('ai_usage', ['created_at' => $at, 'purpose' => 'chatbot', 'model' => 'ministral', 'prompt_tokens' => 100, 'completion_tokens' => 30, 'test_trace' => 0]);
        $db->insert('recruiter', $this->recruiterRow('vrai@pomponette.test'));
        $db->insert('contact_message', $this->messageRow('vrai@pomponette.test'));
        $db->insert('chat_message', ['created_at' => $at, 'question' => 'Essai réel', 'answer' => 'R', 'outcome' => 'ai_primary', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'admin_test' => 1, 'test_trace' => 0]);
        $db->insert('unanswered_question', ['created_at' => $at, 'question' => 'Question réelle', 'reason' => 'missing', 'status' => 'pending', 'test_trace' => 0]);
        $db->insert('email_log', ['sent_at' => $at, 'kind' => 'recruiter_request_received', 'category' => 'recruitment', 'recipient' => 'v***@pomponette.fr', 'success' => 1, 'test_trace' => 0]);
        $db->insert('email_text_revision', ['type' => 'refusal', 'action' => 'saved', 'subject' => 'Texte réel', 'intro' => null, 'signature' => null, 'saved_at' => $at, 'test_trace' => 0]);
        $db->insert('failed_email', ['created_at' => $at, 'kind' => 'notification', 'recipient' => 'contact-0937@webmail.test', 'subject' => 'Objet', 'payload' => 'x', 'attempts' => 1, 'last_error' => '']);

        // Traces de test, repérées précisément
        $db->insert('security_event', ['occurred_at' => $at, 'type' => 'password_failure', 'username' => null, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0 HeadlessChrome/140']);
        $db->insert('security_event', ['occurred_at' => $at, 'type' => 'remote_decision_rejected', 'username' => 'discord used #1', 'ip_address' => '127.0.0.1', 'user_agent' => 'Playwright/1.64.1 (x64; windows 10.0) node/24.20']);
        $db->insert('ai_usage', ['created_at' => $at, 'purpose' => 'admin_test', 'model' => 'ministral', 'prompt_tokens' => 100, 'completion_tokens' => 30, 'test_trace' => 1]);
        $db->insert('recruiter', $this->recruiterRow('parcours@e2e-parcours.example.test'));
        $db->insert('contact_message', $this->messageRow('contact@e2e-parcours.example.test'));
        $db->insert('chat_message', ['created_at' => $at, 'question' => 'Question de test', 'answer' => 'R', 'outcome' => 'ai_primary', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'admin_test' => 1, 'test_trace' => 1]);
        $db->insert('unanswered_question', ['created_at' => $at, 'question' => 'Q de test', 'reason' => 'missing', 'status' => 'pending', 'test_trace' => 1]);
        $db->insert('email_log', ['sent_at' => $at, 'kind' => 'test', 'category' => 'general', 'recipient' => 'g***@gmail.com', 'success' => 1, 'test_trace' => 1]);
        $db->insert('email_text_revision', ['type' => 'refusal', 'action' => 'reset', 'subject' => null, 'intro' => null, 'signature' => null, 'saved_at' => $at, 'test_trace' => 1]);
        $db->insert('failed_email', ['created_at' => $at, 'kind' => 'notification', 'recipient' => 'rh@e2e-parcours.example.test', 'subject' => 'Objet', 'payload' => 'x', 'attempts' => 1, 'last_error' => '']);

        self::assertNotSame([], array_filter($cleaner->count()));
        $cleaner->purge();
        self::assertSame([], array_filter($cleaner->count()), 'Aucune trace restante.');

        // Chaque ligne réelle est intacte
        $kept = [
            "SELECT COUNT(*) FROM security_event WHERE username = 'admin'",
            'SELECT COUNT(*) FROM ai_usage WHERE test_trace = 0',
            "SELECT COUNT(*) FROM recruiter WHERE email = 'vrai@pomponette.test'",
            "SELECT COUNT(*) FROM contact_message WHERE email = 'vrai@pomponette.test'",
            "SELECT COUNT(*) FROM chat_message WHERE question = 'Essai réel'",
            "SELECT COUNT(*) FROM unanswered_question WHERE question = 'Question réelle'",
            "SELECT COUNT(*) FROM email_log WHERE recipient = 'v***@pomponette.fr'",
            "SELECT COUNT(*) FROM email_text_revision WHERE subject = 'Texte réel'",
            "SELECT COUNT(*) FROM failed_email WHERE recipient = 'contact-0937@webmail.test'",
        ];
        foreach ($kept as $query) {
            self::assertSame(1, (int) $db->fetchOne($query), $query);
        }
        foreach (['security_event' => 1, 'ai_usage' => 1, 'recruiter' => 1, 'contact_message' => 1, 'chat_message' => 1, 'unanswered_question' => 1, 'email_log' => 1, 'email_text_revision' => 1, 'failed_email' => 1] as $table => $count) {
            self::assertSame($count, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
    }

    /**
     * Vérifie que les battements de cœur ne sont plus touchés (aucune remise en état selon une date).
     */
    public function testHeartbeatsAreNeverTouched(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->insert('heartbeat', ['name' => 'mail_sent', 'last_run_at' => self::DURING_SUITE, 'success' => 1, 'details' => '{"kind":"recruiter_request_received"}']);

        (new TestTraceCleaner($db))->purge();
        self::assertSame(self::DURING_SUITE, $db->fetchOne("SELECT last_run_at FROM heartbeat WHERE name = 'mail_sent'"));
    }

    /**
     * Ligne minimale d'un recruteur.
     *
     * @return array<string, mixed>
     */
    private function recruiterRow(string $email): array
    {
        return [
            'email' => $email, 'full_name' => 'Test', 'company' => 'Test', 'status' => 'pending', 'requested_at' => self::DURING_SUITE,
            'last_activity_at' => self::DURING_SUITE, 'score' => 0, 'scoring_details' => '[]',
            'created_manually' => 0,
        ];
    }

    /**
     * Ligne minimale d'un message de contact.
     *
     * @return array<string, mixed>
     */
    private function messageRow(string $email): array
    {
        return ['name' => 'Test', 'email' => $email, 'subject' => 'other', 'message' => 'Message de test assez long', 'status' => 'new', 'created_at' => self::DURING_SUITE];
    }
}
