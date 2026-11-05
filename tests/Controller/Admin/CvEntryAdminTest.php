<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CvEntry;
use App\Enum\CvEntrySection;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin des rubriques « liste » du CV (lot 6-bis) : ajout, liste dans l'ordre du CV, déplacement dans
 * la rubrique seulement.
 *
 * Liens :
 * - Couvre : Admin\CvEntryController, CvEntryType, CvEntryRepository (ordre, voisine dans la rubrique)
 */
final class CvEntryAdminTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie l'ajout d'un élément, puis son affichage dans la liste.
     */
    public function testCreateEntry(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/rubriques-cv/nouveau'));
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'cv_entry[section]' => 'software',
            'cv_entry[title]' => 'Conception',
            'cv_entry[content]' => 'SpaceClaim, AutoCAD LT.',
        ]), [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/rubriques-cv'));
        $client->followRedirect();
        self::assertSelectorTextContains('table', 'Logiciels et environnements techniques');
        self::assertSelectorTextContains('table', 'SpaceClaim, AutoCAD LT.');
    }

    /**
     * Vérifie que la liste suit l'ordre du CV et que les flèches ne font pas changer de rubrique.
     */
    public function testMoveStaysInsideSection(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $entityManager = $this->getEntityManager();
        $interest = (new CvEntry())->setSection(CvEntrySection::Interest)->setContent('Intérêt')->setPosition(1);
        $toolA = (new CvEntry())->setSection(CvEntrySection::Tools)->setContent('Outil A')->setPosition(2);
        $toolB = (new CvEntry())->setSection(CvEntrySection::Tools)->setContent('Outil B')->setPosition(3);
        foreach ([$interest, $toolA, $toolB] as $entry) {
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        $crawler = $client->request('GET', $this->adminUrl('/rubriques-cv'));
        // Ordre du CV : outils techniques avant centres d'intérêt, quelle que soit la position
        self::assertSame(['Outil A', 'Outil B', 'Intérêt'], $crawler->filter('tbody tr')->each(static fn ($row): string => trim($row->filter('td')->eq(2)->text())));

        // Monter le premier outil : rien ne change (pas de voisine plus haut dans sa rubrique)
        $client->submit($crawler->filter(\sprintf('form[action$="/rubriques-cv/%d/deplacer/haut"]', $toolA->getId()))->form());
        $entityManager->clear();
        self::assertSame(2, $entityManager->find(CvEntry::class, $toolA->getId())?->getPosition());
        self::assertSame(1, $entityManager->find(CvEntry::class, $interest->getId())?->getPosition());

        // Descendre le premier outil : échange avec le second
        $crawler = $client->request('GET', $this->adminUrl('/rubriques-cv'));
        $client->submit($crawler->filter(\sprintf('form[action$="/rubriques-cv/%d/deplacer/bas"]', $toolA->getId()))->form());
        $entityManager->clear();
        self::assertSame(3, $entityManager->find(CvEntry::class, $toolA->getId())?->getPosition());
        self::assertSame(2, $entityManager->find(CvEntry::class, $toolB->getId())?->getPosition());
    }
}
