<?php

declare(strict_types=1);

namespace App\Service\Content;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Calcule les identifiants d'URL (slugs) : « Enceinte de stabilité ICH » → « enceinte-de-stabilite-ich ».
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController, Admin\ProjectCategoryController (slug laissé vide),
 *   ProjectImporter (archives), CvContentSynchronizer (études de cas)
 */
final class SlugGenerator
{
    /** Longueur maximale d'un slug (colonne project.slug). */
    private const MAX_LENGTH = 170;

    /** Convertisseur en caractères ASCII, règles françaises. */
    private AsciiSlugger $slugger;

    /** Prépare le convertisseur. */
    public function __construct()
    {
        $this->slugger = new AsciiSlugger('fr');
    }

    /**
     * Calcule le slug d'un texte (minuscules, chiffres et tirets).
     *
     * Liens :
     * - Appelée par : unique()
     *
     * @param string $text texte d'origine (titre, nom)
     *
     * @return string slug, ou « projet » si le texte ne contient aucun caractère utilisable
     */
    public function slugify(string $text): string
    {
        $slug = strtolower((string) $this->slugger->slug($text));
        $slug = trim(substr($slug, 0, self::MAX_LENGTH), '-');

        return '' === $slug ? 'projet' : $slug;
    }

    /**
     * Calcule un slug libre : ajoute « -2 », « -3 »… tant que le slug est déjà pris.
     *
     * Liens :
     * - Appelée par : Admin\ProjectController::beforeSave(), Admin\ProjectCategoryController::beforeSave(),
     *   ProjectImporter, CvContentSynchronizer
     *
     * @param string                $text    texte d'origine (ou slug souhaité)
     * @param callable(string):bool $isTaken indique si un slug est déjà utilisé
     *
     * @return string slug libre
     */
    public function unique(string $text, callable $isTaken): string
    {
        $base = $this->slugify($text);
        $slug = $base;
        for ($suffix = 2; $isTaken($slug); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
