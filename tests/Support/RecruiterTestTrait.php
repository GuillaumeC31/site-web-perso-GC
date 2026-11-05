<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Profile;
use App\Entity\Recruiter;
use App\Entity\User;
use App\Enum\RecruiterStatus;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * Outils des tests de l'espace recruteur et du formulaire de contact : recruteur en base, profil avec
 * coordonnées privées, envoi d'un formulaire public avec un jeton Turnstile, lien extrait d'un e-mail.
 *
 * Liens :
 * - Utilisé par : tests/Controller/ContactControllerTest, RecruiterAreaControllerTest,
 *   tests/Controller/Admin/RecruiterAdminTest, tests/Command/*
 */
trait RecruiterTestTrait
{
    /** Téléphone privé du profil de test (ne doit jamais apparaître sur les pages publiques). */
    private const PRIVATE_PHONE = '06 12 34 56 78';

    /** E-mail privé du profil de test. */
    private const PRIVATE_EMAIL = 'prive@example.test';

    /** Adresse de notification du compte admin de test (reçoit les notifications par e-mail). */
    private const NOTIFICATION_EMAIL = 'notifications@example.test';

    /**
     * Vide les compteurs des limiteurs des formulaires publics (pool sur fichiers, partagé entre les tests).
     *
     * Liens :
     * - Appelée par : setUp() des tests des formulaires publics
     */
    private function clearPublicFormLimits(): void
    {
        static::getContainer()->get('cache.public_form_limiter')->clear();
    }

    /**
     * Crée le compte admin qui reçoit les notifications par e-mail (adresse NOTIFICATION_EMAIL) : sans lui,
     * le canal e-mail n'est pas configuré (adresse du compte admin, seule source depuis le 2026-10-26).
     *
     * Liens :
     * - Appelée par : setUp() des tests qui vérifient les notifications à l'admin
     */
    private function createNotifiedAdmin(): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User('admin_notifications'))->setRoles(['ROLE_ADMIN'])->setNotificationEmail(self::NOTIFICATION_EMAIL)->setPassword('!');
        $entityManager->persist($admin);
        $entityManager->flush();

        return $admin;
    }

    /**
     * Crée un recruteur.
     *
     * @param RecruiterStatus $status          statut
     * @param string|null     $accessExpiresAt échéance (« +30 days »…), null : aucune
     */
    private function createRecruiter(string $email = 'rh@acme.test', RecruiterStatus $status = RecruiterStatus::Approved, ?string $accessExpiresAt = '+30 days'): Recruiter
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $recruiter = (new Recruiter($now->modify('-1 day')))->setEmail($email)->setFullName('Camille Martin')->setCompany('Acme')
            ->setStatus($status)->setDecidedAt($now->modify('-1 day'))
            ->setAccessExpiresAt(null === $accessExpiresAt ? null : $now->modify($accessExpiresAt));
        $entityManager->persist($recruiter);
        $entityManager->flush();

        return $recruiter;
    }

    /**
     * Enregistre un profil avec des coordonnées privées et des prétentions.
     *
     * @param bool $showSalary afficher les prétentions dans l'espace recruteur
     */
    private function createPrivateProfile(bool $showSalary = false): Profile
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $profile = static::getContainer()->get(ProfileRepository::class)->getOrCreate()
            ->setPrivatePhone(self::PRIVATE_PHONE)->setPrivateEmail(self::PRIVATE_EMAIL)
            ->setPrivateSalaryExpectation('XX à YY k€')->setShowSalaryInRecruiterArea($showSalary);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
    }

    /**
     * Envoie un formulaire de la page avec un jeton Turnstile (le widget n'existe pas sans navigateur).
     *
     * @param Crawler               $crawler page contenant le formulaire
     * @param string                $button  texte du bouton d'envoi
     * @param array<string, string> $values  valeurs des champs (noms complets)
     * @param string                $token   jeton Turnstile (MockHttpResponseFactory::INVALID_TOKEN pour un refus)
     */
    private function submitPublicForm(KernelBrowser $client, Crawler $crawler, string $button, array $values, string $token = 'valid-token'): Crawler
    {
        $form = $crawler->selectButton($button)->form($values);
        $parameters = $form->getPhpValues();
        $parameters['cf-turnstile-response'] = $token;

        return $client->request($form->getMethod(), $form->getUri(), $parameters, [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Extrait le premier lien vers l'espace recruteur d'un e-mail (version texte).
     */
    private function extractRecruiterLink(Email $email): string
    {
        preg_match('#https?://\S+/espace-recruteur/\S+#', (string) $email->getTextBody(), $matches);
        self::assertNotEmpty($matches, 'Aucun lien vers l\'espace recruteur dans l\'e-mail.');

        return $matches[0];
    }
}
