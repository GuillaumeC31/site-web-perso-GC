<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Usage d'un appel au fournisseur d'IA (compteur de consommation).
 *
 * Liens :
 * - Utilisé par : AiUsage, AiUsageRecorder, admin (consommation)
 */
enum AiPurpose: string
{
    /** Question libre d'un recruteur (chatbot). */
    case Chatbot = 'chatbot';

    /** Analyse d'un CV importé dans l'admin. */
    case CvImport = 'cv_import';

    /** Synthèse de l'entreprise d'un recruteur, dans l'admin. */
    case CompanySynthesis = 'company_synthesis';

    /** Vérification technique (app:ai:check). */
    case Check = 'check';

    /** Essai de l'assistant par l'admin (banc d'essai du « Cerveau de l'IA ») : jetons comptés, hors plafonds des recruteurs. */
    case AdminTest = 'admin_test';

    /**
     * Renvoie la clé de traduction du libellé.
     */
    public function translationKey(): string
    {
        return 'assistant.purpose.'.$this->value;
    }
}
