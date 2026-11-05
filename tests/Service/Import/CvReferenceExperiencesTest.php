<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Repository\ExperienceRepository;
use App\Service\Content\ContentCompletenessChecker;
use App\Service\Content\CvContentProvider;
use App\Service\Import\CvContentSynchronizer;
use App\Service\Import\TextesSiteParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les expériences du site doivent être exactement celles du CV de référence :
 * nombre, ordre, rubrique, entreprise, lieu, dates, intitulés, et les 3 postes DELTA regroupés.
 *
 * Le tableau attendu est écrit ici à la main d'après le CV (indépendant du parseur) : le test échoue si
 * BCI manque, si une date diffère ou si une entrée est en double.
 *
 * Exception : la ligne « Commerce — emploi saisonnier » (03/2000 – 09/2001) n'est pas encore dans le
 * CV PDF ; TEXTES_SITE.md fait foi sur ce point (mise à jour du 24/10/2026, à reporter dans le CV et LinkedIn).
 *
 * Liens :
 * - Couvre : TextesSiteParser (§ 4), CvContentSynchronizer (écriture en base), CvContentProvider (regroupement)
 * - Fixture : tests/Fixtures/cv_reference_experiences.md (copie du § 4 de TEXTES_SITE.md)
 */
final class CvReferenceExperiencesTest extends KernelTestCase
{
    /** Fixture versionnée (§ 4 de TEXTES_SITE.md). */
    private const FIXTURE = __DIR__.'/../../Fixtures/cv_reference_experiences.md';

    /** Fichier de textes réel, hors dépôt (présent sur le poste de développement seulement). */
    private const TEXTES_SITE = __DIR__.'/../../../../TEXTES_SITE.md';

    /**
     * Expériences du CV, dans l'ordre : rubrique, début, fin, entreprise, lieu, poste.
     *
     * @var list<array{string, string, string, string, string, string}>
     */
    private const EXPECTED = [
        ['professional', '02/2023', '12/2025', 'ALPHA ESSAIS GROUP', 'Grenoble', 'Responsable Bureau d\'Études'],
        ['professional', '05/2021', '02/2023', 'BETA CLIMAT INDUSTRIE (BCI)', 'Annecy', 'Responsable Bureau d\'Études'],
        ['professional', '09/2018', '05/2021', 'GAMMA FROID SERVICES', 'Lyon', 'Chargé d\'Affaires Bureau d\'Études Petits Travaux'],
        ['professional', '01/2017', '09/2018', '', 'Lyon', 'Projet entrepreneurial & développement web'],
        ['foundation', '01/2017', '01/2017', 'DELTA THERMIQUE / EPSILON LABO', 'Villeurbanne', 'Chargé de conception'],
        ['foundation', '01/2014', '01/2017', 'DELTA THERMIQUE / EPSILON LABO', 'Villeurbanne', 'Responsable de production'],
        ['foundation', '03/2005', '01/2014', 'DELTA THERMIQUE / EPSILON LABO', 'Villeurbanne', 'Technicien de production'],
        ['field', '06/2004', '02/2005', 'ZETA ÉNERGIE', 'Valence', 'Technicien de maintenance'],
        ['field', '09/2001', '05/2004', 'ÊTA MAINTENANCE', 'Mâcon', 'Technicien de maintenance'],
        // Exception au CV PDF : TEXTES_SITE.md fait foi (voir le docblock de la classe)
        ['field', '03/2000', '09/2001', 'emploi saisonnier', '', 'Restauration'],
        ['field', '09/1997', '03/2000', 'EPSILON LABO', 'Bourg-en-Bresse', 'Technicien monteur'],
        ['field', '09/1993', '08/1997', 'IOTA ÉLECTRICITÉ / KAPPA SERVICES', 'Vienne / Givors', 'Apprenti électrotechnicien et frigoriste'],
    ];

    /**
     * Vérifie que la fixture donne exactement les expériences du CV.
     */
    public function testFixtureMatchesReference(): void
    {
        self::assertSame(self::EXPECTED, $this->parsedRows((string) file_get_contents(self::FIXTURE)));
    }

    /**
     * Vérifie que le vrai TEXTES_SITE.md donne exactement les expériences du CV (poste de développement).
     */
    public function testRealTextesSiteMatchesReference(): void
    {
        if (!is_file(self::TEXTES_SITE)) {
            self::markTestSkipped('TEXTES_SITE.md absent (hors dépôt) : seule la fixture est vérifiée.');
        }

        self::assertSame(self::EXPECTED, $this->parsedRows((string) file_get_contents(self::TEXTES_SITE)));
    }

