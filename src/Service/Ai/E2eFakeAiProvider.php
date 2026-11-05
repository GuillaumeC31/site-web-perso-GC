<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Dev\TestContext;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * IA simulée pour les tests de bout en bout (Playwright) : tant que le fichier repère var/e2e/fake-ai
 * existe, les appels à l'IA venus d'une requête de TEST (Dev\TestContext : navigateur de test, compte de test)
 * reçoivent une réponse fixe, sans aucun appel à l'API Mistral. Les vraies questions posées pendant une suite
 * (navigateur normal) vont au vrai fournisseur (décision du 2026-10-29). Sans ce fichier, tout est transmis.
 *
 * Service déclaré SEULEMENT en développement (#[When('dev')]) : il n'existe ni en test (PHPUnit simule
 * l'API par MockHttpClient) ni en production. Le repère est posé et retiré par la commande
 * app:e2e:recruiter-journey (start / cleanup), appelée par le parcours recruteur de Playwright.
 *
 * Liens :
 * - Décore : AiProviderInterface (MistralProvider)
 * - Utilisé par : tests/e2e/recruiter-journey.spec.js (question libre avec consentement)
 * - Voir aussi : App\Command\E2eRecruiterJourneyCommand
 */
#[When(env: 'dev')]
#[AsDecorator(decorates: AiProviderInterface::class)]
final readonly class E2eFakeAiProvider implements AiProviderInterface
{
    /** Fichier repère, relatif au dossier du projet. */
    public const FLAG_FILE = '/var/e2e/fake-ai';

    /** Réponse renvoyée à toute question pendant les tests. */
    public const ANSWER = 'Réponse simulée pour les tests de bout en bout : Guillaume Couget est l’auteur de ce site (réponse simulée).';

    /**
     * Reçoit le vrai fournisseur et le dossier du projet.
     *
     * @param AiProviderInterface $inner         fournisseur décoré (MistralProvider)
     * @param string              $projectDir    dossier du projet (fichier repère)
     * @param string              $primaryModel  modèle principal (MISTRAL_MODEL_PRIMARY)
     * @param string              $fallbackModel modèle de secours (MISTRAL_MODEL_FALLBACK)
     */
    public function __construct(
        #[AutowireDecorated]
        private AiProviderInterface $inner,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(MISTRAL_MODEL_PRIMARY)%')]
        private string $primaryModel,
        #[Autowire('%env(MISTRAL_MODEL_FALLBACK)%')]
        private string $fallbackModel,
        private TestContext $testContext,
    ) {
    }

    /**
     * Renvoie la réponse fixe si le repère existe, sinon la réponse du vrai fournisseur.
     *
     * Liens :
     * - Appelée par : AiCaller (chatbot, synthèse d'entreprise, import de CV)
     */
    public function complete(string $model, array $messages, int $maxTokens, ?float $timeout = null, ?float $temperature = null): AiCompletion
    {
        if (!$this->isActive()) {
            return $this->inner->complete($model, $messages, $maxTokens, $timeout, $temperature);
        }

        return new AiCompletion(self::ANSWER, $model, 100, 30, 5, 'stop');
    }

    /**
     * Liste les modèles : celle du vrai fournisseur, ou les deux modèles configurés pendant les tests (aucun appel).
     *
     * Liens :
     * - Appelée par : AiModelMonitor
     */
    public function listModels(): array
    {
        if (!$this->isActive()) {
            return $this->inner->listModels();
        }

        return array_map(static fn (string $id): array => ['id' => $id, 'chat' => true, 'archived' => false, 'aliases' => []], array_values(array_unique([$this->primaryModel, $this->fallbackModel])));
    }

    /**
     * Indique si le fournisseur est configuré (toujours vrai pendant les tests).
     */
    public function isConfigured(): bool
    {
        return $this->isActive() || $this->inner->isConfigured();
    }

    /**
     * Nom du fournisseur (celui du vrai fournisseur).
     */
    public function getName(): string
    {
        return $this->inner->getName();
    }

    /**
     * Indique si l'IA simulée répond : repère des tests posé ET requête de test (jamais pour une action réelle).
     */
    private function isActive(): bool
    {
        return is_file($this->projectDir.self::FLAG_FILE) && $this->testContext->isTestRequest();
    }
}
