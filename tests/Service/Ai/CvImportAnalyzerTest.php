<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Experience;
use App\Service\Ai\AiProviderException;
use App\Service\Ai\CvImportAnalyzer;
use App\Tests\Support\MockHttpResponseFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Import de CV par l'IA : données personnelles retirées avant l'envoi, réponse JSON lue en aperçu,
 * rien n'est enregistré dans le contenu du site.
 *
 * Liens :
 * - Couvre : CvImportAnalyzer, PrivateDataFilter::filterAll(), AiCaller (usage « import de CV »)
 */
final class CvImportAnalyzerTest extends KernelTestCase
{
    /** CV de test avec toutes les données personnelles à retirer. */
    private const CV = "Jean Dupont\n12 rue des Lilas, 69500 Lyon\nNé le 03/05/1980\njean.dupont@exemple.test — 06 12 34 56 78\n\nEXPÉRIENCE\n2019 – 2024 Responsable BE, Acme (Lyon)";

    /**
     * Vérifie le texte envoyé (sans données personnelles) et l'aperçu, sans rien enregistrer.
     */
    public function testCvIsRedactedAndAnalyzedWithoutSavingAnything(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $experiencesBefore = $entityManager->getRepository(Experience::class)->count([]);
        MockHttpResponseFactory::$mistralQueue[] = MockHttpResponseFactory::mistralChat('Voici le JSON : {"experiences": [{"job_title": "Responsable BE", "company": "Acme", "location": "Lyon", "start": "2019", "end": "2024", "summary": "Contact : 06 12 34 56 78"}], "educations": [{"year": "1999", "title": "Bac Pro", "institution": ""}], "skills": ["Froid", "Électricité"], "languages": [{"name": "Anglais", "level": "technique"}]}', 900, 300);

        $result = self::getContainer()->get(CvImportAnalyzer::class)->analyze(self::CV);

        $body = MockHttpResponseFactory::requestsTo('mistral.example.test/v1/chat')[0]['body'];
        foreach (['rue des Lilas', '69500', '03/05/1980', 'jean.dupont@exemple.test', '06 12 34 56 78'] as $secret) {
            self::assertStringNotContainsString($secret, $body, $secret);
        }
        self::assertStringContainsString('Responsable BE, Acme', $body);
        self::assertSame('Responsable BE', $result['experiences'][0]['job_title']);
        self::assertStringNotContainsString('06 12 34 56 78', $result['experiences'][0]['summary']);
        self::assertSame(['Froid', 'Électricité'], $result['skills']);
        self::assertSame(1200, $result['tokens']);
        self::assertContains('adresse', $result['removed']);
        self::assertSame($experiencesBefore, $entityManager->getRepository(Experience::class)->count([]));
    }

    /**
     * Vérifie qu'une réponse sans JSON est signalée comme illisible.
     */
    public function testAnswerWithoutJsonIsRejected(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        MockHttpResponseFactory::$mistralQueue[] = MockHttpResponseFactory::mistralChat('Je ne peux pas.');

        $this->expectException(AiProviderException::class);
        self::getContainer()->get(CvImportAnalyzer::class)->analyze(self::CV);
    }
}
