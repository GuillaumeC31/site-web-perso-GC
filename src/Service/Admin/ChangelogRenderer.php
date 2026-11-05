<?php

declare(strict_types=1);

namespace App\Service\Admin;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Affiche le journal des versions (CHANGELOG.md) dans l'admin, et le résumé public des nouveautés
 * (NOUVEAUTES.md, page publique « Nouveautés »), en lecture seule.
 *
 * Rendu Markdown sûr : le HTML brut du fichier est ÉCHAPPÉ (affiché comme du texte, jamais interprété),
 * les liens dangereux (javascript:…) sont retirés, puis le résultat passe par les mêmes règles de nettoyage
 * que les projets (sanitizer « app.changelog », sans la limite de 20 000 octets qui coupait la fin du journal).
 *
 * Liens :
 * - Utilisé par : Admin\ChangelogController::index() (route admin_changelog), WhatsNewController::index()
 *   (route whats_new)
 * - Utilise : CHANGELOG.md et NOUVEAUTES.md (racine du projet), league/commonmark, sanitizer app.changelog
 * - Testé par : tests/Controller/Admin/ChangelogAdminTest, tests/Controller/WhatsNewTest
 */
final readonly class ChangelogRenderer
{
    private GithubFlavoredMarkdownConverter $converter;

    /**
     * Prépare le convertisseur Markdown (HTML brut échappé).
     *
     * @param HtmlSanitizerInterface $sanitizer  nettoyage du HTML produit (app.changelog : règles des projets, sans limite de 20 000 octets)
     * @param string                 $projectDir dossier du projet (CHANGELOG.md)
     */
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.app.changelog')]
        private HtmlSanitizerInterface $sanitizer,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Convertit un texte Markdown en HTML sûr.
     *
     * Liens :
     * - Appelée par : renderChangelog(), renderWhatsNew() ; tests
     *
     * @return string HTML nettoyé (chaîne vide si le texte est vide)
     */
    public function render(string $markdown): string
    {
        if ('' === trim($markdown)) {
            return '';
        }

        return $this->sanitizer->sanitize($this->converter->convert($markdown)->getContent());
    }

    /**
     * Lit CHANGELOG.md et renvoie son rendu HTML.
     *
     * Liens :
     * - Appelée par : Admin\ChangelogController::index()
     *
     * @return string|null HTML nettoyé, ou null si le fichier est absent
     */
    public function renderChangelog(): ?string
    {
        return $this->renderFile('CHANGELOG.md');
    }

    /**
     * Lit le résumé public des nouveautés (NOUVEAUTES.md, sans aucun détail technique : fichiers, chemins,
     * variables, failles) et renvoie son rendu HTML. Le CHANGELOG, technique, n'est jamais publié.
     *
     * Liens :
     * - Appelée par : WhatsNewController::index() (route whats_new, /nouveautes)
     * - Voir aussi : tests/Security/WhatsNewContentTest (refuse tout détail technique dans le fichier)
     *
     * @return string|null HTML nettoyé, ou null si le fichier est absent
     */
    public function renderWhatsNew(): ?string
    {
        return $this->renderFile('NOUVEAUTES.md');
    }

    /**
     * Lit un fichier Markdown à la racine du projet et renvoie son rendu HTML sûr.
     *
     * @param string $name nom du fichier (CHANGELOG.md, NOUVEAUTES.md)
     *
     * @return string|null HTML nettoyé, ou null si le fichier est absent
     */
    private function renderFile(string $name): ?string
    {
        $path = $this->projectDir.'/'.$name;
        if (!is_file($path)) {
            return null;
        }

        return $this->render((string) file_get_contents($path));
    }
}
