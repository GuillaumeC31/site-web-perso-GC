<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\EmailLog;
use App\Enum\EmailType;
use App\Enum\MailCategory;
use App\Repository\EmailLogRepository;
use App\Service\Mail\MailFactory;
use App\Service\Mail\ResilientMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Historique des envois : destinataire masqué, catégorie, jamais le contenu ni les liens ; purge après 90 jours par
 * app:data:purge.
 *
 * Liens :
 * - Couvre : App\Entity\EmailLog, ResilientMailer (écriture), EmailLogRepository, Command\DataPurgeCommand
 */
final class EmailLogTest extends KernelTestCase
{
    /**
     * Vérifie le masquage des adresses.
     */
    public function testAddressesAreMasked(): void
    {
        self::assertSame('j***@entreprise.fr', EmailLog::maskAddress('jean.dupont@entreprise.test'));
        self::assertSame('a***@b.fr', EmailLog::maskAddress('a@b.test'));
        self::assertSame('***', EmailLog::maskAddress('sans-arobase'));
    }

    /**
     * Vérifie qu'un envoi est noté (type, catégorie, adresse masquée) sans aucun contenu.
     */
    public function testSendingIsLogged(): void
    {
        self::bootKernel();
        $email = static::getContainer()->get(MailFactory::class)->createTyped(EmailType::Test, 'contact-0937@webmail.test', ['sent_at' => new \DateTimeImmutable(), 'transport' => 'null', 'category_label' => 'Général du site']);
        static::getContainer()->get(ResilientMailer::class)->send($email, ResilientMailer::KIND_TEST, category: MailCategory::General);

        $logs = static::getContainer()->get(EmailLogRepository::class)->findLatest();
        self::assertCount(1, $logs);
        self::assertSame('g***@gmail.com', $logs[0]->getRecipient());
        self::assertSame(MailCategory::General, $logs[0]->getCategory());
        self::assertSame('test', $logs[0]->getKind());
        self::assertTrue($logs[0]->isSuccess());
        self::assertNotNull(static::getContainer()->get(EmailLogRepository::class)->findLastSuccessAt());
        // Catégorie déduite de la nature pour les e-mails aux recruteurs
        self::assertSame(MailCategory::Recruitment, ResilientMailer::categoryOfKind(ResilientMailer::KIND_LOGIN_LINK));
    }

    /**
     * Vérifie la purge : plus de 90 jours supprimés, le reste gardé.
     */
    public function testPurgeAfterNinetyDays(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new EmailLog(new \DateTimeImmutable('-91 days'), 'test', MailCategory::General, 'vieux@exemple.test', true));
        $entityManager->persist(new EmailLog(new \DateTimeImmutable('-89 days'), 'test', MailCategory::General, 'recent@exemple.test', true));
        $entityManager->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:data:purge'));
        $tester->execute([]);
        self::assertStringContainsString('1 envoi(s) d\'e-mail retirés de l\'historique', $tester->getDisplay());
        $logs = static::getContainer()->get(EmailLogRepository::class)->findLatest();
        self::assertSame(['r***@exemple.fr'], array_map(static fn (EmailLog $log): string => $log->getRecipient(), $logs));
    }
}
