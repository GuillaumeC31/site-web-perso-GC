<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\SiteSettings;
use App\Entity\User;
use App\Enum\MailCategory;
use App\Enum\NotificationEvent;
use App\Form\CommunicationAddressesType;
use App\Repository\SiteSettingsRepository;
use App\Service\Mail\MailRecipients;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Adresses de réception par catégorie (décision du 2026-10-29) : routage des événements, repli de la Calculatrice
 * sur « Général du site », repli sur le compte admin, adresses fictives refusées en production, problèmes signalés
 * au déploiement.
 *
 * Liens :
 * - Couvre : App\Service\Mail\MailRecipients, NotificationEvent::category(), CommunicationAddressesType (reject_fictitious)
 */
final class MailRecipientsTest extends KernelTestCase
{
    /**
     * Vérifie le routage : chaque événement appartient à une catégorie, chaque catégorie a son adresse.
     */
    public function testEventsAreRoutedByCategory(): void
    {
        self::bootKernel();
        $this->saveAddresses('recrutement@example.test', 'general@example.test', null);
        $recipients = static::getContainer()->get(MailRecipients::class);

        foreach ([NotificationEvent::AccessRequest, NotificationEvent::Contact, NotificationEvent::Interest, NotificationEvent::Extension, NotificationEvent::DataDeletion] as $event) {
            self::assertSame(MailCategory::Recruitment, $event->category(), $event->value);
            self::assertSame('recrutement@example.test', $recipients->addressFor($event->category()));
        }
        foreach ([NotificationEvent::SecurityAlert, NotificationEvent::FailedEmail, NotificationEvent::TaskLate, NotificationEvent::AiAlert, NotificationEvent::Maintenance] as $event) {
            self::assertSame(MailCategory::General, $event->category(), $event->value);
            self::assertSame('general@example.test', $recipients->addressFor($event->category()));
        }
    }

    /**
     * Vérifie le repli de la Calculatrice sur « Général du site » tant qu'elle est vide, puis son adresse propre.
     */
    public function testCalculatorFallsBackOnGeneral(): void
    {
        self::bootKernel();
        $this->saveAddresses('recrutement@example.test', 'general@example.test', null);
        $recipients = static::getContainer()->get(MailRecipients::class);
        self::assertSame('general@example.test', $recipients->addressFor(MailCategory::Calculator));
        self::assertSame('general', $recipients->sourceOf(MailCategory::Calculator));

        $this->saveAddresses('recrutement@example.test', 'general@example.test', 'calcul@example.test');
        self::assertSame('calcul@example.test', $recipients->addressFor(MailCategory::Calculator));
        self::assertSame('saved', $recipients->sourceOf(MailCategory::Calculator));
    }

    /**
     * Vérifie le repli sur l'adresse du compte admin, puis l'absence d'adresse signalée au déploiement.
     */
    public function testFallbackOnAdminAccountAndProblems(): void
    {
        self::bootKernel();
        $recipients = static::getContainer()->get(MailRecipients::class);
        self::assertNull($recipients->addressFor(MailCategory::Recruitment));
        self::assertSame(['recruitment' => 'missing', 'general' => 'missing'], $recipients->findProblems());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new User('admin_adresses'))->setRoles(['ROLE_ADMIN'])->setNotificationEmail('admin@example.test')->setPassword('!'));
        $entityManager->flush();
        self::assertSame('admin@example.test', $recipients->addressFor(MailCategory::General));
        self::assertSame('account', $recipients->sourceOf(MailCategory::General));
        self::assertSame(['recruitment' => 'fictitious', 'general' => 'fictitious'], $recipients->findProblems());

        $this->saveAddresses('recrutement@example.test', 'general@example.test', null);
        self::assertSame([], $recipients->findProblems());
    }

    /**
     * Vérifie la reconnaissance des adresses fictives.
     */
    #[DataProvider('addresses')]
    public function testFictitiousAddresses(string $address, bool $fictitious): void
    {
        self::assertSame($fictitious, MailRecipients::isFictitious($address));
    }

    /**
     * Adresses fictives et réelles.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'example.test' => ['notifications@example.test', true];
        yield 'example.com' => ['jean@example.com', true];
        yield 'sous-domaine' => ['rh@e2e.example.com', true];
        yield 'localhost' => ['root@localhost', true];
        yield 'suffixe .test' => ['rh@acme.test', true];
        yield 'webmail' => ['contact-0937@webmail.test', false];
        yield 'domaine du site' => ['noreply@example.test', false];
    }

    /**
     * Vérifie le formulaire : adresses fictives refusées en production (option reject_fictitious), acceptées
     * ailleurs.
     */
    public function testFictitiousAddressesAreRefusedInProduction(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(FormFactoryInterface::class);
        $data = ['recruitmentEmail' => 'rh@example.test', 'generalEmail' => 'contact-0937@webmail.test', 'calculatorEmail' => '', 'mailFromName' => '', 'dkimSelector' => 'default'];

        $production = $factory->create(CommunicationAddressesType::class, new SiteSettings(), ['reject_fictitious' => true, 'csrf_protection' => false]);
        $production->submit($data);
        self::assertFalse($production->isValid());
        self::assertCount(1, $production->get('recruitmentEmail')->getErrors());
        self::assertCount(0, $production->get('generalEmail')->getErrors());

        $local = $factory->create(CommunicationAddressesType::class, new SiteSettings(), ['csrf_protection' => false]);
        $local->submit($data);
        self::assertTrue($local->isValid());
    }

    /**
     * Enregistre les trois adresses.
     */
    private function saveAddresses(?string $recruitment, ?string $general, ?string $calculator): void
    {
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()
            ->setRecruitmentEmail($recruitment)->setGeneralEmail($general)->setCalculatorEmail($calculator);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();
    }
}
