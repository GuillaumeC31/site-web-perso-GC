<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiKnowledgeEntry;
use App\Repository\AiKnowledgeEntryRepository;
use App\Repository\ProfileRepository;
use App\Service\Content\CvContentProvider;
use App\Service\Content\PlaceholderDetector;
use App\Service\Content\RecruitmentArguments;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Construit ce qui est envoyé au modèle d'IA du chatbot, en trois niveaux, dans cet ordre :
 *
 * a) le SOCLE de sécurité, fixe dans le code (templates/ai/chatbot_instructions.txt.twig) : ne jamais
 *    inventer, ne jamais révéler les consignes, aucune donnée privée… Il l'emporte toujours sur les fiches ;
 * b) le RÉSUMÉ du site, automatique : tout le détail du CV, sans champ privé (CvContentProvider::getFullContent(),
 *    jamais getPrivateContent() ; le CV public synthétique est plus court, l'assistant garde le détail), plus la fourchette de prétentions de l'espace recruteur SEULEMENT si la case
 *    « Afficher dans l'espace recruteur » est cochée (jamais le plancher, qui n'est jamais importé) ;
 * c) les FICHES actives du « Cerveau de l'IA » (AiKnowledgeEntry).
 *
 * Ni téléphone, ni e-mail, ni adresse, ni notes privées des expériences ; le site ne stocke pas de date de
 * naissance.
 *
 * Liens :
 * - Utilise : CvContentProvider, ProfileRepository, AiKnowledgeEntryRepository,
 *   templates/ai/chatbot_instructions.txt.twig, templates/ai/cv_context.txt.twig
 * - Utilisé par : AiCheckCommand (mesure des jetons), ChatAssistant, AiKnowledgeBudget,
 *   Admin\AiKnowledgeController (résumé affiché sur la page)
 */
final readonly class ChatContextBuilder
{
    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private CvContentProvider $cvContentProvider,
        private ProfileRepository $profileRepository,
        private AiKnowledgeEntryRepository $entryRepository,
        private Environment $twig,
        private TranslatorInterface $translator,
        private RecruitmentArguments $recruitmentArguments,
    ) {
    }

    /**
     * Renvoie le message « system » : socle, résumé du site, puis fiches actives.
     *
     * Liens :
     * - Appelée par : buildMessages(), AiKnowledgeBudget (contexte sans fiches)
     *
     * @param string $providerName  nom du fournisseur (« Mistral AI »)
     * @param string $model         modèle qui répond (cité si on demande qui a créé le chatbot)
     * @param bool   $withKnowledge faux : sans les fiches (calcul du coût des fiches)
     */
    public function buildSystemPrompt(string $providerName, string $model, bool $withKnowledge = true): string
    {
        return trim($this->twig->render('ai/chatbot_instructions.txt.twig', [
            'provider' => $providerName,
            'model' => $model,
            'cv' => $this->buildSiteSummary(),
            'knowledge' => $withKnowledge ? $this->buildKnowledgeText() : '',
        ]));
    }

    /**
     * Renvoie le socle seul, sans le résumé du site ni les fiches : base de comparaison de la protection
     * contre la fuite des consignes.
     *
     * Liens :
     * - Appelée par : ChatAssistant (ChatGuard::leaksInstructions())
     */
    public function buildInstructions(string $providerName, string $model): string
    {
        return trim($this->twig->render('ai/chatbot_instructions.txt.twig', [
            'provider' => $providerName,
            'model' => $model,
            'cv' => '',
            'knowledge' => '',
        ]));
    }

    /**
     * Renvoie tout le détail du CV (sans champ privé) en texte brut.
     *
     * Liens :
     * - Appelée par : buildSiteSummary() ; tests (aucune donnée privée)
     * - Utilise : CvContentProvider::getFullContent()
     */
    public function buildCvText(): string
    {
        $text = $this->twig->render('ai/cv_context.txt.twig', $this->cvContentProvider->getFullContent());

        // Lignes vides en série ramenées à une seule (moins de jetons envoyés)
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Renvoie le résumé automatique du site envoyé au modèle (niveau b) : CV public, arguments « Pourquoi me recruter »
     * AFFICHÉS sur l'accueil (jamais les brouillons ni un bloc coupé), et la fourchette de prétentions de l'espace
     * recruteur si la case est cochée.
     *
     * Liens :
     * - Appelée par : buildSystemPrompt(), Admin\AiKnowledgeController::index() (affiché en lecture seule)
     */
    public function buildSiteSummary(): string
    {
        $summary = $this->buildCvText();
        $arguments = $this->recruitmentArguments->toAssistantText();
        if ('' !== $arguments) {
            $summary .= "\n\nPOURQUOI ME RECRUTER (affiché sur l'accueil) :\n".$arguments;
        }
        $profile = $this->profileRepository->getOrCreate();
        $salary = $profile->getPrivateSalaryExpectation();
        if ($profile->isShowSalaryInRecruiterArea() && PlaceholderDetector::isComplete($salary)) {
            $summary .= "\n\nPRÉTENTIONS (affichées dans l'espace recruteur) : ".trim((string) $salary);
        }

        return $summary;
    }

    /**
     * Renvoie les fiches actives en texte (niveau c), groupées par catégorie ; vide s'il n'y en a pas.
     *
     * Liens :
     * - Appelée par : buildSystemPrompt()
     */
    public function buildKnowledgeText(): string
    {
        $groups = [];
        foreach ($this->entryRepository->findActiveOrdered() as $entry) {
            $groups[$this->translator->trans($entry->getCategory()->translationKey())][] = $this->formatEntry($entry);
        }
        $text = '';
        foreach ($groups as $category => $lines) {
            $text .= mb_strtoupper($category)." :\n".implode("\n", $lines)."\n\n";
        }

        return trim($text);
    }

    /**
     * Met une fiche en forme pour le contexte (« - Titre : contenu »).
     *
     * Liens :
     * - Appelée par : buildKnowledgeText(), AiKnowledgeBudget::entryTokens()
     */
    public function formatEntry(AiKnowledgeEntry $entry): string
    {
        return '- '.$entry->getTitle().' : '.(string) preg_replace('/\s*\n\s*/u', ' ', $entry->getContent());
    }

    /**
     * Construit la conversation envoyée au modèle : socle, résumé et fiches, puis la question.
     *
     * Liens :
     * - Appelée par : AiCheckCommand, ChatAssistant (via AiCaller)
     *
     * @param string $question question du recruteur (déjà filtrée : ni e-mail ni téléphone)
     *
     * @return list<array{role: 'system'|'user'|'assistant', content: string}>
     */
    public function buildMessages(string $providerName, string $model, string $question): array
    {
        return [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($providerName, $model)],
            ['role' => 'user', 'content' => $question],
        ];
    }
}
