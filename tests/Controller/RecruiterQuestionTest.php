<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ChatMessage;
use App\Entity\Recruiter;
use App\Enum\ChatOutcome;
use App\Enum\ContactSource;
use App\Enum\ContactSubject;
use App\Repository\ContactMessageRepository;
use App\Service\Ai\ChatGuard;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Formulaire de question privé de l'espace recruteur : lien sous une réponse « Ce point n'est pas précisé »,
 * question de l'assistant reprise (seulement pour ses propres échanges), message enregistré pour l'admin
 * avec le nom et l'entreprise du recruteur, notification ; réservé aux recruteurs connectés.
 *
 * Liens :
 * - Couvre : RecruiterQuestionController, templates/recruiter_area/question.html.twig,
 *   templates/recruiter_area/_assistant.html.twig (lien)
 */
final class RecruiterQuestionTest extends WebTestCase
{
    use AdminTestTrait;
    use RecruiterTestTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie le parcours : lien sous la réponse, question reprise, envoi, message pour l'admin, notification.
     */
    public function testQuestionFromAssistant(): void
    {
        $client = self::createClient();
        $this->createAdmin();
        $recruiter = $this->logInRecruiter($client, 'question@acme.test');
        // Noyau redémarré par les requêtes : recruteur relu dans le gestionnaire courant
        $recruiter = $this->entityManager()->find(Recruiter::class, $recruiter->getId());
        self::assertNotNull($recruiter);
        $exchange = new ChatMessage($recruiter, new \DateTimeImmutable(), 'Parle-t-il allemand ?', ChatGuard::NOT_SPECIFIED, ChatOutcome::AiPrimary);
        $this->entityManager()->persist($exchange);
        $this->entityManager()->flush();

        $crawler = $client->request('GET', '/espace-recruteur/accueil');
        $link = $crawler->filter('[data-testid="assistant-question-link"]');
        self::assertCount(1, $link);
        $crawler = $client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="question-sender"]', 'Camille Martin (Acme)');
        self::assertStringContainsString('Parle-t-il allemand ?', (string) $crawler->filter('textarea')->text());

        $client->submit($crawler->selectButton('Envoyer ma question')->form(['recruiter_question[message]' => 'Parle-t-il allemand ? Le poste l\'exige.']));
        self::assertResponseRedirects('/espace-recruteur/accueil');
        $message = static::getContainer()->get(ContactMessageRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($message);
        self::assertSame('Camille Martin', $message->getName());
        self::assertSame('question@acme.test', $message->getEmail());
        self::assertSame('Acme', $message->getCompany());
        self::assertSame(ContactSubject::Recruitment, $message->getSubject());
        // Type « question d'un recruteur » (filtre de la page Messages)
        self::assertSame(ContactSource::RecruiterQuestion, $message->getSource());
        $notification = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && str_contains((string) $m->getSubject(), 'Question d\'un recruteur')));
        self::assertNotEmpty($notification);
    }

    /**
     * Vérifie qu'un échange d'un autre recruteur n'est jamais repris, et que la page est réservée aux recruteurs.
     */
    public function testOtherRecruiterExchangeIsNotPrefilledAndPageIsPrivate(): void
    {
        $client = self::createClient();
        $client->request('GET', '/espace-recruteur/question');
        self::assertResponseRedirects();

        $other = $this->createRecruiter('autre@acme.test');
        $exchange = new ChatMessage($other, new \DateTimeImmutable(), 'Question confidentielle de l\'autre', ChatGuard::NOT_SPECIFIED, ChatOutcome::AiPrimary);
        $this->entityManager()->persist($exchange);
        $this->entityManager()->flush();
        $this->logInRecruiter($client, 'moi@acme.test');

        $crawler = $client->request('GET', '/espace-recruteur/question?echange='.$exchange->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('confidentielle', (string) $crawler->filter('textarea')->text());
    }

    /**
     * Connecte un recruteur par un vrai lien de connexion.
     */
    private function logInRecruiter(KernelBrowser $client, string $email): Recruiter
    {
        $recruiter = $this->createRecruiter($email);
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink($email);
        $emails = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && $email === $m->getTo()[0]->getAddress()));
        $last = end($emails);
        self::assertInstanceOf(Email::class, $last);
        $crawler = $client->request('GET', $this->extractRecruiterLink($last));
        $client->submit($crawler->selectButton('Me connecter')->form());
        $client->followRedirect();

        return $recruiter;
    }

    /** Gestionnaire Doctrine du conteneur de test. */
    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
