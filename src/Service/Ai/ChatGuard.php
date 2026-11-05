<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Protection des consignes du chatbot :
 * - avant l'envoi : repère les tentatives de détournement (demande des consignes, changement de rôle,
 *   « ignore tes règles »…) ; elles reçoivent un refus poli, sans appel au modèle, et sont tracées ;
 * - après la réponse : bloque toute réponse qui reprendrait un passage des consignes.
 *
 * Liens :
 * - Utilisé par : ChatAssistant::ask()
 * - Voir aussi : templates/ai/chatbot_instructions.txt.twig (les consignes protégées)
 * - Testé par : tests/Service/Ai/ChatGuardTest, tests/Service/Ai/ChatAssistantTest
 */
final class ChatGuard
{
    /** Réponse aux tentatives de détournement et aux réponses qui reprendraient les consignes. */
    public const REFUSAL = 'Je ne peux pas partager mes consignes ; je peux en revanche répondre à vos questions sur le parcours de Guillaume Couget.';

    /** Réponse prévue par le socle pour une question hors sujet (aucune connaissance générale). */
    public const OFF_TOPIC = 'Je ne peux répondre que sur le parcours de Guillaume.';

    /**
     * Réponse prévue par le socle quand l'information est absente du site et des fiches, et réponse affichée à
     * la place d'une réponse bloquée par le contrôle avant affichage (AnswerFactChecker). L'assistant ajoute le
     * lien vers le formulaire de question de l'espace recruteur.
     */
    public const NOT_SPECIFIED = 'Ce point n\'est pas précisé ; vous pouvez poser la question à Guillaume via le formulaire de contact.';

    /** Motifs de détournement (accents tolérés) : motif => libellé tracé dans le journal. */
    private const INJECTION_PATTERNS = [
        '/(?:\b(?:affich|montr|donn|r[ée]v[èe]l|r[ée]p[èe]t|recopi|imprim|list|partag|[ée]cri)\w*|dis[- ]moi|quelles? sont)\b.{0,40}\b(?:tes|vos|les|ton|votre)\s+(?:consignes|instructions|r[èe]gles|directives|prompt|messages? syst[èe]me)/iu' => 'demande des consignes',
        '/\b(?:system\s*prompt|prompt\s+syst[èe]me|message\s+syst[èe]me|initial\s+prompt|your\s+(?:instructions|rules|prompt))\b/iu' => 'demande des consignes',
        '/\b(?:ignore|oublie|ne\s+tiens\s+pas\s+compte\s+de|contourne|d[ée]sactive)\b.{0,40}\b(?:consignes|instructions|r[èe]gles|directives|pr[ée]c[ée]dent|ci-dessus|above|previous)/iu' => 'contournement des règles',
        '/\b(?:ignore|disregard|forget)\b.{0,30}\b(?:instructions|rules|above|previous)\b/iu' => 'contournement des règles',
        '/\b(?:tu\s+es\s+(?:maintenant|d[ée]sormais)|fais\s+comme\s+si\s+tu\s+[ée]tais|joue\s+le\s+r[ôo]le|you\s+are\s+now|act\s+as|pretend\s+to\s+be|developer\s+mode|jailbreak|DAN)\b/iu' => 'changement de rôle',
        '/(?:r[ée]p[èe]te|recopie|affiche)\b.{0,30}\b(?:ce\s+qui\s+pr[ée]c[èe]de|tout\s+ce\s+qui\s+est\s+au[- ]dessus|le\s+texte\s+ci-dessus|mot\s+pour\s+mot)/iu' => 'demande des consignes',
    ];

    /**
     * Passages des consignes que le modèle a le droit de reprendre (réponses prévues) : retirés des
     * consignes avant la comparaison, pour ne pas bloquer une réponse normale.
     */
    private const ALLOWED_PHRASES = [
        self::REFUSAL,
        self::OFF_TOPIC,
        self::NOT_SPECIFIED,
        "c'est Guillaume qui a développé et intégré ce chatbot lui-même sur son site",
        "d'adresse postale, de numéro de téléphone, d'adresse e-mail ni de date de naissance",
        'propose le formulaire de contact du site',
        "près de 20 ans d'expérience au total, dont plusieurs postes en génie climatique",
    ];

