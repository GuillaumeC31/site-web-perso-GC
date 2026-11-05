<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ChatMessage;
use App\Entity\Recruiter;
use App\Entity\RecruiterEvent;
use App\Enum\ChatOutcome;
use App\Enum\RecruiterEventType;
use App\Enum\RecruiterStatus;
use App\Repository\RecruiterRepository;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Droits RGPD dans l'espace recruteur (2026-10-29) : lien « Ne plus recevoir ces rappels » des e-mails de rappel
 * d'échéance (effet immédiat, désinscription en un clic des messageries) et « Télécharger mes données » (JSON lisible).
 *
 * Liens :
 * - Couvre : RecruiterAreaController::stopReminders() et exportData(), RecruiterDailyCommand, RecruiterMailer::sendExpiryReminder(),
 *   RecruiterDataExporter, templates/recruiter_area/reminders.html.twig et data.html.twig
 */
final class RecruiterRgpdTest extends WebTestCase
{
    use RecruiterTestTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie le refus des rappels : lien dans le rappel (texte et en-têtes List-Unsubscribe), page de confirmation
     * (rien n'est encore enregistré), refus immédiat, plus aucun rappel ensuite ; lien modifié refusé.
     */
    public function testReminderOptOutTakesEffectImmediately(): void
    {
        $client = self::createClient();
        $this->createNotifiedAdmin();
        $recruiter = $this->createRecruiter('rappel@acme.test', RecruiterStatus::Approved, '+5 days');

        self::assertStringContainsString('1 rappel(s) d\'échéance', $this->runDaily());
        $reminder = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $reminder);
        preg_match('#https?://\S+/espace-recruteur/rappels/\S+#', (string) $reminder->getTextBody(), $matches);
        self::assertNotEmpty($matches, 'Lien « Ne plus recevoir ces rappels » absent de la version texte.');
        $stopUrl = $matches[0];
        self::assertStringContainsString('Ne plus recevoir ces rappels', (string) $reminder->getHtmlBody());
        self::assertSame('<'.$stopUrl.'>', $reminder->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $reminder->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());

        $client->request('GET', str_replace('_hash=', '_hash=x', $stopUrl));
        self::assertResponseStatusCodeSame(404);

        // Ouverture du lien (ou passage d'un scanner) : page de confirmation, rien n'est encore enregistré
        $crawler = $client->request('GET', $stopUrl);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($recruiter)->acceptsReminders());

        $client->submit($crawler->selectButton('Ne plus recevoir ces rappels')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="reminders-stopped"]', 'effet immédiat');
        $stored = $this->reload($recruiter);
        self::assertFalse($stored->acceptsReminders());

        // Même échéance remise à zéro : aucun rappel ne part plus
        $stored->setReminderSentAt(null);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertStringContainsString('0 rappel(s) d\'échéance', $this->runDaily());
    }

    /**
     * Vérifie la désinscription en un clic des messageries (RFC 8058) : POST direct sur le lien signé, sans session.
     */
    public function testOneClickUnsubscribeFromMailbox(): void
    {
        $client = self::createClient();
        $this->createNotifiedAdmin();
        $recruiter = $this->createRecruiter('unclic@acme.test', RecruiterStatus::Approved, '+5 days');
        $this->runDaily();
        $reminder = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $reminder);
        $stopUrl = (string) preg_replace('/^<|>$/', '', (string) $reminder->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());

        $client->request('POST', $stopUrl, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($recruiter)->acceptsReminders());
    }

    /**
     * Vérifie « Télécharger mes données » : bouton de la page « Mes données », fichier JSON lisible (pièce jointe, pas
     * de cache) avec la demande, l'accès, les évènements et les échanges avec l'assistant ; rien d'un autre recruteur.
     */
    public function testDataExportIsReadableJson(): void
    {
        $client = self::createClient();
        $recruiter = $this->createRecruiter('portable@acme.test');
        $other = $this->createRecruiter('autre@acme.test');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new RecruiterEvent($recruiter, RecruiterEventType::Interested, new \DateTimeImmutable('-1 day')));
        $entityManager->persist(new ChatMessage($recruiter, new \DateTimeImmutable('-2 hours'), 'Quelle est son expérience ?', 'Dix ans de chantier.', ChatOutcome::Guided));
        $entityManager->persist(new ChatMessage($other, new \DateTimeImmutable('-1 hour'), 'Question d\'un autre', 'Réponse', ChatOutcome::Guided));
        $entityManager->flush();

        $client->request('GET', '/espace-recruteur/mes-donnees/telechargement');
        self::assertResponseRedirects();

        $client->loginUser($recruiter, 'recruiter');
        $crawler = $client->request('GET', '/espace-recruteur/mes-donnees');
        self::assertSelectorTextContains('[data-testid="reminders-state"]', 'Reçus');
        $client->click($crawler->filter('[data-testid="data-export"]')->link());
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=mes-donnees-guillaume-couget.json', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $json = (string) $response->getContent();
        self::assertStringContainsString("\n    ", $json, 'JSON indenté, lisible.');
        self::assertStringContainsString('Quelle est son expérience ?', $json, 'Accents gardés tels quels.');
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('portable@acme.test', $data['demande']['adresse_e_mail']);
        self::assertSame('approved', $data['acces']['statut']);
        self::assertSame([RecruiterEventType::Interested->value], array_column($data['evenements'], 'type'));
        self::assertSame(['Quelle est son expérience ?'], array_column($data['echanges_avec_l_assistant'], 'question'));
        self::assertStringNotContainsString('autre@acme.test', $json);
        self::assertStringNotContainsString('Question d\'un autre', $json);

        // Formulaire de question à Guillaume : mention courte, elle aussi
        $client->request('GET', '/espace-recruteur/question');
        self::assertSelectorTextContains('form [data-testid="form-privacy-notice"]', '12 mois');
    }

    /**
     * Lance la commande quotidienne de l'espace recruteur et renvoie son bilan.
     */
    private function runDaily(): string
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:recruiter:daily'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    /**
     * Relit le recruteur en base (sans le cache de Doctrine).
     */
    private function reload(Recruiter $recruiter): Recruiter
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $stored = static::getContainer()->get(RecruiterRepository::class)->find($recruiter->getId());
        self::assertInstanceOf(Recruiter::class, $stored);

        return $stored;
    }
}
