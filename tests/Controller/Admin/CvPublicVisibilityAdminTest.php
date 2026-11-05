<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Certification;
use App\Entity\CvEntry;
use App\Entity\Experience;
use App\Entity\Profile;
use App\Enum\CvEntrySection;
use App\Service\Import\CvContentSynchronizer;
use App\Service\Import\TextesSiteParser;
use App\Tests\Support\AdminTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CV public allégé (correction de recette n° 1, 2026-10-30) : réglages de l'admin et repères de TEXTES_SITE.md.
 *
 * - une case « Visible en public » par puce d'expérience ; une puce modifiée n'est plus publique ;
 * - « Résumé public » d'un élément des rubriques ;
 * - repères 🌐 du fichier lus par le parseur et appliqués par la synchronisation.
 *
 * Liens :
 * - Couvre : ExperienceType (publicItems), CvEntryType (publicSummary), Experience::getPublicItems(),
 *   TextesSiteParser (repère 🌐), CvContentSynchronizer
 */
final class CvPublicVisibilityAdminTest extends WebTestCase
{
    use AdminTestTrait;

    /**
     * Vérifie les cases « Visible en public » : une par puce, enregistrées, et une puce modifiée redevient privée.
     */
    public function testExperienceBulletsCanBeMadePublic(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $entityManager = $this->getEntityManager();
        $experience = (new Experience())->setJobTitle('Responsable Bureau d\'Études')->setCompanyName('ALPHA ESSAIS GROUP')
            ->setStartDate(new \DateTimeImmutable('2023-02-01'))->setMissions("**Analyse des besoins clients** et conception.\nChiffrages et plans.\nRachat de BCI.")->setPosition(1);
        $entityManager->persist($experience);
        $entityManager->flush();

        $crawler = $client->request('GET', $this->adminUrl('/experiences/'.$experience->getId().'/modifier'));
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('input[name="experience[publicItems][]"]'));
        self::assertSelectorTextContains('form', 'Visible en public');
        // Libellé sans les marques de gras
        self::assertSelectorTextContains('form', 'Analyse des besoins clients et conception.');

        $form = $crawler->selectButton('Enregistrer')->form();
        // Une case par puce : les deux premières cochées
        $form['experience[publicItems]'][0]->tick();
        $form['experience[publicItems]'][1]->tick();
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/experiences'));

        $entityManager->clear();
        $saved = $entityManager->find(Experience::class, $experience->getId());
        self::assertSame(['**Analyse des besoins clients** et conception.', 'Chiffrages et plans.'], $saved?->getPublicItems());

