<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AiKnowledgeEntry;
use App\Enum\AiKnowledgeCategory;
use App\Service\Ai\AnswerFactChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrôle avant affichage : chaque date, nombre, nom propre, sigle ou norme de la réponse doit figurer
 * dans le contexte envoyé (comparaison sans accents, casse, espaces ni séparateurs de milliers).
 *
 * Liens :
 * - Couvre : App\Service\Ai\AnswerFactChecker
 */
final class AnswerFactCheckerTest extends TestCase
{
    /** Contexte fictif (socle et résumé du site réduits). */
    private const CONTEXT = <<<'TXT'
        Tu es l'assistant de Guillaume Couget. Réponds en 3 phrases maximum ; près de 20 ans d'expérience au total.
        RÉSUMÉ DU SITE :
        - 01/2012 – 12/2020 | ALPHA ESSAIS GROUP (Saint-Étienne) — Responsable bureau d'études — dimensionnement selon la FD X 15-140, logiciel SolidWorks, budget de 1 XX 000 €.
        - Formation : BTS Électrotechnique, habilitation électrique B2V.
        FICHES DE GUILLAUME :
        - Mobilité : mobile dans toute l'Auvergne-Rhône-Alpes, permis B.
        TXT;

    /**
     * Vérifie qu'une réponse fidèle passe (accents, casse, milliers et tirets normalisés).
     */
    public function testFaithfulAnswerPasses(): void
    {
        $answer = "Guillaume Couget a été responsable bureau d'études chez Alpha Essais Group à saint-etienne de 2012 à 2020. Il a géré un budget de 1200000 € et utilisé SolidWorks selon la FD X 15-140. Il est titulaire d'un BTS et de l'habilitation B2V, et il est mobile en Auvergne-Rhône-Alpes.";

        self::assertNull((new AnswerFactChecker())->findUnsupported($answer, self::CONTEXT));
    }

    /**
     * Vérifie que l'élément absent du contexte est repéré et renvoyé tel qu'écrit.
     */
    #[DataProvider('provideInventions')]
    public function testInventedElementIsReported(string $answer, string $expected): void
    {
        self::assertSame($expected, (new AnswerFactChecker())->findUnsupported($answer, self::CONTEXT));
    }

    /**
     * Réponses avec une date, une entreprise, un chiffre, un lieu, une norme ou un logiciel inventés.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideInventions(): iterable
    {
        yield 'date' => ['Il a rejoint ALPHA ESSAIS GROUP en 2009.', '2009'];
        yield 'entreprise' => ['Il a aussi travaillé chez Airbus comme ingénieur.', 'Airbus'];
        yield 'chiffre' => ['Il a encadré une équipe de 14 personnes.', '14'];
        yield 'montant' => ['Il a géré un budget de 3 XX 000 €.', '3500000'];
        yield 'lieu' => ['Il a vécu à Lyon pendant ses études.', 'Lyon'];
        yield 'norme' => ['Il maîtrise la norme NF C 15-100.', '15-100'];
        yield 'logiciel' => ['Il utilise aussi AutoCAD au quotidien.', 'AutoCAD'];
        yield 'durée' => ['Il a passé 15 ans en génie climatique.', '15'];
    }

    /**
     * Vérifie qu'une fourchette en k€ du contexte reformulée en euros passe, et qu'un autre montant reste repéré.
     */
    #[DataProvider('provideThousandUnits')]
    public function testThousandUnitsMatchAmountsInEuros(string $context, string $answer, ?string $expected): void
    {
        self::assertSame($expected, (new AnswerFactChecker())->findUnsupported($answer, $context));
    }

    /**
     * Contextes en k€ (montants fictifs) et réponses reformulées.
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function provideThousandUnits(): iterable
    {
        $context = 'PRÉTENTIONS (affichées dans l\'espace recruteur) : XX à YY k€ brut annuel, selon le poste.';
        yield 'fourchette reformulée' => [$context, 'Sa fourchette est comprise entre XX 000 € et XX 000 € brut annuel.', null];
        yield 'fourchette telle quelle' => [$context, 'Sa fourchette est de XX à YY k€ brut annuel.', null];
        yield 'autre montant' => [$context, 'Il souhaite XX 000 € brut annuel.', '78000'];
        yield 'tiret et décimale' => ['Prétentions : 72,XX à YY k€.', 'Entre 72 500 € et XX 000 €.', null];
        yield 'kilomètres non convertis' => ['Mobile dans un rayon de 70 km.', 'Il vise XX 000 € par an.', '70000'];
    }

    /**
     * Vérifie l'indicateur « probablement utilisée » : il repose sur les termes propres à la fiche, pas sur le nom
     * de Guillaume ni sur les mots déjà présents dans le site (ex. « outils »).
     */
    #[DataProvider('provideUsedEntries')]
    public function testUsedEntriesRelyOnDistinctiveTerms(string $answer, bool $expected): void
    {
        $entry = (new AiKnowledgeEntry())->setCategory(AiKnowledgeCategory::Faq)->setTitle('Réalisation du site')
            ->setContent("Si on demande comment ce site a été réalisé : Guillaume l'a conçu et développé de manière autonome, avec l'assistance d'outils d'IA.");
        $baseContext = self::CONTEXT."\n- Outils numériques : SolidWorks, tableurs.";

        self::assertSame($expected ? [$entry] : [], (new AnswerFactChecker())->findUsedEntries($answer, [$entry], $baseContext));
    }

    /**
     * Réponses qui reprennent la fiche, ou qui ne partagent avec elle que des termes communs.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function provideUsedEntries(): iterable
    {
        yield 'fiche reprise' => ["Guillaume a conçu et développé ce site de manière autonome, en s'appuyant sur des outils d'IA.", true];
        yield 'nom et mot du site seulement' => ["Guillaume Couget est responsable technique, à l'aise avec les outils numériques.", false];
        yield 'un seul terme propre' => ['Guillaume travaille de manière rigoureuse.', false];
    }

    /**
     * Vérifie que la majuscule de début de phrase n'est pas prise pour un nom propre.
     */
    public function testSentenceStartIsNotANameButAcronymsAre(): void
    {
        $checker = new AnswerFactChecker();

        self::assertSame([], $checker->extractElements('Ensuite, il a conçu des bancs d\'essai. Puis il a encadré les études.'));
        self::assertSame(['BTS', 'Lyon'], $checker->extractElements('BTS obtenu. Il habite près de Lyon.'));
        self::assertSame(['R-290'], $checker->extractElements('Il connaît le fluide R-290.'));
    }
}
