<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ContactMessage;
use App\Entity\User;
use App\Enum\ContactSource;
use App\Enum\ContactStatus;
use App\Enum\ContactSubject;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Page Messages (décision du 2026-10-29) : filtres avec leur nombre, type, recherche, tri, pagination au-delà de 25,
 * filtres mémorisés, « répondu » / « non répondu », actions groupées (suppression confirmée, sans JavaScript),
 * pastille du menu (non lus).
 *
 * Liens :
 * - Couvre : Admin\ContactMessageController, ContactMessageRepository (findFiltered(), countByFilter()),
 *   templates/admin/contact_message/*.html.twig, templates/admin/base.html.twig (pastille)
 */
final class ContactMessageFiltersTest extends WebTestCase
{
    use AdminTestTrait;
    use ConfirmationTrait;
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie le nombre de chaque filtre et le filtre par type.
     */
    public function testFiltersHaveCountsAndTypeFilter(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->createMessage('Alice Nouvelle', '-1 hour');
        $this->createMessage('Bruno Nouveau', '-2 hours');
        $this->createMessage('Chloé Question', '-3 hours', ContactSource::RecruiterQuestion, ContactStatus::Read);
        $this->createMessage('Denis Répondu', '-4 hours', replied: true);
        $this->createMessage('Emma Archivée', '-5 hours', archived: true);

        $crawler = $client->request('GET', $this->adminUrl('/messages?filtre=tous'));
        self::assertSame(['tous' => 4, 'non-lus' => 2, 'lus' => 2, 'repondus' => 1, 'archives' => 1], $this->counts($crawler));
        self::assertSame(['Alice Nouvelle', 'Bruno Nouveau', 'Chloé Question', 'Denis Répondu'], $this->names($crawler));

        self::assertSame(['Emma Archivée'], $this->names($client->request('GET', $this->adminUrl('/messages?filtre=archives'))));
        self::assertSame(['Denis Répondu'], $this->names($client->request('GET', $this->adminUrl('/messages?filtre=repondus'))));
        self::assertSame(['Alice Nouvelle', 'Bruno Nouveau'], $this->names($client->request('GET', $this->adminUrl('/messages?filtre=non-lus'))));

        $crawler = $client->request('GET', $this->adminUrl('/messages?filtre=tous&type=question'));
        self::assertSame(['Chloé Question'], $this->names($crawler));
        self::assertSame(['tous' => 1, 'non-lus' => 0, 'lus' => 1, 'repondus' => 0, 'archives' => 0], $this->counts($crawler));
        self::assertSelectorTextContains('[data-message] td:nth-child(4)', 'Question d\'un recruteur');
    }

    /**
     * Vérifie la recherche (nom, entreprise, mot du message, sans tenir compte de la casse) ; « % » cherché tel quel.
     */
    public function testSearch(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->createMessage('Alice Martin', '-1 hour', company: 'Froid Industrie', text: 'Question sur une chambre froide.');
        $this->createMessage('Bruno Durand', '-2 hours', company: 'Acme', text: 'Demande de devis pour 100 % des pièces.');

        foreach (['martin' => ['Alice Martin'], 'ACME' => ['Bruno Durand'], 'chambre' => ['Alice Martin'], '100 %' => ['Bruno Durand'], '%' => ['Bruno Durand']] as $search => $expected) {
            self::assertSame($expected, $this->names($client->request('GET', $this->adminUrl('/messages?filtre=tous&q='.rawurlencode($search)))), $search);
        }
        $client->request('GET', $this->adminUrl('/messages?filtre=tous&q=introuvable'));
        self::assertSelectorTextContains('[data-testid="messages-empty"]', 'Aucun message ne correspond');
    }

