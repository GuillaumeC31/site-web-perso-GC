<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AiUsage;
use App\Enum\AiPurpose;
use App\Service\Ai\AiCompletion;
use App\Service\Ai\AiProviderException;
use App\Service\Ai\AiProviderInterface;
use App\Service\Ai\ChatContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vérifie le fournisseur d'IA (Mistral AI, offre gratuite) avec la clé du compte, sans jamais l'afficher :
 *
 * 1. modèles disponibles pour le compte (GET /v1/models) ;
 * 2. appel court pour chaque modèle configuré (MISTRAL_MODEL_PRIMARY, MISTRAL_MODEL_FALLBACK) ou demandé
 *    avec --model : disponibilité, durée, jetons ;
 * 3. avec --measure : une vraie question du chatbot (consignes + contenu public du CV + question + réponse)
 *    pour mesurer les jetons réels d'une question, base du calcul des plafonds.
 *
 * Chaque appel compte dans les limites du compte gratuit : la commande en fait peu (1 par modèle, +1 avec --measure),
 * et ses jetons sont comptés dans le plafond mensuel (AiUsage, usage « Vérification »).
 *
 * Usage :
 *   php bin/console app:ai:check
 *   php bin/console app:ai:check --model=ministral-8b-2512 --model=mistral-small-2603 --measure
 *
 * Liens :
 * - Utilise : AiProviderInterface (MistralProvider), ChatContextBuilder, AiUsage (consommation)
 * - Testée par : tests/Command/AiCheckCommandTest (client HTTP simulé)
 */
#[AsCommand(name: 'app:ai:check', description: 'Vérifie Mistral AI : modèles du compte, modèles configurés, jetons réels d\'une question (clé jamais affichée)')]
final class AiCheckCommand extends Command
{
    /** Question type utilisée pour mesurer les jetons réels. */
    public const MEASURE_QUESTION = 'Quelles sont les principales expériences de Guillaume Couget en bureau d\'études ?';

    /** Longueur maximale de la réponse pour la mesure (3 phrases). */
    public const MEASURE_MAX_TOKENS = 200;

