<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Mise en forme des périodes d'expérience du CV.
 *
 * Usage : {{ cv_period(experience) }} → « mars 2023 – décembre 2025 »
 *         {{ cv_period(experience, true) }} → « 2023 – 2026 »
 *
 * Liens :
 * - Utilisée par : templates/home/index.html.twig, templates/cv/show.html.twig, templates/cv/pdf/*
 * - Données : tableaux d'expérience de CvContentProvider (clés start, end, dates_in_years)
 */
final readonly class CvExtension
{
    /**
     * Reçoit le traducteur (mot « depuis » / « aujourd'hui »).
     *
     * @param TranslatorInterface $translator textes de l'interface
     */
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * Renvoie la période d'une expérience, en français.
     *
     * Liens :
     * - Appelée par : la fonction Twig cv_period()
     *
     * Exemples : « mars 2023 – décembre 2025 », « 2017 – 2019 », « depuis mars 2023 ».
     *
     * @param array<string, mixed> $experience expérience publique (start, end, dates_in_years)
     * @param bool                 $yearsOnly  vrai pour n'afficher que les années (cartes de l'accueil)
     *
     * @return string la période, ou une chaîne vide sans date de début
     */
    #[AsTwigFunction('cv_period')]
    public function formatPeriod(array $experience, bool $yearsOnly = false): string
    {
        $start = $experience['start'] ?? null;
        $end = $experience['end'] ?? null;
        if (!$start instanceof \DateTimeInterface) {
            return '';
        }

        $years = $yearsOnly || true === ($experience['dates_in_years'] ?? false);
        $format = fn (\DateTimeInterface $date): string => $years ? $date->format('Y') : $this->formatMonth($date);

        if (!$end instanceof \DateTimeInterface) {
            return $this->translator->trans('cv.period.since', ['%date%' => $format($start)]);
        }

        $from = $format($start);
        $to = $format($end);

        return $from === $to ? $from : $from.' – '.$to;
    }

    /**
     * Renvoie la période au format du CV de référence : « 02/2023 – 12/2025 », ou « 2017 – 2019 »
     * si seules les années sont connues.
     *
     * Liens :
     * - Appelée par : la fonction Twig cv_period_numeric() (page /cv, CV PDF, espace recruteur)
     *
     * @param array<string, mixed> $experience expérience ou groupe (start, end, dates_in_years)
     *
     * @return string la période, ou une chaîne vide sans date de début
     */
    #[AsTwigFunction('cv_period_numeric')]
    public function formatNumericPeriod(array $experience): string
    {
        $start = $experience['start'] ?? null;
        $end = $experience['end'] ?? null;
        if (!$start instanceof \DateTimeInterface) {
            return '';
        }

        $pattern = true === ($experience['dates_in_years'] ?? false) ? 'Y' : 'm/Y';
        if (!$end instanceof \DateTimeInterface) {
            return $this->translator->trans('cv.period.since', ['%date%' => $start->format($pattern)]);
        }

        $from = $start->format($pattern);
        $to = $end->format($pattern);

        return $from === $to ? $from : $from.' – '.$to;
    }

    /**
     * Rend les mots-clés **en gras** d'un texte du CV : le texte est d'abord échappé en entier, puis
     * seules les paires « **…** » deviennent <strong>…</strong>. Aucun autre HTML ne peut passer.
     *
     * Liens :
     * - Appelée par : le filtre Twig cv_bold (page /cv, espace recruteur, accueil, CV PDF « mis en page » et ATS)
     * - Voir aussi : TextesSiteParser (garde les « **…** » des missions et des rubriques)
     *
     * Exemple : « **Analyse des besoins** et <b>conception</b> » → « <strong>Analyse des besoins</strong> et &lt;b&gt;conception&lt;/b&gt; »
     *
     * @param string|null $text texte saisi (admin ou TEXTES_SITE.md)
     *
     * @return string HTML sûr (déjà échappé)
     */
    #[AsTwigFilter('cv_bold', isSafe: ['html'])]
    public function bold(?string $text): string
    {
        $escaped = htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $escaped);
    }

    /**
     * Retire les marques « **…** » (textes sans mise en forme : balises meta, titres d'onglet).
     *
     * Liens :
     * - Appelée par : le filtre Twig cv_plain
     */
    #[AsTwigFilter('cv_plain')]
    public function plain(?string $text): string
    {
        return (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '$1', (string) $text);
    }

    /**
     * Met la première lettre en majuscule sans toucher au reste (le filtre « capitalize » de Twig met
     * le reste en minuscules : « IA » deviendrait « ia »).
     *
     * Liens :
     * - Appelée par : le filtre Twig capitalize_first (savoir-être du CV : « gestion de projets… » → « Gestion… »)
     */
    #[AsTwigFilter('capitalize_first')]
    public function capitalizeFirst(?string $text): string
    {
        $text = (string) $text;

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Met la première lettre en minuscule (« Maintenance préventive… » après un tiret : « – maintenance… »).
     *
     * Liens :
     * - Appelée par : le filtre Twig lower_first (« Parcours terrain » du CV PDF)
     */
    #[AsTwigFilter('lower_first')]
    public function lowerFirst(?string $text): string
    {
        $text = (string) $text;

        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Renvoie « mars 2023 » (mois en toutes lettres, en français).
     */
    private function formatMonth(\DateTimeInterface $date): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM y');

        return (string) $formatter->format($date);
    }
}