    /**
     * Vérifie le tri (récents d'abord par défaut) et la pagination au-delà de 25 messages.
     */
    public function testSortAndPagination(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        for ($i = 1; $i <= 27; ++$i) {
            $this->createMessage(\sprintf('Message %02d', $i), \sprintf('-%d hours', 100 - $i));
        }

        $crawler = $client->request('GET', $this->adminUrl('/messages'));
        $names = $this->names($crawler);
        self::assertCount(25, $names);
        self::assertSame('Message 27', $names[0]);
        self::assertSelectorTextContains('[data-testid="pagination"]', 'Page 1 sur 2');

        $crawler = $client->click($crawler->filter('[data-testid="pagination"] a[rel="next"]')->link());
        self::assertSame(['Message 02', 'Message 01'], $this->names($crawler));

        $names = $this->names($client->request('GET', $this->adminUrl('/messages?filtre=tous&tri=anciens')));
        self::assertSame('Message 01', $names[0]);
    }

    /**
     * Vérifie que le filtre, le type et le tri choisis sont repris à la visite suivante (jamais la recherche).
     */
    public function testFiltersAreRemembered(): void
    {
        $client = self::createClient();
        $admin = $this->loginFullyAsAdmin($client);
        $this->createMessage('Emma Archivée', '-1 hour', ContactSource::RecruiterQuestion, archived: true);

        $client->request('GET', $this->adminUrl('/messages?filtre=archives&type=question&tri=anciens&q=emma'));
        $crawler = $client->request('GET', $this->adminUrl('/messages'));
        self::assertSame('archives', $crawler->filter('[data-testid="message-filters"] [aria-current="page"]')->attr('data-filter'));
        self::assertSame('question', $crawler->filter('#message-type option[selected]')->attr('value'));
        self::assertSame('anciens', $crawler->filter('#message-sort option[selected]')->attr('value'));
        self::assertSame('', $crawler->filter('#message-q')->attr('value'));
        $this->getEntityManager()->clear();
        self::assertSame(['filtre' => 'archives', 'type' => 'question', 'tri' => 'anciens'], $this->getEntityManager()->find(User::class, $admin->getId())?->getMessageFilters());
    }