    /**
     * Durée totale attribuée à un domaine (« près de 20 ans d'expérience en génie climatique ») : à partir de
     * 20 ans, c'est forcément la durée de tout le parcours (aucun poste du CV ne dure aussi longtemps).
     */
    private const TOTAL_EXPERIENCE_IN_DOMAIN = '/\b((?:près\s+de|presque|environ|plus\s+de)\s+)?([2-9]\d)\s+ans\s+d[\'’]expérience\s+(?:en|dans)\s+[^,.;:!?]+/iu';

    /**
     * Durées calculées par le modèle pour un domaine (« sur plus de 20 ans », « une expérience de 15 ans en… ») :
     * de 13 à 27 ans, plus long que tout poste du CV (12 ans au plus) et différent de la durée totale.
     */
    private const DOMAIN_DURATIONS = [
        '/\bexpérience\s+de\s+(?:(?:plus\s+de|près\s+de|presque|environ)\s+)?(?:1[3-9]|2[0-7])\s+ans\s+(?:en|dans)\s+[^,.;:!?]+/iu' => 'expérience acquise au fil de son parcours',
        '/\b(?:sur|depuis|pendant|durant)\s+(?:(?:plus\s+de|près\s+de|presque|environ)\s+)?(?:1[3-9]|2[0-7])\s+ans\b/iu' => 'au fil de son parcours',
    ];

    /** Longueur d'un passage des consignes repris mot pour mot dans une réponse pour la bloquer (caractères). */
    private const LEAK_MIN_LENGTH = 60;

    /** Pas entre deux passages comparés (caractères). */
    private const LEAK_STEP = 5;

    /**
     * Renvoie le motif d'un détournement suspecté, ou null.
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask() (avant tout appel au modèle)
     */
    public function detectInjection(string $question): ?string
    {
        foreach (self::INJECTION_PATTERNS as $pattern => $reason) {
            if (1 === preg_match($pattern, $question)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Corrige une durée totale d'expérience attribuée à un seul domaine (« près de 20 ans d'expérience en
     * génie climatique » devient « près de 20 ans d'expérience au total ») et les durées par domaine calculées
     * par le modèle, absentes du CV (« sur plus de 20 ans » devient « au fil de son parcours »).
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask() (après la mise en forme de la réponse)
     *
     * @return string la réponse corrigée (inchangée si rien n'est trouvé)
     */
    public function fixTotalExperience(string $answer): string
    {
        $answer = (string) preg_replace(self::TOTAL_EXPERIENCE_IN_DOMAIN, '$1$2 ans d\'expérience au total', $answer);

        return (string) preg_replace(array_keys(self::DOMAIN_DURATIONS), array_values(self::DOMAIN_DURATIONS), $answer);
    }

    /**
     * Indique si la réponse reprend un passage des consignes (60 caractères consécutifs d'une phrase des
     * consignes, hors contenu du CV et hors réponses prévues : refus, hors sujet, auteur du chatbot).
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask() (après la réponse du modèle)
     *
     * @param string $answer       réponse du modèle
     * @param string $instructions consignes seules (ChatContextBuilder::buildInstructions())
     */
    public function leaksInstructions(string $answer, string $instructions): bool
    {
        $normalizedAnswer = $this->normalize($answer);
        // Une ligne des consignes par élément, réponses prévues retirées
        $lines = array_map($this->normalize(...), preg_split('/\R/u', $instructions) ?: []);
        foreach (self::ALLOWED_PHRASES as $phrase) {
            $lines = str_replace($this->normalize($phrase), '|', $lines);
        }

        foreach ($lines as $line) {
            foreach (explode('|', $line) as $passage) {
                $passage = trim($passage);
                // Fenêtres de 60 caractères du passage, tous les 5 caractères
                for ($start = 0; $start + self::LEAK_MIN_LENGTH <= mb_strlen($passage); $start += self::LEAK_STEP) {
                    if (str_contains($normalizedAnswer, mb_substr($passage, $start, self::LEAK_MIN_LENGTH))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Normalise un texte pour la comparaison : minuscules, apostrophes droites, sans guillemets ni gras,
     * espaces regroupés.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace(['’', '«', '»', '"', '*'], ["'", '', '', '', ''], $text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
