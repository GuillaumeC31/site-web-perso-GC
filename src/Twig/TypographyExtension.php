<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Content\Hyphenator;
use Twig\Attribute\AsTwigFilter;

/**
 * Filtres de typographie du texte justifié (césure française, voir App\Service\Content\Hyphenator).
 *
 * Usage : {{ mission|hyphenate|cv_bold }} (texte brut, avant les autres filtres)
 *         {% apply hyphenate_html %}…HTML…{% endapply %} (fragment HTML : pages légales, contenu des projets)
 *
 * Liens :
 * - Utilisée par : templates/cv/*, templates/home/index.html.twig, templates/project/show.html.twig,
 *   templates/legal/_section.html.twig, templates/calculator/tool.html.twig (pages du site seulement, jamais les PDF)
 * - Utilise : Hyphenator
 */
final readonly class TypographyExtension
{
    /**
     * Reçoit le césureur.
     *
     * @param Hyphenator $hyphenator césure française
     */
    public function __construct(private Hyphenator $hyphenator)
    {
    }

    /**
     * Filtre hyphenate : césures conditionnelles dans un texte brut (le résultat est encore échappé par Twig).
     *
     * Liens :
     * - Appelée par : le filtre Twig hyphenate
     *
     * @param string|null $text texte brut
     *
     * @return string le texte avec ses césures conditionnelles
     */
    #[AsTwigFilter('hyphenate')]
    public function hyphenate(?string $text): string
    {
        return $this->hyphenator->hyphenate($text);
    }

    /**
     * Filtre hyphenate_html : césures conditionnelles dans le texte d'un fragment HTML déjà sûr.
     *
     * Liens :
     * - Appelée par : le filtre Twig hyphenate_html (balise apply des gabarits)
     *
     * @param string|null $html fragment HTML déjà rendu (échappé ou nettoyé)
     *
     * @return string le fragment avec ses césures conditionnelles
     */
    #[AsTwigFilter('hyphenate_html', isSafe: ['html'], preEscape: 'html')]
    public function hyphenateHtml(?string $html): string
    {
        return $this->hyphenator->hyphenateHtml($html);
    }
}
