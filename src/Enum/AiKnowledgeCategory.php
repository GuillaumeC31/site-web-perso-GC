<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Catégorie d'une fiche de connaissances de l'assistant IA (page « Cerveau de l'IA »).
 *
 * Pas de catégorie « Règle stricte » (l'ancien site en avait une) : les règles restent dans le socle de
 * sécurité fixe (templates/ai/chatbot_instructions.txt.twig), qu'aucune fiche ne peut modifier.
 *
 * Liens :
 * - Utilisé par : AiKnowledgeEntry, AiKnowledgeEntryType, ChatContextBuilder, AiDirectiveImporter
 */
enum AiKnowledgeCategory: string
{
    /** Informations personnelles publiables (mobilité, langues parlées…). */
    case Personal = 'personal';

    /** Préférences de poste (type de contrat, secteur, télétravail…). */
    case JobPreferences = 'job_preferences';

    /** Réponses prêtes aux questions fréquentes des recruteurs. */
    case Faq = 'faq';

    /** Ton et style des réponses (toujours dans les limites du socle). */
    case Tone = 'tone';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : templates/admin/ai_knowledge/*.html.twig, ChatContextBuilder (titre de section)
     */
    public function translationKey(): string
    {
        return 'admin.ai_knowledge.category.'.$this->value;
    }
}