    /**
     * Vérifie « Marquer comme répondu » (date affichée) puis « Marquer comme non répondu », et l'archivage.
     */
    public function testRepliedAndArchived(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $message = $this->createMessage('Alice Martin', '-1 hour');

        $crawler = $client->request('GET', $this->adminUrl('/messages/'.$message->getId()));
        self::assertSelectorTextContains('[data-testid="replied-at"]', 'Pas encore répondu');
        $client->submit($crawler->selectButton('Marquer comme répondu')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextNotContains('[data-testid="replied-at"]', 'Pas encore répondu');
        self::assertSelectorTextContains('[data-testid="replied-at"]', date('Y'));

        $client->submit($crawler->selectButton('Marquer comme non répondu')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="replied-at"]', 'Pas encore répondu');

        $client->submit($crawler->selectButton('Archiver')->form());
        $client->followRedirect();
        self::assertSame(['Alice Martin'], $this->names($client->request('GET', $this->adminUrl('/messages?filtre=archives'))));
        self::assertSame([], $this->names($client->request('GET', $this->adminUrl('/messages?filtre=tous'))));

        $client->request('POST', $this->adminUrl('/messages/'.$message->getId().'/action'), ['action' => 'replied', '_token' => 'faux']);
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Vérifie les actions groupées : seuls les messages cochés changent ; la suppression passe par la page de
     * confirmation (sans JavaScript) ; retour à la liste avec les mêmes filtres.
     */
    public function testBulkActions(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $alice = $this->createMessage('Alice Nouvelle', '-1 hour');
        $bruno = $this->createMessage('Bruno Nouveau', '-2 hours');
        $chloe = $this->createMessage('Chloé Nouvelle', '-3 hours');

        $crawler = $client->request('GET', $this->adminUrl('/messages?filtre=non-lus'));
        $this->bulk($client, $crawler, 'Marquer lus', [$alice, $bruno]);
        self::assertResponseRedirects($this->adminUrl('/messages?filtre=non-lus&type=tous&tri=recents'));
        $crawler = $client->followRedirect();
        self::assertSame(['Chloé Nouvelle'], $this->names($crawler));
        self::assertSelectorTextContains('[data-notify-target="toast"]', '2 messages marqués comme lus.');

        $crawler = $client->request('GET', $this->adminUrl('/messages?filtre=tous'));
        $this->bulk($client, $crawler, 'Marquer répondus', [$alice]);
        $crawler = $client->followRedirect();
        $this->bulk($client, $crawler, 'Archiver', [$bruno]);
        $crawler = $client->followRedirect();
        self::assertSame(['tous' => 2, 'non-lus' => 1, 'lus' => 1, 'repondus' => 1, 'archives' => 1], $this->counts($crawler));

        // Suppression : page de confirmation (rien n'est supprimé avant), puis confirmation
        $this->bulk($client, $crawler, 'Supprimer', [$alice, $chloe]);
        $this->confirmAction($client, 'Supprimer les 2 messages sélectionnés ?');
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSame([], $this->names($crawler));
        self::assertSame(['tous' => 0, 'non-lus' => 0, 'lus' => 0, 'repondus' => 0, 'archives' => 1], $this->counts($crawler));

        // Aucune case cochée : rien ne change
        $client->request('POST', $this->adminUrl('/messages/actions-groupees'), ['action' => 'archive', '_token' => $this->generateCsrfToken($client, 'contact_message_bulk')]);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Aucun message sélectionné.');
    }

    /**
     * Vérifie la pastille du menu « Messages » : messages non lus de la boîte (archivés exclus).
     */
    public function testMenuBadgeCountsUnread(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl('/messages'));
        self::assertSelectorNotExists('[data-testid="admin-unread-badge"]');

        $this->createMessage('Alice Nouvelle', '-1 hour');
        $this->createMessage('Bruno Nouveau', '-2 hours');
        $this->createMessage('Emma Archivée', '-3 hours', archived: true);
        static::getContainer()->get('cache.dashboard')->clear();
        $client->request('GET', $this->adminUrl('/messages'));
        self::assertSelectorTextContains('[data-testid="admin-unread-badge"]', '2');
        self::assertSelectorTextContains('[data-testid="admin-unread-badge"]', '2 messages non lus');
    }

    /**
     * Crée un message.
     */
    private function createMessage(string $name, string $receivedAgo, ContactSource $source = ContactSource::ContactForm, ContactStatus $status = ContactStatus::New, bool $replied = false, bool $archived = false, ?string $company = null, string $text = 'Une question sur le dimensionnement.'): ContactMessage
    {
        $received = new \DateTimeImmutable($receivedAgo);
        $message = (new ContactMessage($received))->setName($name)->setEmail('contact@acme.test')->setCompany($company)
            ->setSubject(ContactSubject::Technical)->setMessage($text)->setSource($source)->setStatus($status)->setConsentAt($received);
        if ($replied) {
            $message->setRepliedAt($received);
        }
        if ($archived) {
            $message->setArchivedAt($received);
        }
        $this->getEntityManager()->persist($message);
        $this->getEntityManager()->flush();

        return $message;
    }

    /**
     * Coche des messages et envoie une action groupée.
     *
     * @param list<ContactMessage> $messages messages à cocher
     */
    private function bulk(KernelBrowser $client, Crawler $crawler, string $button, array $messages): void
    {
        $form = $crawler->selectButton($button)->form();
        $values = $form->getPhpValues();
        $values['ids'] = array_map(static fn (ContactMessage $m): string => (string) $m->getId(), $messages);
        $values['action'] = $form->get('action')->getValue();
        $client->request('POST', $form->getUri(), $values);
    }

    /**
     * Noms des messages listés, dans l'ordre.
     *
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('[data-message] td:nth-child(3) a')->each(static fn (Crawler $link): string => trim($link->text()));
    }

    /**
     * Nombre affiché sur chaque filtre.
     *
     * @return array<string, int>
     */
    private function counts(Crawler $crawler): array
    {
        $counts = [];
        $crawler->filter('[data-testid="message-filters"] a')->each(static function (Crawler $link) use (&$counts): void {
            $counts[(string) $link->attr('data-filter')] = (int) $link->filter('[data-testid="filter-count"]')->text();
        });

        return $counts;
    }
}
