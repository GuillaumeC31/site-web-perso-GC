<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\AiPurpose;
use App\Repository\AiUsageRepository;
use App\Service\Ai\CompanySynthesizer;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Synthèse d'entreprise : seuls l'entreprise, le domaine de l'e-mail et le message filtré partent chez
 * Mistral AI (jamais le nom ni l'e-mail du recruteur) ; quatre lignes enregistrées sur le recruteur.
 *
 * Liens :
 * - Couvre : CompanySynthesizer, AiCaller (usage « synthèse »)
 */
final class CompanySynthesizerTest extends KernelTestCase
{
    use RecruiterTestTrait;

    /**
     * Vérifie le texte envoyé, la lecture des quatre lignes et l'enregistrement.
     */
    public function testSynthesisSendsNoPersonalDataAndIsStored(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $recruiter = $this->createRecruiter('camille.martin@acme-industrie.test')->setMessage('Poste de responsable BE, appelez-moi au 06 12 34 56 78, 5 avenue Jean Jaurès 69000 Lyon.');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        MockHttpResponseFactory::$mistralQueue[] = MockHttpResponseFactory::mistralChat("**Type :** entreprise utilisatrice\nSecteur : industrie\nActivité : fabrication de machines.\nCrédibilité : élevée, domaine cohérent avec le nom.\nRemarque en trop.");

        $synthesis = self::getContainer()->get(CompanySynthesizer::class)->synthesize($recruiter);

        self::assertSame("Type : entreprise utilisatrice\nSecteur : industrie\nActivité : fabrication de machines.\nCrédibilité : élevée, domaine cohérent avec le nom.", $synthesis);
        self::assertSame($synthesis, $recruiter->getCompanySynthesis());
        self::assertNotNull($recruiter->getCompanySynthesisAt());

        $body = MockHttpResponseFactory::requestsTo('mistral.example.test/v1/chat')[0]['body'];
        self::assertStringContainsString('acme-industrie.example', $body);
        foreach (['Camille', 'camille.martin@', '06 12 34 56 78', 'Jean Jaur', '69000'] as $secret) {
            self::assertStringNotContainsString($secret, $body, $secret);
        }
        self::assertSame([AiPurpose::CompanySynthesis], array_column(self::getContainer()->get(AiUsageRepository::class)->summarizeSince(new \DateTimeImmutable('-1 hour')), 'purpose'));
    }

    /**
     * Vérifie que le domaine d'une messagerie grand public n'est pas envoyé et qu'une ligne manquante est signalée.
     */
    public function testPublicMailDomainIsNotSentAndMissingLinesAreMarked(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $recruiter = $this->createRecruiter('contact-3bda@webmail.test');
        MockHttpResponseFactory::$mistralQueue[] = MockHttpResponseFactory::mistralChat('Type : cabinet de recrutement');

        $synthesis = self::getContainer()->get(CompanySynthesizer::class)->synthesize($recruiter);

        self::assertStringNotContainsString('gmail', MockHttpResponseFactory::requestsTo('mistral.example.test/v1/chat')[0]['body']);
        self::assertStringContainsString('Secteur : non précisé', $synthesis);
    }
}
