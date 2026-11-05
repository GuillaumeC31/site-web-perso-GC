<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Content\CvContentProvider;
use Twig\Environment;

/**
 * Assistant guidé de l'espace recruteur : questions prédéfinies, réponses tirées du contenu public du CV,
 * sans IA ni consentement. Toujours disponible ; c'est aussi la solution de repli quand l'IA ne répond
 * pas (erreur 429, panne, délai dépassé, plafond atteint, IA désactivée).
 *
 * Liens :
 * - Utilisé par : RecruiterAreaController::assistantGuided(), templates/recruiter_area/_assistant.html.twig
 * - Utilise : CvContentProvider::getContent(), templates/ai/guided_answer.txt.twig
 * - Testé par : tests/Service/Ai/GuidedAssistantTest
 */
final readonly class GuidedAssistant
{
    /** Sujets des questions guidées, dans l'ordre d'affichage (libellés : assistant.guided.<sujet>). */
    public const TOPICS = ['profile', 'experience', 'skills', 'search', 'education', 'contact'];

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private CvContentProvider $cvContentProvider,
        private Environment $twig,
    ) {
    }

    /**
     * Indique si le sujet fait partie des questions guidées.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::assistantGuided()
     */
    public function supports(string $topic): bool
    {
        return \in_array($topic, self::TOPICS, true);
    }

    /**
     * Renvoie la réponse à une question guidée, en texte brut.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::assistantGuided()
     *
     * @param string $topic sujet (self::TOPICS)
     *
     * @throws \InvalidArgumentException si le sujet est inconnu
     */
    public function answer(string $topic): string
    {
        if (!$this->supports($topic)) {
            throw new \InvalidArgumentException(\sprintf('Question guidée inconnue : %s.', $topic));
        }
        $text = $this->twig->render('ai/guided_answer.txt.twig', ['topic' => $topic] + $this->cvContentProvider->getContent());

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