    /**
     * Vérifie la base après synchronisation : mêmes lignes, sans doublon ; DELTA en une seule entrée de
     * 3 postes (03/2005 – 01/2017) ; CV public synthétique (puces 🌐, nombre de puces réservées), contexte ALPHA ESSAIS
     * et fin de contrat réservés au contenu privé.
     */
    public function testSynchronizedDatabaseMatchesReference(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $content = $container->get(TextesSiteParser::class)->extract((string) file_get_contents(self::FIXTURE));
        $container->get(CvContentSynchronizer::class)->synchronize($content, true);

        $rows = array_map(static fn ($e): array => [
            $e->getCareerSection()->value, $e->getStartDate()?->format('m/Y'), $e->getEndDate()?->format('m/Y'),
            $e->getCompanyName(), (string) $e->getLocation(), $e->getJobTitle(),
        ], $container->get(ExperienceRepository::class)->findAllOrdered());
        self::assertSame(self::EXPECTED, $rows);

        $public = $container->get(CvContentProvider::class)->getContent()['career'];
        self::assertCount(4, $public['professional']);
        self::assertCount(1, $public['foundation'], 'DELTA : une seule entrée.');
        self::assertSame(['2017', '2014', '2007'], array_map(static fn (array $r): string => $r['start']->format('Y'), $public['foundation'][0]['roles']));
        self::assertSame(['03/2005', '01/2017'], [$public['foundation'][0]['start']->format('m/Y'), $public['foundation'][0]['end']->format('m/Y')]);
        self::assertCount(5, $public['field']);
        // CV public synthétique (2026-10-30) : puces 🌐 seulement, contexte ALPHA ESSAIS réservé à l'espace recruteur
        self::assertNotContains('Rachat de BCI par ALPHA ESSAIS Group en février 2023.', $public['professional'][0]['missions']);
        self::assertSame([3, 1], [\count($public['professional'][0]['missions']), $public['professional'][0]['hidden_count']]);
        self::assertNull($public['professional'][0]['private_note']);
        self::assertSame([3, 2], [\count($public['professional'][1]['missions']), $public['professional'][1]['hidden_count']], 'BCI : 3 puces publiques sur 5.');
        self::assertSame([], $public['field'][0]['missions'], 'Parcours terrain : une ligne par poste.');

        $private = $container->get(CvContentProvider::class)->getPrivateContent()['career'];
        self::assertSame('Note privée de test.', $private['professional'][0]['private_note']);
        self::assertContains('Rachat de BCI par ALPHA ESSAIS Group en février 2023.', $private['professional'][0]['missions']);
        self::assertSame([5, 0], [\count($private['professional'][1]['missions']), $private['professional'][1]['hidden_count']], 'BCI : les 5 puces du § 4.2.');
        self::assertNotSame([], $private['field'][0]['missions']);
    }

    /**
     * Vérifie le tableau de bord « À compléter » : avec la ligne « Restauration », plus aucun trou dans le
     * parcours ; sans elle, le trou 03/2000 – 09/2001 est signalé (le contrôle fonctionne bien).
     */
    public function testRestaurantLineClosesTheCareerGap(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $parser = $container->get(TextesSiteParser::class);
        $synchronizer = $container->get(CvContentSynchronizer::class);
        $checker = $container->get(ContentCompletenessChecker::class);
        $gaps = static fn (): array => array_values(array_map(
            static fn (array $item): string => $item['parameters']['%from%'].' – '.$item['parameters']['%to%'],
            array_filter($checker->findMissingItems(), static fn (array $item): bool => 'admin.completeness.career_gap' === $item['message']),
        ));

        $markdown = (string) file_get_contents(self::FIXTURE);
        $withoutRestaurant = (string) preg_replace('/^- \*\*Restauration — .*\n/mu', '', $markdown);
        $synchronizer->synchronize($parser->extract($withoutRestaurant), true);
        self::assertSame(['03/2000 – 09/2001'], $gaps());

        $synchronizer->synchronize($parser->extract($markdown), true);
        self::assertSame([], $gaps());
    }

    /**
     * Expériences lues par le parseur, au format du tableau attendu.
     *
     * @return list<array{string, string, string, string, string, string}>
     */
    private function parsedRows(string $markdown): array
    {
        $format = static fn (?string $date): string => null === $date ? '' : substr($date, 5, 2).'/'.substr($date, 0, 4);

        return array_map(static fn (array $e): array => [
            $e['section']->value, $format($e['start']), $format($e['end']), $e['company'], (string) $e['location'], $e['job_title'],
        ], (new TextesSiteParser())->extract($markdown)['experiences']);
    }
}