        // Puce reformulée : elle n'est plus publique tant qu'elle n'est pas recochée
        $saved->setMissions("**Analyse des besoins clients** et conception.\nChiffrages, notes de calcul et plans.\nRachat de BCI.");
        self::assertSame(['**Analyse des besoins clients** et conception.'], $saved->getPublicItems());
    }

    /**
     * Vérifie le champ « Résumé public » d'un élément des rubriques.
     */
    public function testEntryPublicSummaryIsSaved(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/rubriques-cv/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'cv_entry[section]' => 'software',
            'cv_entry[title]' => 'Conception',
            'cv_entry[content]' => 'SpaceClaim 2023 R1 (ANSYS), AutoCAD LT, SketchUp.',
            'cv_entry[publicSummary]' => 'SpaceClaim, AutoCAD, SketchUp.',
        ]), [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/rubriques-cv'));
        $entry = $this->getEntityManager()->getRepository(CvEntry::class)->findOneBy(['title' => 'Conception']);
        self::assertSame('SpaceClaim, AutoCAD, SketchUp.', $entry?->getPublicSummary());
    }

    /**
     * Vérifie la lecture des repères 🌐 (puces publiques, résumés publics) et leur application en base.
     */
    public function testTextesSiteMarkersAreSynchronized(): void
    {
        self::bootKernel();
        $markdown = <<<'MD'
            ## 4. Expériences professionnelles 🌍

            ### 4.1 Responsable Bureau d'Études — ALPHA ESSAIS GROUP · Grenoble (02/2023 – 12/2025)
            - 🌐 **Analyse des besoins clients** et conception.
            - Rachat de BCI. 🔒 Note privée.

            ## 7. Logiciels et environnements techniques 🌍
            - **Conception :** SpaceClaim 2023 R1 (ANSYS), AutoCAD LT.
              - 🌐 Résumé public : SpaceClaim, AutoCAD.
            - **Langages informatiques :** HTML, CSS, PHP.
            MD;
        $content = static::getContainer()->get(TextesSiteParser::class)->extract($markdown);

        self::assertSame(['**Analyse des besoins clients** et conception.', 'Rachat de BCI.'], $content['experiences'][0]['missions']);
        self::assertSame(['**Analyse des besoins clients** et conception.'], $content['experiences'][0]['public_missions']);
        self::assertSame(['SpaceClaim, AutoCAD.', null], array_column($content['entries'], 'public_summary'));
        self::assertCount(2, $content['entries'], 'La sous-puce du résumé n\'est pas un élément.');

        static::getContainer()->get(CvContentSynchronizer::class)->synchronize($content, true);
        $entityManager = $this->getEntityManager();
        $entityManager->clear();
        self::assertSame(['**Analyse des besoins clients** et conception.'], $entityManager->getRepository(Experience::class)->findOneBy([])?->getPublicItems());
        $summaries = array_map(static fn (CvEntry $e): ?string => $e->getPublicSummary(), $entityManager->getRepository(CvEntry::class)->findBy(['section' => CvEntrySection::Software], ['position' => 'ASC']));
        self::assertSame(['SpaceClaim, AutoCAD.', null], $summaries);
    }

    /**
     * Vérifie le repère 🔒 des titres des § 9 et 10 (rubriques réservées) et la ligne « Certifications » du § 10
     * (corrections de recette n° 2 et 3) ; un titre 🌍 rend la rubrique de nouveau publique.
     */
    public function testSectionMarkersAndCertificationsAreSynchronized(): void
    {
        self::bootKernel();
        $markdown = <<<'MD'
            ## 9. Langues 🔒
            - Français : langue maternelle.

            ## 10. Formations et certifications 🔒
            | Année | Diplôme | Lieu | Organisme |
            |---|---|---|---|
            | 2019 | Titre Développeur Web | Lyon | École Exemple |

            **Certifications :** Attestation d'aptitude à la manipulation des fluides (catégorie 1), Centre de formation Exemple (2011).

            **Habilitations :** habilitations électriques B2V, obtenues chez Gamma Froid (2021 – 2023) ; recyclage à prévoir.
            MD;
        $parser = static::getContainer()->get(TextesSiteParser::class);
        $content = $parser->extract($markdown);
        self::assertSame(['languages' => false, 'educations' => false], $content['section_visibility']);
        self::assertSame([['name' => 'Attestation d\'aptitude à la manipulation des fluides (catégorie 1)', 'issuer' => 'Centre de formation Exemple', 'year' => 2011]], $content['certifications']);

        $synchronizer = static::getContainer()->get(CvContentSynchronizer::class);
        $synchronizer->synchronize($content, true);
        $entityManager = $this->getEntityManager();
        $entityManager->clear();
        $profile = $entityManager->getRepository(Profile::class)->findOneBy([]);
        self::assertNotNull($profile);
        self::assertFalse($profile->isLanguagesPublic());
        self::assertFalse($profile->isEducationsPublic());
        $types = array_map(static fn (Certification $c): string => $c->getType()->value.' '.$c->getObtainedOn()?->format('Y'), $entityManager->getRepository(Certification::class)->findAll());
        sort($types);
        self::assertSame(['certification 2011', 'clearance 2021'], $types);

        $synchronizer->synchronize($parser->extract(str_replace(['Langues 🔒', 'certifications 🔒'], ['Langues 🌍', 'certifications 🌍'], $markdown)), true);
        $entityManager->clear();
        self::assertTrue($entityManager->getRepository(Profile::class)->findOneBy([])?->isLanguagesPublic());
    }
}
