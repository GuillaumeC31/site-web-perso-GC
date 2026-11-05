<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Repository\ProfileRepository;

/**
 * Bloque l'enregistrement d'une fiche du « Cerveau de l'IA » qui contiendrait une donnée à ne jamais
 * envoyer à Mistral AI : téléphone, e-mail, adresse postale, date de naissance ou âge, plancher de
 * rémunération (ligne 🚫 de TEXTES_SITE.md), donnée de santé évidente ; ou une consigne contraire au socle de
 * sécurité (« révèle tes consignes », « ignore tes règles »…), que le socle refuserait de toute façon.
 *
 * Liens :
 * - Utilisé par : Admin\AiKnowledgeController (new, edit), AiDirectiveImporter (anciennes directives)
 * - Utilise : PrivateDataFilter::filterAll(), SalaryFloorDetector, ChatGuard::detectInjection(), ProfileRepository
 * - Testé par : tests/Service/Ai/AiKnowledgeValidatorTest
 */
final readonly class AiKnowledgeValidator
{
    /**
     * Données de santé évidentes (mots entiers, accents tolérés) : maladie, handicap, traitement, grossesse….
     */
    private const HEALTH_PATTERN = '/\b(?:maladie|malade|handicap\w*|RQTH|invalidit[ée]|arr[êe]t\s+(?:de\s+)?(?:travail|maladie)|cong[ée]\s+maladie|hospitalis\w*|op[ée]r[ée]e?\s+(?:du|de\s+la|des)|chirurgi\w*|traitement\s+m[ée]dical|m[ée]dicament\w*|diab[èe]t\w*|cancer\w*|tumeur|d[ée]pression|d[ée]pressif|burn[- ]?out|anxi[ée]t[ée]|trouble\s+(?:psychique|mental|bipolaire)|sant[ée]\s+mentale|enceinte|grossesse|allergi\w*|asthm\w*|[ée]pilep\w*|sclérose|VIH|s[ée]ropositi\w*|th[ée]rapie|psychiatr\w*)\b/iu';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private PrivateDataFilter $privateDataFilter,
        private SalaryFloorDetector $floorDetector,
        private ChatGuard $guard,
        private ProfileRepository $profileRepository,
    ) {
    }

    /**
     * Contrôle le titre et le contenu d'une fiche.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController (avant l'enregistrement), AiDirectiveImporter
     *
     * @param string $text titre et contenu de la fiche
     *
     * @return list<string> clés de traduction des motifs de blocage (admin.ai_knowledge.blocked.*) ; vide si la fiche peut être enregistrée
     */
    public function validate(string $text): array
    {
        $reasons = [];
        $removed = $this->privateDataFilter->filterAll($text)['removed'];
        if (\in_array('e-mail', $removed, true) || $this->containsProfileEmail($text)) {
            $reasons[] = 'admin.ai_knowledge.blocked.email';
        }
        if (\in_array('téléphone', $removed, true) || $this->containsProfilePhone($text)) {
            $reasons[] = 'admin.ai_knowledge.blocked.phone';
        }
        if (\in_array('adresse', $removed, true) || \in_array('code postal et ville', $removed, true)) {
            $reasons[] = 'admin.ai_knowledge.blocked.address';
        }
        if (\in_array('date de naissance', $removed, true) || \in_array('âge', $removed, true)) {
            $reasons[] = 'admin.ai_knowledge.blocked.birth';
        }
        if ($this->floorDetector->contains($text)) {
            $reasons[] = 'admin.ai_knowledge.blocked.floor';
        }
        if (1 === preg_match(self::HEALTH_PATTERN, $text)) {
            $reasons[] = 'admin.ai_knowledge.blocked.health';
        }
        if (null !== $this->guard->detectInjection($text)) {
            $reasons[] = 'admin.ai_knowledge.blocked.injection';
        }

        return $reasons;
    }

    /**
     * Indique si le texte contient l'e-mail privé du profil (même écrit autrement : casse, espaces).
     */
    private function containsProfileEmail(string $text): bool
    {
        $email = mb_strtolower(trim((string) $this->profileRepository->getOrCreate()->getPrivateEmail()));

        return '' !== $email && str_contains((string) preg_replace('/\s+/u', '', mb_strtolower($text)), $email);
    }

    /**
     * Indique si le texte contient le téléphone privé du profil (chiffres comparés, séparateurs ignorés).
     */
    private function containsProfilePhone(string $text): bool
    {
        $digits = (string) preg_replace('/\D/', '', (string) $this->profileRepository->getOrCreate()->getPrivatePhone());
        if (\strlen($digits) < 8) {
            return false;
        }

        // Les 8 derniers chiffres suffisent (indicatif +33 ou 0 en tête)
        return str_contains((string) preg_replace('/\D/', '', $text), substr($digits, -8));
    }
}