    /**
     * Reçoit les dépendances et les modèles configurés.
     *
     * @param string $primaryModel  modèle principal (MISTRAL_MODEL_PRIMARY)
     * @param string $fallbackModel modèle de secours (MISTRAL_MODEL_FALLBACK)
     */
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly ChatContextBuilder $contextBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire('%env(MISTRAL_MODEL_PRIMARY)%')]
        private readonly string $primaryModel,
        #[Autowire('%env(MISTRAL_MODEL_FALLBACK)%')]
        private readonly string $fallbackModel,
    ) {
        parent::__construct();
    }

    /**
     * Déclare les options --model et --measure.
     *
     * Liens :
     * - Appelée par : la console Symfony
     */
    protected function configure(): void
    {
        $this
            ->addOption('model', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Modèle à tester en plus des modèles configurés (répétable)')
            ->addOption('measure', null, InputOption::VALUE_NONE, 'Mesurer les jetons réels d\'une question du chatbot (un appel de plus)');
    }

    /**
     * Liste les modèles, teste les modèles configurés et, avec --measure, mesure une question réelle.
     *
     * Liens :
     * - Appelée par : la console Symfony
     * - Utilise : AiProviderInterface::listModels(), ::complete() ; ChatContextBuilder::buildMessages()
     *
     * @return int Command::SUCCESS si tous les modèles testés répondent, sinon Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vérification de '.$this->provider->getName());

        if (!$this->provider->isConfigured()) {
            $io->error('Clé d\'API absente : renseignez MISTRAL_API_KEY dans .env.local.');

            return Command::FAILURE;
        }
        $io->text('Clé d\'API : renseignée (jamais affichée).');

        try {
            $models = $this->provider->listModels();
        } catch (AiProviderException $exception) {
            $io->error('Liste des modèles impossible : '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('Modèles disponibles pour ce compte (%d)', \count($models)));
        $io->table(['Identifiant', 'Conversation', 'Archivé', 'Alias'], array_map(static fn (array $m): array => [
            $m['id'], $m['chat'] ? 'oui' : 'non', $m['archived'] ? 'oui' : 'non', implode(', ', $m['aliases']),
        ], $models));

        $requested = array_filter(array_map('strval', (array) $input->getOption('model')));
        $toTest = array_values(array_unique(array_filter([$this->primaryModel, $this->fallbackModel, ...$requested])));
        if ([] === $toTest) {
            $io->warning('Aucun modèle configuré (MISTRAL_MODEL_PRIMARY, MISTRAL_MODEL_FALLBACK) ni demandé (--model) : aucun appel de test.');

            return Command::SUCCESS;
        }

        $known = array_merge(...array_map(static fn (array $m): array => [$m['id'], ...$m['aliases']], $models));
        $rows = [];
        $rateLimits = [];
        $failures = 0;
        foreach ($toTest as $model) {
            $role = match ($model) {
                $this->primaryModel => 'principal',
                $this->fallbackModel => 'secours',
                default => 'test',
            };
            try {
                $completion = $this->provider->complete($model, [['role' => 'user', 'content' => 'Réponds seulement : OK']], 10);
                $this->recordUsage($completion);
                $rows[] = [$model, $role, \in_array($model, $known, true) ? 'oui' : 'non', 'répond ('.$completion->content.')', $completion->durationMs.' ms', $completion->totalTokens()];
                $rateLimits = [] === $completion->rateLimits ? $rateLimits : $completion->rateLimits;
            } catch (AiProviderException $exception) {
                ++$failures;
                $rows[] = [$model, $role, \in_array($model, $known, true) ? 'oui' : 'non', 'ERREUR : '.$exception->getMessage(), '—', '—'];
            }
        }
        $io->section('Modèles testés (appel court)');
        $io->table(['Modèle', 'Rôle', 'Dans la liste', 'Résultat', 'Durée', 'Jetons'], $rows);

        // Limites de débit annoncées par l'API (en-têtes du dernier appel réussi ; jamais la clé)
        $io->section('Limites de débit renvoyées par l\'API (en-têtes de réponse)');
        if ([] === $rateLimits) {
            $io->text('Aucun en-tête de limite (x-ratelimit-… ou ratelimit-…) dans les réponses : relever les limites dans la console Mistral (Admin › API › Limits).');
        } else {
            $io->table(['En-tête', 'Valeur'], array_map(static fn (string $name, string $value): array => [$name, $value], array_keys($rateLimits), $rateLimits));
        }

        if ($input->getOption('measure')) {
            $model = $toTest[0];
            $io->section(\sprintf('Mesure d\'une question réelle (modèle %s)', $model));
            try {
                $messages = $this->contextBuilder->buildMessages($this->provider->getName(), $model, self::MEASURE_QUESTION);
                $completion = $this->provider->complete($model, $messages, self::MEASURE_MAX_TOKENS);
                $this->recordUsage($completion);
                $io->definitionList(
                    ['Question' => self::MEASURE_QUESTION],
                    ['Consignes + contenu public' => \sprintf('%d caractères', mb_strlen($messages[0]['content']))],
                    ['Jetons envoyés (consignes + contenu + question)' => $completion->promptTokens],
                    ['Jetons de la réponse' => $completion->completionTokens],
                    ['Total par question' => $completion->totalTokens()],
                    ['Durée' => $completion->durationMs.' ms'],
                    ['Réponse' => $completion->content],
                );
            } catch (AiProviderException $exception) {
                ++$failures;
                $io->error('Mesure impossible : '.$exception->getMessage());
            }
        }

        if ($failures > 0) {
            $io->warning(\sprintf('%d appel(s) en erreur : voir le tableau ci-dessus.', $failures));

            return Command::FAILURE;
        }
        $io->success('Fournisseur d\'IA opérationnel pour les modèles testés.');

        return Command::SUCCESS;
    }

    /**
     * Enregistre les jetons d'un appel réussi (plafond mensuel, usage « Vérification »).
     */
    private function recordUsage(AiCompletion $completion): void
    {
        $this->entityManager->persist(new AiUsage($this->clock->now(), AiPurpose::Check, $completion->model, $completion->promptTokens, $completion->completionTokens));
        $this->entityManager->flush();
    }
}
