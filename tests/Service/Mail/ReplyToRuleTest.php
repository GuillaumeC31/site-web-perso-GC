<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Recruiter;
use App\Enum\EmailType;
use App\Repository\SiteSettingsRepository;
use App\Service\Mail\MailFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Règle de confidentialité de l'adresse de réponse (décision du 2026-10-29) : l'adresse « Recrutement » n'est mise
 * en Reply-To QUE sur les e-mails aux recruteurs VALIDÉS ; les e-mails aux demandes non validées n'ont aucune
 * adresse de réponse, renvoient vers le formulaire de contact et ne montrent aucun webmail. MAILER_REPLY_TO
 * (reponse@example.test en test) n'est qu'un repli si le champ est vide.
 *
 * Liens :
 * - Couvre : App\Service\Mail\MailFactory::createTyped(), EmailType, templates/email/_layout.html.twig,
 *   templates/email/_text_footer.txt.twig
 */
final class ReplyToRuleTest extends KernelTestCase
{
    private const RECRUITMENT = 'contact-0c39@webmail.test';

    /**
     * Vérifie les e-mails aux recruteurs validés : Reply-To = adresse « Recrutement ».
     */
    public function testValidatedRecruitersCanReply(): void
    {
        self::bootKernel();
        $this->saveRecruitmentAddress(self::RECRUITMENT);
        foreach ([EmailType::LoginLink, EmailType::Approval, EmailType::Renewal, EmailType::ExpiryReminder] as $type) {
            $email = $this->render($type);
            self::assertSame([self::RECRUITMENT], array_map(static fn ($a): string => $a->getAddress(), $email->getReplyTo()), $type->value);
            self::assertStringContainsString('Vous pouvez répondre à cet e-mail.', (string) $email->getTextBody(), $type->value);
            self::assertStringNotContainsString('formulaire de contact', (string) $email->getTextBody(), $type->value);
        }
    }

    /**
     * Vérifie les e-mails aux demandes non validées : aucune adresse de réponse, formulaire de contact, aucun webmail.
     */
    public function testUnvalidatedRequestsHaveNoReplyTo(): void
    {
        self::bootKernel();
        $this->saveRecruitmentAddress(self::RECRUITMENT);
        foreach ([EmailType::RequestReceived, EmailType::RequestPending, EmailType::Refusal] as $type) {
            $email = $this->render($type);
            self::assertSame([], $email->getReplyTo(), $type->value);
            foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
                self::assertStringContainsString('Pour écrire à Guillaume, utilisez le formulaire de contact du site', $body, $type->value);
                self::assertStringContainsString('/contact', $body, $type->value);
                self::assertStringNotContainsString('gmail', strtolower($body), $type->value);
                self::assertStringNotContainsString('Vous pouvez répondre', $body, $type->value);
            }
            self::assertSame('noreply@example.test', $email->getFrom()[0]->getAddress());
        }
    }

    /**
     * Vérifie le repli sur MAILER_REPLY_TO quand l'adresse « Recrutement » est vide (validés seulement).
     */
    public function testReplyToFallsBackOnEnvironment(): void
    {
        self::bootKernel();
        $this->saveRecruitmentAddress(null);
        self::assertSame('reponse@example.test', $this->render(EmailType::LoginLink)->getReplyTo()[0]->getAddress());
        self::assertSame([], $this->render(EmailType::RequestReceived)->getReplyTo());
    }

    /**
     * Crée et rend un e-mail d'un type, avec un recruteur d'exemple.
     */
    private function render(EmailType $type): \Symfony\Bridge\Twig\Mime\TemplatedEmail
    {
        $recruiter = (new Recruiter(new \DateTimeImmutable()))->setEmail('rh@acme.test')->setFullName('Camille Martin')->setCompany('Acme')
            ->setAccessExpiresAt(new \DateTimeImmutable('+90 days'));
        $email = static::getContainer()->get(MailFactory::class)->createTyped($type, 'rh@acme.test', [
            'recruiter' => $recruiter, 'url' => 'https://localhost/lien', 'minutes' => 30, 'renewed' => EmailType::Renewal === $type, 'extension_url' => 'https://localhost/prolonger', 'stop_reminders_url' => 'https://localhost/rappels',
        ], ['nom' => 'Camille Martin', 'entreprise' => 'Acme', 'delai' => 'sous 24 h ouvrées']);
        static::getContainer()->get(BodyRendererInterface::class)->render($email);

        return $email;
    }

    /**
     * Enregistre l'adresse « Recrutement ».
     */
    private function saveRecruitmentAddress(?string $address): void
    {
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setRecruitmentEmail($address);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();
    }
}
