<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Enum\RecruiterStatus;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Recruiter\AccessRequestSummary;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Adresse du recruteur dans les notifications (v2.0.3) : COMPLÈTE et cliquable (mailto) dans l'e-mail envoyé à
 * Guillaume ; masquée (domaine seulement) sur Discord et Telegram, comme avant.
 *
 * Liens :
 * - Couvre : Service\Notification\Notification::emailLines(), templates/email/notification.html.twig et .txt.twig,
 *   Service\Recruiter\AccessRequestSummary (demande d'accès, signal d'intérêt)
 */
final class RecruiterEmailContactTest extends WebTestCase
{
    use AdminTestTrait;
    use RecruiterTestTrait;

    /** Adresse du recruteur fictif. */
    private const EMAIL = 'camille.rh@acme.test';

    /**
     * Remet les réponses simulées à zéro.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Demande d'accès et signal d'intérêt : adresse complète dans l'e-mail (texte et HTML, lien mailto), jamais sur
     * Discord ni Telegram.
     */
    public function testFullAddressInEmailOnly(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $summary = $container->get(AccessRequestSummary::class);
        // Adresse de réception : celle du compte admin (repli final de MailRecipients)
        $this->createAdmin();
        $recruiter = $this->createRecruiter(self::EMAIL, RecruiterStatus::Pending, null);

        foreach ([$summary->notification($recruiter), $summary->interestNotification($recruiter, ['Je suis intéressé (05/11 15:00)'])] as $notification) {
            MockHttpResponseFactory::reset();
            $container->get(NotificationDispatcher::class)->notify($notification);

            // Hors requête HTTP : messages lus dans l'enregistreur du mailer
            $messages = $container->get('mailer.message_logger_listener')->getEvents()->getMessages();
            $emails = array_values(array_filter($messages, static fn ($m): bool => $m instanceof Email));
            $email = end($emails);
            self::assertInstanceOf(Email::class, $email, $notification->title.' : e-mail envoyé');
            self::assertStringContainsString('E-mail : '.self::EMAIL, (string) $email->getTextBody());
            self::assertStringContainsString('href="mailto:'.self::EMAIL.'"', (string) $email->getHtmlBody());

            $chat = implode("\n", array_map(static fn (array $r): string => $r['body'], [...MockHttpResponseFactory::requestsTo('discord'), ...MockHttpResponseFactory::requestsTo('api.telegram.org')]));
            self::assertNotSame('', $chat, $notification->title.' : messageries appelées');
            self::assertStringNotContainsString('camille.rh', $chat, $notification->title.' : adresse jamais sur Discord ni Telegram');
            self::assertStringNotContainsString(rawurlencode(self::EMAIL), $chat);
            self::assertStringContainsString('acme.example', $chat, 'Domaine seulement sur les messageries.');
        }
    }

    /**
     * Les lignes des messageries gardent le domaine seul ; les lignes de l'e-mail mettent l'adresse au même rang.
     */
    public function testEmailLinesReplaceDomainInPlace(): void
    {
        self::bootKernel();
        $recruiter = $this->createRecruiter(self::EMAIL, RecruiterStatus::Pending, null);
        $notification = self::getContainer()->get(AccessRequestSummary::class)->notification($recruiter);

        self::assertSame('@acme.example', $notification->lines['E-mail']);
        self::assertSame(array_keys($notification->lines), array_keys($notification->emailLines()));
        self::assertSame(self::EMAIL, $notification->emailLines()['E-mail']);
    }
}
