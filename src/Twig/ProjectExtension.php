<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Admin\ProjectImageStorage;
use App\Service\Content\MarkdownRenderer;
use Symfony\Component\Asset\Packages;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Fonctions Twig des projets : contenu Markdown nettoyé et chemins des images.
 *
 * Usage : {{ project.content|project_markdown }}
 *         <img src="{{ project_image(project.coverImage) }}" srcset="{{ project_image_srcset(project.coverImage, 1200) }}">
 *
 * Liens :
 * - Utilisée par : templates/project/*, templates/home/index.html.twig, templates/admin/project/*
 * - Utilise : MarkdownRenderer, ProjectImageStorage (dossier et variante 600 px)
 */
final readonly class ProjectExtension
{
    /**
     * Reçoit les dépendances.
     *
     * @param MarkdownRenderer $markdownRenderer conversion Markdown → HTML nettoyé
     * @param Packages         $packages         URL des fichiers publics
     */
    public function __construct(
        private MarkdownRenderer $markdownRenderer,
        private Packages $packages,
    ) {
    }

    /**
     * Convertit le Markdown d'un projet en HTML nettoyé (sûr à afficher tel quel).
     *
     * Liens :
     * - Appelée par : le filtre Twig project_markdown
     * - Utilise : MarkdownRenderer::render()
     *
     * @param string|null $markdown contenu saisi dans l'admin
     *
     * @return string HTML nettoyé
     */
    #[AsTwigFilter('project_markdown', isSafe: ['html'])]
    public function renderMarkdown(?string $markdown): string
    {
        return $this->markdownRenderer->render($markdown);
    }

    /**
     * Renvoie l'URL d'une image de projet (grande taille, ou variante 600 px).
     *
     * Liens :
     * - Appelée par : la fonction Twig project_image()
     *
     * @param string $filename nom du fichier WebP
     * @param bool   $small    vrai pour la variante de 600 px de large
     */
    #[AsTwigFunction('project_image')]
    public function imageUrl(string $filename, bool $small = false): string
    {
        $file = $small ? ProjectImageStorage::variantFilename($filename) : $filename;

        return $this->packages->getUrl(ProjectImageStorage::PUBLIC_DIRECTORY.'/'.$file);
    }

    /**
     * Renvoie la valeur de l'attribut srcset d'une image de projet (variante 600 px + grande taille).
     *
     * Liens :
     * - Appelée par : la fonction Twig project_image_srcset()
     *
     * @param string $filename nom du fichier WebP
     * @param int    $width    largeur réelle de la grande image, en pixels
     */
    #[AsTwigFunction('project_image_srcset')]
    public function imageSrcset(string $filename, int $width): string
    {
        if ($width <= ProjectImageStorage::VARIANT_WIDTH) {
            return $this->imageUrl($filename).' '.$width.'w';
        }

        return $this->imageUrl($filename, true).' '.ProjectImageStorage::VARIANT_WIDTH.'w, '.$this->imageUrl($filename).' '.$width.'w';
    }
}
