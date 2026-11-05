<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\GuidedAssistant;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Assistant guidé : une réponse pour chaque question prédéfinie, sans IA et sans donnée privée.
 *
 * Liens :
 * - Couvre : GuidedAssistant, templates/ai/guided_answer.txt.twig
 */
final class GuidedAssistantTest extends KernelTestCase
{
    use RecruiterTestTrait;

    /**
     * Vérifie que chaque sujet a une réponse non vide, sans coordonnées privées ni appel à l'IA.
     */
    public function testEveryTopicIsAnsweredWithoutAiNorPrivateData(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->createPrivateProfile(true);
        $guidedAssistant = self::getContainer()->get(GuidedAssistant::class);

        foreach (GuidedAssistant::TOPICS as $topic) {
            $answer = $guidedAssistant->answer($topic);
            self::assertNotSame('', $answer, $topic);
            self::assertStringNotContainsString(self::PRIVATE_PHONE, $answer, $topic);
            self::assertStringNotContainsString(self::PRIVATE_EMAIL, $answer, $topic);
            self::assertStringNotContainsString('XX à YY k€', $answer, $topic);
        }
        // Signal d'intérêt (v2.0.3, point 6) : réponse par e-mail sous 24 h ouvrées, jamais « rapidement »
        self::assertStringContainsString('vous répondra par e-mail sous 24 h ouvrées', $guidedAssistant->answer('contact'));
        self::assertSame([], MockHttpResponseFactory::requestsTo('mistral'));
    }

    /**
     * Vérifie qu'un sujet inconnu est refusé.
     */
    public function testUnknownTopicIsRejected(): void
    {
        self::bootKernel();
        $guidedAssistant = self::getContainer()->get(GuidedAssistant::class);

        self::assertFalse($guidedAssistant->supports('salary'));
        $this->expectException(\InvalidArgumentException::class);
        $guidedAssistant->answer('salary');
    }
}
