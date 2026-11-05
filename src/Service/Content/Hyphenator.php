<?php

declare(strict_types=1);

namespace App\Service\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vanderlee\Syllable\Hyphen\Text;
use Vanderlee\Syllable\Syllable;

/**
 * Césure française des textes justifiés : place des césures conditionnelles invisibles (U+00AD) aux
 * coupures permises par les motifs TeX du français (algorithme de Liang, bibliothèque vanderlee/syllable).
 *
 * Pourquoi côté serveur : Edge et Chrome sous Windows ne font pas de césure en français (hyphens: auto) ;
 * sans elle, le texte justifié laisse de grands blancs entre les mots. Les césures conditionnelles marchent
 * dans tous les navigateurs. Un trait d'union n'apparaît qu'en fin de ligne.
 *
 * Affichage des pages SEULEMENT : à n'utiliser que sur le texte courant justifié (.text-prose-justify), jamais
 * sur un titre, un mot-clé, une adresse e-mail, une métadonnée, un e-mail, un PDF (décision du 2026-10-25 :
 * copier-coller cassé), un export ni un texte envoyé à l'IA ; rien n'est enregistré en base.
 *
 * Liens :
 * - Utilisé par : App\Twig\TypographyExtension (filtres hyphenate et hyphenate_html)
 * - Testé par : tests/Twig/TypographyExtensionTest, tests/Security/NoHyphenationLeakTest
 */
final class Hyphenator
{
    /** Césure conditionnelle (invisible, trait d'union seulement en fin de ligne). */
    public const SOFT_HYPHEN = "\u{00AD}";

    /** Coupure possible sans trait d'union (après une barre oblique : « gaz/vapeur », « CAO/DAO »). */
    public const BREAK_OPPORTUNITY = "\u{200B}";

    /** Mots de moins de 9 lettres : jamais coupés (décision du 2026-10-25 : moins de mots coupés). */
    private const MIN_WORD_LENGTH = 9;

    /** Césureur français, créé au premier usage (lecture des motifs). */
    private ?Syllable $syllable = null;

    /**
     * Reçoit le dossier de cache des motifs compilés.
     *
     * @param string $cacheDir dossier de cache du noyau (motifs compilés dans syllable/)
     */
    public function __construct(
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Place les césures conditionnelles dans un texte brut (le résultat reste du texte, à échapper).
     *
     * Liens :
     * - Appelée par : TypographyExtension::hyphenate() (filtre Twig hyphenate)
     *
     * Exemple : hyphenate('approvisionnements') renvoie « ap·pro·vi·sion·ne·ments » (· = U+00AD) ; une barre
     * oblique entre deux lettres (« gaz/vapeur ») reçoit une coupure possible sans trait d'union (U+200B).
     *
     * @param string|null $text texte brut (null : chaîne vide)
     *
     * @return string le texte avec ses césures conditionnelles
     */
    public function hyphenate(?string $text): string
    {
        if (null === $text || '' === $text) {
            return '';
        }

        $text = (string) preg_replace('~(?<=\p{L})/(?=\p{L})~u', '/'.self::BREAK_OPPORTUNITY, $text);

        return $this->syllable()->hyphenateText($text);
    }

    /**
     * Place les césures conditionnelles dans le texte d'un fragment HTML (balises et attributs intacts ;
     * ni code, ni adresse e-mail protégée).
     *
     * Liens :
     * - Appelée par : TypographyExtension::hyphenateHtml() (filtre Twig hyphenate_html)
     *
     * @param string|null $html fragment HTML déjà sûr (null : chaîne vide)
     *
     * @return string le fragment avec ses césures conditionnelles
     */
    public function hyphenateHtml(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return (string) $html;
        }

        // DOMDocument (libxml) ne connaît pas les balises HTML5 (<mark>, <section>…) : ses avertissements sont
        // tus, sinon ils deviennent des exceptions (pages en erreur 500)
        $previous = libxml_use_internal_errors(true);
        try {
            return $this->syllable()->hyphenateHtmlText($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Renvoie le césureur français (motifs compilés une fois dans le cache du noyau).
     */
    private function syllable(): Syllable
    {
        if (null === $this->syllable) {
            $dir = $this->cacheDir.'/syllable';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            Syllable::setCacheDir($dir);
            $this->syllable = new Syllable('fr', new Text(self::SOFT_HYPHEN));
            $this->syllable->setMinWordLength(self::MIN_WORD_LENGTH);
            $this->syllable->setLibxmlOptions(\LIBXML_NOERROR | \LIBXML_NOWARNING);
            // Jamais coupés : titres, adresses, tableaux, légendes, code
            $this->syllable->excludeElement(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'address', 'th', 'td', 'caption', 'figcaption', 'code', 'pre', 'script', 'style']);
            $this->syllable->excludeAttribute('data-controller', 'protected-email');
        }

        return $this->syllable;
    }
}
