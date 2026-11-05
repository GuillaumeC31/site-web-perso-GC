<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\ChatMessage;
use App\Entity\Recruiter;
use App\Entity\UnansweredQuestion;
use App\Enum\AiPurpose;
use App\Enum\ChatOutcome;
use App\Repository\AiKnowledgeEntryRepository;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Chatbot de l'espace recruteur : traite une question libre de bout en bout.
 *
 * 1. Retire e-mails et téléphones de la question (rien de tel n'est envoyé ni enregistré).
 * 2. Question libre désactivée dans l'admin → assistant guidé.
 * 3. Tentative de détournement → refus poli, sans appel au modèle, tracée comme suspecte.
 * 4. Plafond atteint (recruteur, site, mois) → assistant guidé.
 * 5. Modèle principal, puis modèle de secours (AiCaller), température 0 ; échec des deux → assistant guidé.
 * 6. Réponse : texte brut limité à 3 phrases, durées corrigées, e-mails et téléphones retirés, bloquée si elle
 *    reprend les consignes ; CONTRÔLE AVANT AFFICHAGE (AnswerFactChecker) : un élément absent du contexte
 *    (date, nombre, nom…) fait remplacer la réponse par « Ce point n'est pas précisé… » (réponse bloquée).
 * 7. Information absente ou réponse bloquée : la question est gardée dans « Questions sans réponse », sans
 *    donnée du recruteur.
 * 8. Tout est noté dans le journal (ChatMessage : question, réponse, modèle, jetons, erreur, élément bloqué).
 *
 * Le banc d'essai de l'admin (test()) suit les étapes 1, 3, 5 et 6, sans plafond ni question sans réponse.
 *
 * Liens :
 * - Utilisé par : RecruiterAreaController::assistantAsk(), Admin\AiKnowledgeController::test()
 * - Utilise : PrivateDataFilter, ChatGuard, AiQuota, AiCaller, ChatContextBuilder, AnswerFormatter, AnswerFactChecker
 * - Testé par : tests/Service/Ai/ChatAssistantTest, tests/Service/Ai/AiKnowledgeAssistantTest (fournisseur simulé)
 */
final readonly class ChatAssistant
{
    /** Longueur maximale d'une question (caractères). */
    public const MAX_QUESTION_LENGTH = 500;

    /** Longueur maximale de la réponse demandée au modèle (jetons). */
    public const MAX_TOKENS = 200;

    /** Température du chatbot : la plus basse (réponse la plus fidèle au contexte). */
    public const TEMPERATURE = 0.0;

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private PrivateDataFilter $privateDataFilter,
        private ChatGuard $guard,
        private AiQuota $quota,
        private AiCaller $caller,
        private AiProviderInterface $provider,
        private ChatContextBuilder $contextBuilder,
        private AnswerFormatter $formatter,
        private AnswerFactChecker $factChecker,
        private AiKnowledgeEntryRepository $entryRepository,
        private SiteSettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Répond à une question libre d'un recruteur et l'enregistre dans le journal.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::assistantAsk() (route recruiter_assistant_ask)
     *
     * @param Recruiter $recruiter recruteur connecté (consentement déjà vérifié par le contrôleur)
     * @param string    $question  question saisie (500 caractères au plus)
     *
     * @return ChatReply échange enregistré et données personnelles retirées de la question
     */
    public function ask(Recruiter $recruiter, string $question): ChatReply
    {
        $filtered = $this->privateDataFilter->filterContact(mb_substr(trim($question), 0, self::MAX_QUESTION_LENGTH));
        $question = $filtered['text'];
        $now = $this->clock->now();

        if (!$this->settingsRepository->getOrCreate()->isAiAssistantEnabled()) {
            return $this->save(new ChatMessage($recruiter, $now, $question, $this->trans('assistant.fallback.disabled'), ChatOutcome::Disabled), $filtered['removed']);
        }

        $injection = $this->guard->detectInjection($question);
        if (null !== $injection) {
            $message = new ChatMessage($recruiter, $now, $question, ChatGuard::REFUSAL, ChatOutcome::Refused);
            $message->setSuspiciousReason($injection);

            return $this->save($message, $filtered['removed']);
        }

        $limit = $this->quota->getReachedLimit($recruiter);
        if (null !== $limit) {
            return $this->save(new ChatMessage($recruiter, $now, $question, $this->trans('assistant.fallback.'.$limit), ChatOutcome::LimitReached), $filtered['removed']);
        }

        $message = $this->answerWithAi($recruiter, $now, $question, AiPurpose::Chatbot)['message'];
        if ($this->isUnanswered($message)) {
            $this->entityManager->persist(new UnansweredQuestion(
                $now,
                $question,
                $message->isBlocked() ? UnansweredQuestion::REASON_BLOCKED : UnansweredQuestion::REASON_MISSING,
                $message->getBlockedElement(),
            ));
        }

        return $this->save($message, $filtered['removed']);
    }

    /**
     * Essai de l'assistant par l'admin (banc d'essai) : même chaîne que pour un recruteur, sans plafond de
     * questions ni « question sans réponse » ; jetons comptés (usage « essai admin ») ; échange journalisé
     * comme essai admin.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController::test() (route admin_ai_knowledge_test)
     *
     * @param string $question question posée par l'admin
     *
     * @return ChatTestResult échange, fiches utilisées, réponse brute si elle a été bloquée
     */
    public function test(string $question): ChatTestResult
    {
        $question = $this->privateDataFilter->filterContact(mb_substr(trim($question), 0, self::MAX_QUESTION_LENGTH))['text'];
        $now = $this->clock->now();

        $injection = $this->guard->detectInjection($question);
        if (null !== $injection) {
            $message = (new ChatMessage(null, $now, $question, ChatGuard::REFUSAL, ChatOutcome::Refused))->setSuspiciousReason($injection)->setAdminTest(true);
            $this->save($message, []);

            return new ChatTestResult($message, []);
        }

        $result = $this->answerWithAi(null, $now, $question, AiPurpose::AdminTest);
        $message = $result['message']->setAdminTest(true);
        $this->save($message, []);
        $used = $this->factChecker->findUsedEntries(
            $result['raw'] ?? $message->getAnswer(),
            $this->entryRepository->findActiveOrdered(),
            $this->contextBuilder->buildSystemPrompt($this->provider->getName(), (string) $message->getModel(), false),
        );

        return new ChatTestResult($message, $used, $message->isBlocked() ? $result['raw'] : null);
    }

    /**
     * Interroge le modèle et contrôle la réponse avant affichage.
     *
     * @return array{message: ChatMessage, raw: string|null} échange (non enregistré) et réponse mise en forme avant tout remplacement
     */
    private function answerWithAi(?Recruiter $recruiter, \DateTimeImmutable $now, string $question, AiPurpose $purpose): array
    {
        $providerName = $this->provider->getName();
        $contexts = [];
        try {
            $result = $this->caller->call(
                $purpose,
                function (string $model) use ($providerName, $question, &$contexts): array {
                    $messages = $this->contextBuilder->buildMessages($providerName, $model, $question);
                    $contexts[$model] = $messages[0]['content'];

                    return $messages;
                },
                self::MAX_TOKENS,
                self::TEMPERATURE,
            );
        } catch (AiProviderException $exception) {
            $key = AiProviderException::RATE_LIMITED === $exception->reason ? 'assistant.fallback.busy' : 'assistant.fallback.unavailable';
            $message = new ChatMessage($recruiter, $now, $question, $this->trans($key), ChatOutcome::GuidedFallback);
            $message->setError(mb_substr($exception->reason.' : '.$exception->getMessage(), 0, 255));

            return ['message' => $message, 'raw' => null];
        }

        $completion = $result->completion;
        $answer = $this->privateDataFilter->filterContact($this->guard->fixTotalExperience($this->formatter->format($completion->content)))['text'];
        $raw = $answer;
        $suspicious = null;
        $blocked = null;
        if ($this->guard->leaksInstructions($answer, $this->contextBuilder->buildInstructions($providerName, $completion->model))) {
            $answer = ChatGuard::REFUSAL;
            $suspicious = 'fuite des consignes bloquée';
        } elseif ('' === $answer) {
            $answer = ChatGuard::OFF_TOPIC;
        } else {
            // Contexte réellement envoyé au modèle qui a répondu (principal ou secours)
            $context = $contexts[$completion->model] ?? (end($contexts) ?: '');
            $blocked = $this->factChecker->findUnsupported($answer, $context);
            if (null !== $blocked) {
                $answer = ChatGuard::NOT_SPECIFIED;
            }
        }

        $message = new ChatMessage($recruiter, $now, $question, $answer, $result->fallback ? ChatOutcome::AiFallback : ChatOutcome::AiPrimary);
        $message->setAiResult($completion->model, $completion->promptTokens, $completion->completionTokens, $completion->durationMs);
        $message->setSuspiciousReason($suspicious);
        $message->setBlockedElement($blocked);

        return ['message' => $message, 'raw' => $raw];
    }

    /**
     * Indique si l'assistant n'a pas su répondre : information absente (réponse prévue par le socle) ou
     * réponse bloquée par le contrôle avant affichage.
     *
     * Liens :
     * - Appelée par : ask() ; templates/recruiter_area/_assistant.html.twig (lien vers le formulaire, via
     *   isNotSpecifiedAnswer())
     */
    public function isUnanswered(ChatMessage $message): bool
    {
        return $message->getOutcome()->isAi() && ($message->isBlocked() || self::isNotSpecifiedAnswer($message->getAnswer()));
    }

    /**
     * Indique si une réponse est (ou contient) « Ce point n'est pas précisé… » : l'assistant affiche alors le
     * lien vers le formulaire de question de l'espace recruteur.
     *
     * Liens :
     * - Appelée par : isUnanswered(), ChatMessage::isNotSpecified()
     */
    public static function isNotSpecifiedAnswer(string $answer): bool
    {
        $normalize = static fn (string $text): string => mb_strtolower(str_replace(['’', "\u{A0}", "\u{202F}"], ["'", ' ', ' '], $text));

        return str_contains($normalize($answer), "n'est pas précisé");
    }

    /**
     * Enregistre l'échange dans le journal.
     *
     * @param list<string> $removed types de données retirées de la question
     */
    private function save(ChatMessage $message, array $removed): ChatReply
    {
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return new ChatReply($message, $removed);
    }

    /**
     * Traduit un message de l'assistant.
     */
    private function trans(string $key): string
    {
        return $this->translator->trans($key);
    }
}
