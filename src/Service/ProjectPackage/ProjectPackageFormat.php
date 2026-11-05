<?php

declare(strict_types=1);

namespace App\Service\ProjectPackage;

/**
 * Format des archives ZIP d'export et d'import d'un projet.
 *
 * Version 2 (ce site) :
 *   manifest.json  { format, version, exported_at }
 *   project.json   champs du projet, catégories, couverture et galerie (chemins dans l'archive)
 *   images/…       fichiers WebP
 *
 * Version 1 (ancien site guillaume_c, lecture seule) :
 *   manifest.json  { format: guillaume_c_project_package, version: 1, assets[] }
 *   project.json   { title, slug, excerpt, content (HTML), cover_archive, repository_url, demo_url… }
 *   categories.json, assets/…
 *
 * Liens :
 * - Utilisé par : ProjectExporter, ProjectImporter
 */
final class ProjectPackageFormat
{
    /** Identifiant du format de ce site. */
    public const FORMAT_ID = 'guillaume_couget_project_package';

    /** Version du format de ce site. */
    public const VERSION = 2;

    /** Identifiant du format de l'ancien site (version 1). */
    public const LEGACY_FORMAT_ID = 'guillaume_c_project_package';

    public const MANIFEST_FILE = 'manifest.json';
    public const PROJECT_FILE = 'project.json';
    public const LEGACY_CATEGORIES_FILE = 'categories.json';

    /** Taille maximale de l'archive envoyée (50 Mo). */
    public const MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;

    /** Nombre maximal de fichiers dans l'archive. */
    public const MAX_ENTRIES = 200;

    /** Taille décompressée maximale de l'archive (protection contre les « bombes ZIP »). */
    public const MAX_UNCOMPRESSED_BYTES = 150 * 1024 * 1024;

    /** Taille décompressée maximale d'une image. */
    public const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    /** Taille décompressée maximale d'un fichier JSON. */
    public const MAX_JSON_BYTES = 2 * 1024 * 1024;

    /** Extensions d'images converties en WebP à l'import (le SVG est écarté). */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
}
