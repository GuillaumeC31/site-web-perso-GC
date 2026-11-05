<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AiUsage;
use App\Entity\ChatMessage;
use App\Entity\ContactMessage;
use App\Entity\Recruiter;
use App\Entity\RecruiterEvent;
use App\Enum\AiPurpose;
use App\Enum\ChatOutcome;
use App\Enum\ContactSubject;
use App\Enum\RecruiterEventType;
use App\Enum\RecruiterStatus;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Commandes quotidiennes de l'espace recruteur (lot 6), lancées par le cron : rappel 7 jours avant
 * l'échéance, accès échus, récapitulatif des signaux d'intérêt, purge RGPD.
 *
 * Liens :
 * - Couvre : RecruiterDailyCommand (app:recruiter:daily), DataPurgeCommand (app:data:purge),
 *   InterestSignalNotifier::sendDigest()
 */
final class RecruiterCommandsTest extends KernelTestCase
{
    use RecruiterTestTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->createNotifiedAdmin();
    }

    /**
     * Vérifie le rappel (une seule fois, avec un lien de prolongation), le passage en « échu » et le récapitulatif.
     */
    public function testDailyCommandSendsRemindersExpiresAndDigests(): void
    {
        $soon = $this->createRecruiter('bientot@acme.test', RecruiterStatus::Approved, '+5 days');
        $this->createRecruiter('tranquille@acme.test', RecruiterStatus::Approved, '+20 days');
        $expired = $this->createRecruiter('echu@acme.test', RecruiterStatus::Approved, '-1 hour');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new RecruiterEvent($soon, RecruiterEventType::CvDownload, new \DateTimeImmutable('-1 day')));
        $entityManager->flush();

        self::assertStringContainsString('1 rappel(s) d\'échéance, 1 accès échu(s), 1 récapitulatif(s)', $this->executeCommand('app:recruiter:daily'));
        self::assertEmailCount(2);
        $reminder = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $reminder);
        self::assertEmailAddressContains($reminder, 'To', 'bientot@acme.test');
        self::assertStringContainsString('/espace-recruteur/prolongation/', $this->extractRecruiterLink($reminder));
        self::assertCount(1, MockHttpResponseFactory::requestsTo('discord'));
        // Récapitulatif de l'activité : jamais titré « Signal d'intérêt » (v2.0.3)
        $content = (string) json_decode(MockHttpResponseFactory::requestsTo('discord')[0]['body'], true)['content'];
        self::assertStringContainsString('Activité d\'un recruteur', $content);
        self::assertStringNotContainsString('Signal d\'intérêt', $content);

        $entityManager->clear();
        self::assertSame(RecruiterStatus::Expired, $entityManager->find(Recruiter::class, $expired->getId())?->getStatus());
        self::assertStringContainsString('0 rappel(s) d\'échéance, 0 accès échu(s), 0 récapitulatif(s)', $this->executeCommand('app:recruiter:daily'));
    }

    /**
     * Vérifie la purge : refus de plus de 30 jours, comptes inactifs depuis 12 mois, messages de plus
     * de 12 mois, journal de l'assistant IA de plus de 12 mois, adresses IP de plus de 30 jours ; un accès
     * ouvert est conservé.
     */
    public function testPurgeAppliesRetentionRules(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $make = static fn (string $email, RecruiterStatus $status, string $since): Recruiter => (new Recruiter(new \DateTimeImmutable($since)))
            ->setEmail($email)->setFullName('Camille Martin')->setCompany('Acme')->setStatus($status)->setIpAddress('198.51.100.7');
        $recruiters = [
            'refused_old' => $make('refus@acme.test', RecruiterStatus::Refused, '-40 days'),
            'refused_recent' => $make('refus-recent@acme.test', RecruiterStatus::Refused, '-10 days'),
            'expired_old' => $make('ancien@acme.test', RecruiterStatus::Expired, '-13 months'),
            'approved_old' => $make('ouvert@acme.test', RecruiterStatus::Approved, '-13 months'),
        ];
        $oldMessage = (new ContactMessage(new \DateTimeImmutable('-13 months')))->setName('Jean')->setEmail('jean@acme.test')
            ->setSubject(ContactSubject::Other)->setMessage('Un ancien message.')->setConsentAt(new \DateTimeImmutable('-13 months'));
        $chatMessages = [
            new ChatMessage($recruiters['approved_old'], new \DateTimeImmutable('-13 months'), 'Ancienne question', 'Ancienne réponse', ChatOutcome::Guided),
            new ChatMessage($recruiters['approved_old'], new \DateTimeImmutable('-1 month'), 'Question récente', 'Réponse récente', ChatOutcome::Guided),
        ];
        $aiUsages = [
            new AiUsage(new \DateTimeImmutable('-13 months'), AiPurpose::Chatbot, 'modele-principal-test', 2800, 60),
            new AiUsage(new \DateTimeImmutable('-1 month'), AiPurpose::Chatbot, 'modele-principal-test', 2800, 60),
        ];
        foreach ([...$recruiters, $oldMessage, ...$chatMessages, ...$aiUsages] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $ids = array_map(static fn (Recruiter $r): ?int => $r->getId(), $recruiters);

        self::assertStringContainsString('2 recruteur(s) et 1 message(s) supprimés, 1 échange(s) et 1 ligne(s) de consommation de l\'assistant supprimés, 1 adresse(s) IP effacée(s)', $this->executeCommand('app:data:purge'));
        $entityManager->clear();
        self::assertNull($entityManager->find(Recruiter::class, $ids['refused_old']));
        self::assertNull($entityManager->find(Recruiter::class, $ids['expired_old']));
        self::assertNull($entityManager->find(Recruiter::class, $ids['approved_old'])?->getIpAddress());
        self::assertSame('198.51.100.7', $entityManager->find(Recruiter::class, $ids['refused_recent'])?->getIpAddress());
        self::assertSame(0, $entityManager->getRepository(ContactMessage::class)->count([]));
        self::assertSame(1, $entityManager->getRepository(ChatMessage::class)->count([]));
        self::assertSame(1, $entityManager->getRepository(AiUsage::class)->count([]));
    }

    /**
     * Lance une commande et renvoie sa sortie.
     */
    private function executeCommand(string $name): string
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find($name));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
