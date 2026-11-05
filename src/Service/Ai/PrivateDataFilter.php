<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Retire d'un texte les données personnelles avant de l'envoyer à l'IA ou de l'afficher : adresses e-mail,
 * numéros de téléphone, adresses postales, dates de naissance (et âge déclaré).
 *
 * Utilisé dans les deux sens : question du recruteur avant l'envoi, réponse du modèle avant l'affichage ;
 * et sur le texte d'un CV (import) ou d'une demande de recruteur (synthèse) avant l'envoi.
 *
 * Liens :
 * - Utilisé par : ChatAssistant, CvImportAnalyzer, CompanySynthesizer
 * - Testé par : tests/Service/Ai/PrivateDataFilterTest
 */
final class PrivateDataFilter
{
    /** Texte de remplacement. */
    public const MASK = '[information retirée]';

    /** Adresse e-mail. */
    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu';

    /** Téléphone : français (06 12 34 56 78, 06.12.34.56.78, +33 6 12…, 0033…) ou international (+44 20…). */
    private const PHONE = '/(?<![\w\/])(?:(?:\+|00)33[\s.-]?\(?0?\)?[\s.-]?[1-9](?:[\s.-]?\d{2}){4}|0[1-9](?:[\s.-]?\d{2}){4}|\+\d{1,3}(?:[\s.-]?\d{2,4}){3,5})(?![\w\/])/u';

    /** Adresse postale : numéro, type de voie et nom (« 12 bis rue des Lilas »), puis code postal et ville éventuels. */
    private const ADDRESS = '/\b\d{1,4}\s*(?:bis|ter|b|t)?\s*,?\s*(?:rue|avenue|av\.?|boulevard|bd|chemin|ch\.?|allée|allee|impasse|place|route|rte|quai|cours|square|lotissement|lieu[- ]dit|résidence|residence|voie|sentier|passage|faubourg|cité|cite)\b[^\n,;]{0,60}(?:,?\s*\d{5}\s+[\p{L}][\p{L}\'\- ]{1,40})?/iu';

    /** Code postal suivi d'une ville (« 69500 Lyon ») : partie d'adresse. */
    private const POSTCODE_CITY = '/\b\d{5}\s+[\p{Lu}][\p{L}\'\-]+(?:[ \-][\p{L}\'\-]+){0,3}\b/u';

    /** Date de naissance : « né(e) le … », « date de naissance : … », et toute date complète qui suit. */
    private const BIRTH = '/\b(?:n[ée]e?\s+le|date\s+de\s+naissance|naissance)\s*:?\s*(?:\d{1,2}[\/.\-\s]\d{1,2}[\/.\-\s]\d{2,4}|\d{1,2}(?:er)?\s+\p{L}+\s+\d{4})?/iu';

    /** Âge déclaré (« âgé de 45 ans », « Âge : 45 ») : permet de retrouver l'année de naissance. « 20 ans d’expérience » est gardé. */
    private const AGE = '/\b(?:âg[ée]e?\s+de\s+\d{2}\s+ans|âge\s*:\s*\d{2}(?:\s+ans)?)\b/iu';

    /**
     * Retire e-mails et téléphones (questions et réponses du chatbot).
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask() (question avant l'envoi, réponse avant l'affichage)
     *
     * @return array{text: string, removed: list<string>} texte nettoyé, types de données retirées (« e-mail », « téléphone »)
     */
    public function filterContact(string $text): array
    {
        return $this->apply($text, ['e-mail' => self::EMAIL, 'téléphone' => self::PHONE]);
    }

    /**
     * Retire toutes les données personnelles (texte d'un CV, demande d'un recruteur) : e-mail, téléphone,
     * adresse postale, date de naissance, âge.
     *
     * Liens :
     * - Appelée par : CvImportAnalyzer::analyze(), CompanySynthesizer::synthesize()
     *
     * @return array{text: string, removed: list<string>}
     */
    public function filterAll(string $text): array
    {
        return $this->apply($text, [
            'e-mail' => self::EMAIL,
            'date de naissance' => self::BIRTH,
            'téléphone' => self::PHONE,
            'adresse' => self::ADDRESS,
            'code postal et ville' => self::POSTCODE_CITY,
            'âge' => self::AGE,
        ]);
    }

    /**
     * Applique les motifs dans l'ordre et relève les types de données retirées.
     *
     * @param array<string, string> $patterns type de donnée => expression régulière
     *
     * @return array{text: string, removed: list<string>}
     */
    private function apply(string $text, array $patterns): array
    {
        $removed = [];
        foreach ($patterns as $type => $pattern) {
            $count = 0;
            $text = (string) preg_replace($pattern, self::MASK, $text, -1, $count);
            if ($count > 0) {
                $removed[] = $type;
            }
        }

        return ['text' => $text, 'removed' => $removed];
    }
}
