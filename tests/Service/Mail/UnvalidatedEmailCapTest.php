<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Heartbeat;
use App\Entity\Recruiter;
use App\Enum\RecruiterStatus;
use App\Repository\HeartbeatRepository;
use App\Repository\RecruiterRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Dashboard\DashboardMetrics;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Service\Recruiter\RecruiterMailer;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

/**
 * Plafond quotidien des e-mails aux personnes non validées (décision du 2026-10-29) : accusés, « demande déjà en cours »
 * et refus suspendus au-delà du plafond jusqu'au lendemain, demandes toujours enregistrées, alerte « Général du site »
 * une seule fois par jour, tuile Communication en problème ; recruteurs validés et notifications jamais bloqués.
 *
 * Liens :
 * - Couvre : Mail\UnvalidatedEmailCap, RecruiterMailer::send(), EmailLogRepository::countKindsSince(),
 *   DashboardMetrics::communication()
 */
final class UnvalidatedEmailCapTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RecruiterTestTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        self::mockTime('2026-11-02 10:00:00');
        self::bootKernel();
        $this->createNotifiedAdmin();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setUnvalidatedEmailDailyCap(2));
        $entityManager->flush();
    }

    /**
     * Vérifie la suspension au-delà du plafond : demandes enregistrées, une seule alerte par jour, tuile en problème.
     */
    public function testEmailsBeyondTheCapAreSuspendedButRequestsAreKept(): void
    {
        foreach (['un', 'deux', 'trois', 'quatre'] as $name) {
            $this->requestAccess($name.'@acme.example');
        }

        self::assertCount(4, static::getContainer()->get(RecruiterRepository::class)->findBy(['status' => RecruiterStatus::Pending]), 'Demandes toujours enregistrées.');
        self::assertSame(['un@acme.test', 'deux@acme.test'], $this->acknowledgementRecipients());
        $alerts = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && str_contains((string) $m->getSubject(), 'Plafond')));
        self::assertCount(1, $alerts, 'Alerte « Général du site » envoyée une seule fois.');
        self::assertCount(1, array_filter(MockHttpResponseFactory::requestsTo('discord'), static fn (array $r): bool => str_contains((string) json_encode($r), 'Plafond')));

        $heartbeat = static::getContainer()->get(HeartbeatRepository::class)->findAllByName()[Heartbeat::EMAIL_CAP] ?? null;
        self::assertSame(['day' => '2026-11-02', 'suspended' => 2], $heartbeat?->getDetails());

        $tile = array_column(static::getContainer()->get(DashboardMetrics::class)->getTiles(true)['tiles'], null, 'id')['communication'];
        self::assertSame(DashboardMetrics::PROBLEM, $tile['status']);
        self::assertContains('admin.dashboard.line.email_cap_reached', array_column($tile['lines'], 'key'));
    }

    /**
     * Vérifie que le refus est aussi plafonné, mais jamais les e-mails aux recruteurs validés (lien de connexion,
     * validation).
     */
    public function testValidatedRecruitersAreNeverBlocked(): void
    {
        $this->requestAccess('un@acme.test');
        $this->requestAccess('deux@acme.test');
        $mailer = static::getContainer()->get(RecruiterMailer::class);
        $refused = $this->createRecruiter('refus@acme.test', RecruiterStatus::Pending, null);
        self::assertFalse($mailer->sendRefusal($refused), 'Refus suspendu au-delà du plafond.');

        $approved = $this->createRecruiter('valide@acme.test');
        self::assertTrue($mailer->sendApproval($approved, false));
        self::assertTrue($mailer->sendLoginLink($approved));
    }

    /**
     * Vérifie la reprise le lendemain et que les adresses des tests de bout en bout ne comptent pas.
     */
    public function testCapResetsTheNextDayAndIgnoresTestAddresses(): void
    {
        foreach (['un@e2e-parcours.example.test', 'deux@e2e-parcours.example.test', 'trois@e2e-parcours.example.test'] as $email) {
            $this->requestAccess($email);
        }
        $this->requestAccess('un@acme.test');
        $this->requestAccess('deux@acme.test');
        $this->requestAccess('trois@acme.test');
        self::assertNotContains('trois@acme.test', $this->acknowledgementRecipients());

        self::mockTime('2026-11-03 08:00:00');
        $this->requestAccess('quatre@acme.test');
        self::assertContains('quatre@acme.test', $this->acknowledgementRecipients());
    }

    /**
     * Envoie une demande d'accès (comme le formulaire public).
     */
    private function requestAccess(string $email): void
    {
        $data = (new Recruiter(new \DateTimeImmutable()))->setEmail($email)->setFullName('Camille Martin')->setCompany('Acme')->setConsentAt(new \DateTimeImmutable());
        static::getContainer()->get(RecruiterAccessManager::class)->requestAccess($data, '198.51.100.7');
    }

    /**
     * Destinataires des accusés de réception envoyés.
     *
     * @return list<string>
     */
    private function acknowledgementRecipients(): array
    {
        $recipients = [];
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && str_contains((string) $message->getSubject(), 'demande') && !str_contains((string) $message->getSubject(), 'Nouvelle')) {
                $recipients[] = $message->getTo()[0]->getAddress();
            }
        }

        return array_values(array_filter($recipients, static fn (string $address): bool => !str_starts_with($address, 'notifications@')));
    }
}
