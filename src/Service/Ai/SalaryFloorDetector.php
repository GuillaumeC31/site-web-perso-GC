<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Repère le plancher personnel de rémunération dans un texte, sans jamais l'écrire ni l'afficher : le
 * montant est lu au moment du contrôle dans la ligne 🚫 « Plancher … : N k€ » de TEXTES_SITE.md (hors dépôt),
 * sous ses formes « N k€ », « Nk€ », « N K€ », « N 000 », « N000 » (même règle que
 * scripts/security/check-staged-assets.sh).
 *
 * Sur le serveur (l’hébergeur), TEXTES_SITE.md n'existe pas : la variable SALARY_FLOOR (montant en k€, dans
 * .env.local du serveur UNIQUEMENT, jamais dans Git, .env ou .env.example) est utilisée à la place. Sans
 * aucune des deux sources, le contrôle est indisponible : isAvailable() le dit, la page l'affiche.
 *
 * Liens :
 * - Utilisé par : AiKnowledgeValidator (fiches du « Cerveau de l'IA »)
 * - Paramètre : app.textes_site_path (../TEXTES_SITE.md ; fixture fictive en test), variable SALARY_FLOOR (secours)
 * - Voir aussi : scripts/security/check-staged-assets.sh (valeur refusée dans Git)
 */
final class SalaryFloorDetector
{
    /** Expression du plancher, calculée une fois (null : pas de fichier ou pas de ligne). */
    private ?string $pattern = null;

    private bool $loaded = false;

    /** Source du plancher : 'file', 'env' ou null. */
    private ?string $source = null;

    /**
     * Reçoit les deux sources du plancher.
     *
     * @param string      $textesSitePath chemin de TEXTES_SITE.md (absent sur le serveur)
     * @param string|null $salaryFloor    SALARY_FLOOR (montant en k€, ex. le nombre seul), utilisé si le fichier manque
     */
    public function __construct(
        #[Autowire('%app.textes_site_path%')]
        private readonly string $textesSitePath,
        #[Autowire('%env(default::SALARY_FLOOR)%')]
        private readonly ?string $salaryFloor = null,
    ) {
    }

    /**
     * Indique la source utilisée : « fichier » (TEXTES_SITE.md), « variable » (SALARY_FLOOR) ou null.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController::index() (information affichée, jamais le montant)
     *
     * @return 'file'|'env'|null
     */
    public function getSource(): ?string
    {
        $this->pattern();

        return $this->source;
    }

    /**
     * Indique si le contrôle est possible (fichier présent et ligne 🚫 du plancher trouvée).
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController::index() (avertissement)
     */
    public function isAvailable(): bool
    {
        return null !== $this->pattern();
    }

    /**
     * Indique si le texte contient le plancher.
     *
     * Liens :
     * - Appelée par : AiKnowledgeValidator::validate()
     */
    public function contains(string $text): bool
    {
        $pattern = $this->pattern();

        return null !== $pattern && 1 === preg_match($pattern, $text);
    }

    /**
     * Construit l'expression du plancher depuis la ligne 🚫 (le montant reste dans cette expression privée).
     */
    private function pattern(): ?string
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $markdown = is_file($this->textesSitePath) ? (string) file_get_contents($this->textesSitePath) : '';
            $amount = null;
            if (1 === preg_match('/^.*🚫.*[Pp]lancher[^:]*:[^0-9\n]*(\d+)/mu', $markdown, $matches)) {
                $amount = $matches[1];
                $this->source = 'file';
            } elseif (1 === preg_match('/^\s*(\d{2,3})\s*$/', (string) $this->salaryFloor, $matches)) {
                // Secours du serveur : SALARY_FLOOR, montant en k€ (nombre seul)
                $amount = $matches[1];
                $this->source = 'env';
            }
            if (null !== $amount) {
                $this->pattern = '/(?<!\d)'.$amount.'(?:\s*[kK]\s*€|[\s\x{202F}\x{A0}.]?000)(?!\d)/u';
            }
        }

        return $this->pattern;
    }
}
