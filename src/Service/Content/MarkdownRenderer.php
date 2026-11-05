<?php

declare(strict_types=1);

namespace App\Service\Content;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Transforme le contenu Markdown des projets en HTML sûr.
 *
 * Deux étapes : CommonMark (syntaxe GitHub : tableaux, listes de tâches, liens
 * automatiques), puis nettoyage par le sanitizer « app.project_content »
 * (config/packages/html_sanitizer.yaml). Le HTML brut est accepté dans le Markdown
 * (contenus repris de l'ancien site), mais il passe lui aussi par le nettoyage :
 * aucun script, style ni lien javascript: ne peut atteindre la page.
 *
 * Liens :
 * - Utilisé par : App\Twig\ProjectExtension (filtre project_markdown),
 *   Admin\ProjectController::previewMarkdown() (aperçu dans l'éditeur)
 */
final readonly class MarkdownRenderer
{
    /** Convertisseur Markdown, configuré une fois. */
    private GithubFlavoredMarkdownConverter $converter;

    /**
     * Reçoit le sanitizer dédié aux contenus des projets.
     *
     * @param HtmlSanitizerInterface $sanitizer nettoyage du HTML (app.project_content)
     */
    public function __construct(
        #[Target('app.project_content')]
        private HtmlSanitizerInterface $sanitizer,
    ) {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    /**
     * Convertit du Markdown en HTML nettoyé.
     *
     * Liens :
     * - Appelée par : App\Twig\ProjectExtension::renderMarkdown(), Admin\ProjectController::previewMarkdown()
     *
     * @param string|null $markdown texte saisi dans l'admin
     *
     * @return string HTML sûr (chaîne vide si le texte est vide)
     */
    public function render(?string $markdown): string
    {
        $markdown = trim((string) $markdown);
        if ('' === $markdown) {
            return '';
        }

        return $this->sanitizer->sanitize($this->converter->convert($markdown)->getContent());
    }
}
