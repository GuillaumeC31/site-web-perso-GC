<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CareerSection;
use App\Enum\CvEntrySection;
use App\Service\Content\PlaceholderDetector;

/**
 * Lit TEXTES_SITE.md (réécrit d'après le CV de référence, version du 2026-10-24) et en extrait le
 * contenu du CV déjà rédigé.
 *
 * Garde-fous :
 * - toute ligne marquée 🔒 (espace recruteur) ou 🚫 (nulle part) est ignorée, sauf deux cas 🔒 rangés dans un
 *   champ privé (affiché aux seuls recruteurs) : la partie 🔒 d'une mission d'expérience (note privée de
 *   l'expérience) et la ligne « Prétentions » du § 3 (prétentions du profil) ;
 * - une ligne 🚫 n'est JAMAIS lue (ex. plancher de rémunération : ni site, ni espace recruteur, ni PDF, ni chatbot) ;
 * - tout élément contenant un repère « [À COMPLÉTER] », « [nb] »… est ignoré ;
 * - les conseils « 💡 » sont ignorés ;
 * - contrôle final : si un texte extrait contient une adresse e-mail, un numéro de téléphone ou une
 *   date de naissance, l'analyse échoue (rien n'est importé).
 *
 * CV public allégé (correction de recette n° 1, 2026-10-30) : une puce d'expérience qui commence par 🌐 est
 * « Visible en public » (les autres sont réservées à l'espace recruteur) ; sous un élément des § 5 à 7, une
 * sous-puce « - 🌐 Résumé public : … » donne son résumé public (sans elle, l'élément n'est pas sur le CV public).
 * Corrections de recette n° 2 et 3 : un titre de section « ## 9. Langues 🔒 » ou « ## 10. Formations… 🔒 » réserve la
 * rubrique à l'espace recruteur (son contenu reste lu) ; la ligne « **Certifications :** … » du § 10 donne les
 * certifications (hors habilitations).
 *
 * Mise en forme : les mots-clés « **…** » des textes (missions, rubriques, profil) sont gardés tels quels
 * et rendus en gras par le filtre Twig cv_bold ; le reste du Markdown (italique, accents graves) est retiré.
 *
 * Classe pure (aucun accès à la base) : elle renvoie un tableau décrit par extract().
 *
 * Liens :
 * - Utilisée par : SyncTextesSiteCommand (app:content:sync-textes), CvContentSynchronizer
 * - Testée par : tests/Service/Import/TextesSiteParserTest
 */
final class TextesSiteParser
{
    /** Marque « espace recruteur seulement ». */
    private const RECRUITER_MARKER = '🔒';

    /** Marque « visible sur le CV public » (puce d'expérience, résumé public d'une rubrique). */
    private const PUBLIC_MARKER = '🌐';

    /** Sous-puce du résumé public d'un élément des § 5 à 7. */
    private const PUBLIC_SUMMARY_PATTERN = '/^- 🌐\s*Résumé public\s*:\s*(.+)$/u';

    /** Marques de confidentialité : une ligne qui en contient une n'est pas reprise telle quelle. */
    private const PRIVATE_MARKERS = [self::RECRUITER_MARKER, '🚫'];

    /** Adresse e-mail. */
    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu';

    /** Numéro de téléphone français (06 12 34 56 78, +33 6…, 0612345678). */
    private const PHONE_PATTERN = '/(?:\+33\s?|\b0)[1-9](?:[\s.-]?\d{2}){4}\b/u';

    /** Date de naissance (mention explicite). */
    private const BIRTH_PATTERN = '/\bn[ée]e?\s+le\b|naissance\s*:/iu';

    /** Période « (MM/AAAA – MM/AAAA) » ou « (AAAA – AAAA) ». */
    private const PERIOD_PATTERN = '/\((\d{2}\/\d{4}|\d{4})\s*[–-]\s*(\d{2}\/\d{4}|\d{4})\)/u';

    /** Sections numérotées des rubriques « liste » (numéro de section => rubrique). */
    private const ENTRY_SECTIONS = [
        5 => CvEntrySection::Tools,
        6 => CvEntrySection::Expertise,
        7 => CvEntrySection::Software,
        8 => CvEntrySection::SoftSkill,
        11 => CvEntrySection::Interest,
    ];

    /**
     * Analyse le contenu du fichier et renvoie les données du CV.
     *
     * Liens :
     * - Appelée par : SyncTextesSiteCommand::execute()
     *
     * @param string $markdown contenu de TEXTES_SITE.md
     *
     * @return array{
     *     profile: array<string, string>,
     *     key_figures: list<array{value: string, label: string}>,
     *     pillars: list<array{name: string, summary: string}>,
     *     experiences: list<array{section: CareerSection, job_title: string, company: string, location: ?string, summary: ?string, start: string, end: ?string, dates_in_years: bool, missions: list<string>, public_missions: list<string>, private_note: ?string}>,
     *     entries: list<array{section: CvEntrySection, title: ?string, content: string, public_summary: ?string}>,
     *     languages: list<array{name: string, proficiency: string}>,
     *     educations: list<array{year: int, title: string, location: ?string, institution: ?string}>,
     *     clearances: list<array{name: string, issuer: ?string, year: int}>,
     *     certifications: list<array{name: string, issuer: ?string, year: int}>,
     *     section_visibility: array{languages: bool, educations: bool},
     *     case_studies: list<array{title: string, competences: list<string>}>,
     *     recruiter_only: array{salary?: string},
     *     skipped: list<string>
     * }
     *
     * @throws \RuntimeException si une donnée privée (e-mail, téléphone, naissance) a été extraite
     */
    public function extract(string $markdown): array
    {
        $sections = $this->splitSections($markdown);
        $skipped = [];

        $entries = [];
        foreach (self::ENTRY_SECTIONS as $number => $section) {
            array_push($entries, ...$this->extractEntries($sections[$number] ?? '', $section));
        }

        $content = [
            'profile' => $this->extractProfile($sections),
            'key_figures' => $this->extractKeyFigures($sections[1] ?? ''),
            'pillars' => $this->extractPillars($sections[2] ?? ''),
            'experiences' => $this->extractExperiences($sections[4] ?? '', $skipped),
            'entries' => $entries,
            'languages' => $this->extractLanguages($sections[9] ?? ''),
            'educations' => $this->extractEducations($sections[10] ?? ''),
            'clearances' => $this->extractClearances($sections[10] ?? '', $skipped),
            'certifications' => $this->extractCertifications($sections[10] ?? '', $skipped),
            'section_visibility' => $this->extractSectionVisibility($markdown),
            'case_studies' => $this->extractCaseStudies($sections[12] ?? ''),
            'recruiter_only' => $this->extractRecruiterOnly($sections[3] ?? ''),
            'skipped' => $skipped,
        ];

        $this->assertNoPrivateData($content);

        return $content;
    }

    /**
     * Découpe le fichier par sections de premier niveau (« ## 4. Expériences » → clé 4).
     *
     * @return array<int, string>
     */
    private function splitSections(string $markdown): array
    {
        $parts = preg_split('/^## (\d+)\.[^\n]*$/mu', str_replace("\r\n", "\n", $markdown), -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $sections = [];

        for ($i = 1; $i < \count($parts) - 1; $i += 2) {
            $sections[(int) $parts[$i]] = $parts[$i + 1];
        }

        return $sections;
    }

    /**
     * Lit la visibilité publique des rubriques « Langues » (§ 9) et « Formations et certifications » (§ 10) d'après
     * le repère du titre : 🔒 = espace recruteur seulement (correction de recette n° 2, 2026-10-30) ; sinon publique.
     *
     * Liens :
     * - Appelée par : extract() ; écrite par CvContentSynchronizer::syncSectionVisibility()
     *
     * @return array{languages: bool, educations: bool} vrai : rubrique affichée sur le CV public
     */
    private function extractSectionVisibility(string $markdown): array
    {
        preg_match_all('/^## (\d+)\.([^\n]*)$/mu', str_replace("\r\n", "\n", $markdown), $matches, \PREG_SET_ORDER);
        $headings = [];
        foreach ($matches as [, $number, $title]) {
            $headings[(int) $number] = $title;
        }

        return [
            'languages' => !str_contains($headings[9] ?? '', self::RECRUITER_MARKER),
            'educations' => !str_contains($headings[10] ?? '', self::RECRUITER_MARKER),
        ];
    }

    /**
     * Extrait les textes du profil : nom, titre, ligne de domaines, accroche, résumé, statut de recherche,
     * disponibilité, localisation, modalités, postes et structures visés.
     *
     * @param array<int, string> $sections sections du fichier (clé = numéro de section)
     *
     * @return array<string, string> champ de Profile => valeur (champs absents : non trouvés ou incomplets)
     */
    private function extractProfile(array $sections): array
    {
        $decisions = $sections[0] ?? '';
        $home = $sections[1] ?? '';
        $search = $sections[3] ?? '';

        $profile = [
            'fullName' => $this->matchField($home, 'Titre'),
            'jobTitle' => $this->matchField($home, 'Sous-titre'),
            'tagline' => $this->matchField($home, 'Ligne de domaines'),
            'pitch' => $this->quoteAfter($home, '**Accroche'),
            'summary' => $this->quoteAfter($sections[2] ?? '', ''),
            'workModes' => $this->capitalize($this->matchField($search, 'Modalités de travail')),
            'targetRoles' => $this->capitalize($this->matchField($search, 'Postes visés')),
            'targetCompanies' => $this->capitalize($this->matchField($search, 'Structures')),
            'contractTypes' => $this->matchField($search, 'Contrat'),
        ];

        // Badge : « Disponible immédiatement · Lyon · Sur site, hybride ou télétravail »
        $badge = $this->safeParts((string) $this->matchField($home, 'Badge'));
        $profile['availability'] = $badge[0] ?? null;
        $profile['location'] = $badge[1] ?? null;

        // Statut de recherche : « - **Depuis 12/2025** : « En recherche active, disponible immédiatement ». »
        if (1 === preg_match('/^-\s*\*\*Depuis [^*]+\*\*\s*:\s*«\s*(.+?)\s*»/mu', $decisions, $matches)) {
            $profile['searchStatus'] = $this->cleanInline($matches[1]);
        }

        return array_filter($profile, fn (?string $value): bool => null !== $value && $this->isSafe($value));
    }

    /**
     * Extrait les chiffres clés complets du tableau du § 1.
     *
     * @return list<array{value: string, label: string}>
     */
    private function extractKeyFigures(string $section): array
    {
        $figures = [];
        foreach ($this->tableRows($section) as $cells) {
            if (2 === \count($cells) && $this->isSafe($cells[0]) && $this->isSafe($cells[1])) {
                $figures[] = ['value' => $this->cleanInline($cells[0]), 'label' => $this->cleanInline($cells[1])];
            }
        }

        return $figures;
    }

    /**
     * Extrait les quatre domaines (piliers) du tableau du § 2 : « | **Conception** | CAO/DAO, dimensionnement | ».
     *
     * @return list<array{name: string, summary: string}>
     */
    private function extractPillars(string $section): array
    {
        $pillars = [];
        foreach ($this->tableRows($section) as $cells) {
            if (2 === \count($cells) && $this->isSafe($cells[0]) && $this->isSafe($cells[1])) {
                $pillars[] = ['name' => $this->cleanInline($cells[0]), 'summary' => $this->cleanInline($cells[1])];
            }
        }

        return $pillars;
    }

    /**
     * Extrait les expériences du § 4, dans l'ordre du CV : « Expériences professionnelles », puis
     * « Expérience fondatrice » (une entreprise, plusieurs postes), puis « Parcours terrain ».
     *
     * @param list<string> $skipped reçoit la description des expériences ignorées
     *
     * @return list<array{section: CareerSection, job_title: string, company: string, location: ?string, summary: ?string, start: string, end: ?string, dates_in_years: bool, missions: list<string>, public_missions: list<string>, private_note: ?string}>
     */
    private function extractExperiences(string $section, array &$skipped): array
    {
        $experiences = [];
        $current = CareerSection::Professional;
        $blocks = preg_split('/^### /mu', $section) ?: [];
        array_shift($blocks);

        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            $heading = trim((string) array_shift($lines));
            $lowerHeading = mb_strtolower($heading);

            if (str_starts_with($lowerHeading, 'expérience fondatrice')) {
                $current = CareerSection::Foundation;
                continue;
            }
            if (str_starts_with($lowerHeading, 'parcours terrain')) {
                foreach ($lines as $line) {
                    if (null !== ($experience = $this->parseFieldLine($line))) {
                        $experiences[] = $experience;
                    }
                }
                continue;
            }

            // « 4.1 Poste — ENTREPRISE (SIGLE) · Lieu (02/2023 – 12/2025) » ou « 4.5 ENTREPRISE · Lieu (…) » (postes en
            // dessous) : la période est la dernière parenthèse contenant une année
            if (1 !== preg_match('/^\d+\.\d+\s+(.+?)\s*(\([^()]*\d{4}[^()]*\))\s*$/u', $heading, $matches)
                || null === ($period = $this->parsePeriod($matches[2]))) {
                $skipped[] = \sprintf('Expérience « %s » : dates à compléter, non importée.', $this->cleanInline((string) preg_replace('/^\d+\.\d+\s+/u', '', $heading)));
                continue;
            }

            $roles = $this->parseRoles($lines);
            if ([] !== $roles) {
                [$company, $location] = $this->splitCompanyLocation($matches[1]);
                array_push($experiences, ...$this->buildRoleExperiences($roles, $company, $location, $period, $current));
                continue;
            }

            [$jobTitle, $companyAndLocation] = array_pad(explode(' — ', $matches[1], 2), 2, null);
            if (null === $companyAndLocation) {
                // Activité sans employeur : « Projet entrepreneurial & développement web · Lyon »
                [$jobTitle, $location] = $this->splitCompanyLocation($matches[1]);
                $company = '';
            } else {
                [$company, $location] = $this->splitCompanyLocation($companyAndLocation);
            }

            [$missions, $privateNote, $publicMissions] = $this->parseMissions($lines);
            $experiences[] = [
                'section' => $current,
                'job_title' => $this->cleanInline((string) $jobTitle),
                'company' => $company,
                'location' => $location,
                'summary' => $this->italicLine($lines),
                'start' => $period['start'],
                'end' => $period['end'],
                'dates_in_years' => $period['in_years'],
                'missions' => $missions,
                'public_missions' => $publicMissions,
                'private_note' => $privateNote,
            ];
        }

        return $experiences;
    }

    /**
     * Retire le repère 🌐 en tête d'une puce et dit si elle était marquée « visible en public ».
     *
     * Liens :
     * - Appelée par : parseRoles(), parseMissions()
     *
     * @param string $text puce sans le tiret
     *
     * @return array{0: string, 1: bool} texte sans le repère, vrai si la puce est publique
     */
    private function splitPublicMarker(string $text): array
    {
        $text = trim($text);
        if (str_starts_with($text, self::PUBLIC_MARKER)) {
            return [trim(substr($text, \strlen(self::PUBLIC_MARKER))), true];
        }

        return [$text, false];
    }

    /**
     * Lit les postes successifs d'une entreprise : « **2017 – Chargé de conception** » suivi de ses puces.
     *
     * @param list<string> $lines lignes du bloc
     *
     * @return list<array{year: int, title: string, missions: list<string>, public_missions: list<string>}> postes du plus récent au plus ancien
     */
    private function parseRoles(array $lines): array
    {
        $roles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (1 === preg_match('/^\*\*(\d{4})\s*[–-]\s*(.+?)\*\*$/u', $line, $matches)) {
                $roles[] = ['year' => (int) $matches[1], 'title' => $this->cleanInline($matches[2]), 'missions' => [], 'public_missions' => []];
            } elseif ([] !== $roles && str_starts_with($line, '- ') && $this->isSafe($line)) {
                [$text, $public] = $this->splitPublicMarker(substr($line, 2));
                $mission = $this->cleanInline($text, true);
                $roles[\count($roles) - 1]['missions'][] = $mission;
                if ($public) {
                    $roles[\count($roles) - 1]['public_missions'][] = $mission;
                }
            }
        }

        return $roles;
    }

    /**
     * Transforme les postes d'une entreprise en expériences : chaque poste va de son année au début du
     * poste suivant ; le premier commence au mois de l'entrée dans l'entreprise, le dernier finit à sa sortie.
     *
     * @param list<array{year: int, title: string, missions: list<string>, public_missions: list<string>}> $roles  postes, du plus récent au plus ancien
     * @param array{start: string, end: ?string, in_years: bool}                                           $period période dans l'entreprise
     *
     * @return list<array{section: CareerSection, job_title: string, company: string, location: ?string, summary: ?string, start: string, end: ?string, dates_in_years: bool, missions: list<string>, public_missions: list<string>, private_note: ?string}>
     */
    private function buildRoleExperiences(array $roles, string $company, ?string $location, array $period, CareerSection $section): array
    {
        $experiences = [];
        $groupStartYear = (int) substr($period['start'], 0, 4);
        foreach ($roles as $index => $role) {
            $experiences[] = [
                'section' => $section,
                'job_title' => $role['title'],
                'company' => $company,
                'location' => $location,
                'summary' => null,
                'start' => $role['year'] === $groupStartYear ? $period['start'] : $role['year'].'-01-01',
                'end' => 0 === $index ? $period['end'] : $roles[$index - 1]['year'].'-01-01',
                'dates_in_years' => true,
                'missions' => $role['missions'],
                'public_missions' => $role['public_missions'],
                'private_note' => null,
            ];
        }

        return $experiences;
    }

    /**
     * Lit une ligne du « Parcours terrain » :
     * « - **Technicien de maintenance — ZETA ÉNERGIE · Valence** (06/2004 – 02/2005) : description ».
     *
     * @return array{section: CareerSection, job_title: string, company: string, location: ?string, summary: ?string, start: string, end: ?string, dates_in_years: bool, missions: list<string>, public_missions: list<string>, private_note: ?string}|null
     */
    private function parseFieldLine(string $line): ?array
    {
        $line = trim($line);
        if (!str_starts_with($line, '- **') || !$this->isSafe($line)
            || 1 !== preg_match('/^- \*\*(.+?)\s+—\s+(.+?)\*\*\s*(\(.+?\))\s*:?\s*(.*)$/u', $line, $matches)
            || null === ($period = $this->parsePeriod($matches[3]))) {
            return null;
        }

        [$company, $location] = $this->splitCompanyLocation($matches[2]);
        $description = trim($matches[4]);

        return [
            'section' => CareerSection::Field,
            'job_title' => $this->cleanInline($matches[1]),
            'company' => $company,
            'location' => $location,
            'summary' => null,
            'start' => $period['start'],
            'end' => $period['end'],
            'dates_in_years' => $period['in_years'],
            'missions' => '' === $description ? [] : [(string) $this->capitalize($this->cleanInline($description, true))],
            // Parcours terrain : une ligne par poste en public, la description reste dans l'espace recruteur
            'public_missions' => [],
            'private_note' => null,
        ];
    }

    /**
     * Lit les puces d'une expérience ; la partie 🔒 d'une puce devient la note privée ; une puce qui commence
     * par 🌐 est « visible en public ».
     *
     * Exemple : « - Contexte : ALPHA ESSAIS Group rachète BCI en 02/2023. 🔒 Fin de mission en décembre 2025. »
     * → mission « Contexte : ALPHA ESSAIS Group rachète BCI en 02/2023. », note « Fin de mission en décembre 2025. »
     *
     * @param list<string> $lines lignes du bloc
     *
     * @return array{0: list<string>, 1: ?string, 2: list<string>} missions, note privée, missions visibles en public
     */
    private function parseMissions(array $lines): array
    {
        $missions = [];
        $publicMissions = [];
        $privateNote = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '- ')) {
                continue;
            }
            [$public, $private] = array_pad(explode(self::RECRUITER_MARKER, substr($line, 2), 2), 2, null);
            if (null !== $private && PlaceholderDetector::isComplete($private) && !str_contains($private, '🚫')) {
                $privateNote = $this->cleanInline($private);
            }
            [$public, $isPublic] = $this->splitPublicMarker((string) $public);
            if ('' !== $public && $this->isSafe($public)) {
                $missions[] = $this->cleanInline($public, true);
                if ($isPublic) {
                    $publicMissions[] = $this->cleanInline($public, true);
                }
            }
        }

        return [$missions, $privateNote, $publicMissions];
    }

    /**
     * Extrait une rubrique « liste » : « - **Titre :** texte » ou « - texte » (sans titre), suivi au besoin de
     * la sous-puce « - 🌐 Résumé public : … » (résumé affiché sur le CV public).
     *
     * @return list<array{section: CvEntrySection, title: ?string, content: string, public_summary: ?string}>
     */
    private function extractEntries(string $section, CvEntrySection $entrySection): array
    {
        $entries = [];
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (1 === preg_match(self::PUBLIC_SUMMARY_PATTERN, $line, $matches)) {
                if ([] !== $entries && $this->isSafe($matches[1])) {
                    $entries[\count($entries) - 1]['public_summary'] = $this->cleanInline($matches[1], true);
                }
                continue;
            }
            if (!str_starts_with($line, '- ') || !$this->isSafe($line)) {
                continue;
            }
            $text = substr($line, 2);
            $title = null;
            if (1 === preg_match('/^\*\*(.+?)\s*:\*\*\s*(.+)$/u', $text, $matches)) {
                $title = $this->cleanInline($matches[1]);
                $text = $matches[2];
            }
            $entries[] = ['section' => $entrySection, 'title' => $title, 'content' => $this->cleanInline($text, true), 'public_summary' => null];
        }

        return $entries;
    }

    /**
     * Extrait les langues du § 9 : « - Français : langue maternelle. ».
     *
     * @return list<array{name: string, proficiency: string}>
     */
    private function extractLanguages(string $section): array
    {
        $languages = [];
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '- ') && $this->isSafe($line) && str_contains($line, ' : ')) {
                [$name, $proficiency] = explode(' : ', substr($line, 2), 2);
                $languages[] = ['name' => $this->cleanInline($name), 'proficiency' => rtrim($this->cleanInline($proficiency), '.')];
            }
        }

        return $languages;
    }

    /**
     * Extrait les formations du tableau du § 10 : année, diplôme, lieu, organisme.
     *
     * @return list<array{year: int, title: string, location: ?string, institution: ?string}>
     */
    private function extractEducations(string $section): array
    {
        $educations = [];
        foreach ($this->tableRows($section) as $cells) {
            if (4 !== \count($cells) || 1 !== preg_match('/^\d{4}$/', $cells[0]) || !$this->isSafe(implode(' ', $cells))) {
                continue;
            }
            $educations[] = [
                'year' => (int) $cells[0],
                'title' => $this->cleanInline($cells[1]),
                'location' => '' === $cells[2] ? null : $this->cleanInline($cells[2]),
                'institution' => '' === $cells[3] ? null : $this->cleanInline($cells[3]),
            ];
        }

        return $educations;
    }

    /**
     * Extrait les habilitations du § 10, écrites sur une ligne :
     * « **Habilitations :** habilitations électriques B2V, BR et BC, obtenues chez Gamma Froid Services
     * (2021 – 2023) ; recyclage à prévoir. À ajouter aussi au CV et à LinkedIn. »
     * → « Habilitations électriques B2V, BR et BC (2021 – 2023, recyclage à prévoir) », Gamma Froid Services, 2021.
     * Ce qui suit le premier point (note de l'auteur) n'est pas repris.
     *
     * Liens :
     * - Appelée par : extract() ; écrites par CvContentSynchronizer::syncClearances()
     *
     * @param list<string> $skipped reçoit la ligne si son format n'est pas reconnu
     *
     * @return list<array{name: string, issuer: ?string, year: int}> liste vide si absente ou « à compléter »
     */
    private function extractClearances(string $section, array &$skipped): array
    {
        if (null === ($line = $this->matchField($section, 'Habilitations')) || !$this->isSafe($line)) {
            return [];
        }
        if (1 !== preg_match('/^(.+?),\s*obtenues?\s+chez\s+(.+?)\s*\((\d{4})\s*[–-]\s*(\d{4})\)\s*;?\s*([^.]*)/u', $line, $matches)) {
            $skipped[] = 'Habilitations : format non reconnu (« …, obtenues chez Organisme (AAAA – AAAA) ; remarque. »), non importées.';

            return [];
        }
        $period = $matches[3].' – '.$matches[4].('' === trim($matches[5]) ? '' : ', '.trim($matches[5]));

        return [[
            'name' => $this->capitalize(trim($matches[1])).' ('.$period.')',
            'issuer' => trim($matches[2]),
            'year' => (int) $matches[3],
        ]];
    }

    /**
     * Extrait les certifications du § 10 (correction de recette n° 3, 2026-10-30), séparées par « ; » :
     * « **Certifications :** Attestation d'aptitude à la manipulation des fluides (catégorie 1), Centre de formation Exemple (2011). »
     *
     * Liens :
     * - Appelée par : extract() ; écrite par CvContentSynchronizer::syncCertifications()
     *
     * @param list<string> $skipped reçoit la description d'une ligne au format non reconnu
     *
     * @return list<array{name: string, issuer: ?string, year: int}> certifications (liste vide si la ligne manque)
     */
    private function extractCertifications(string $section, array &$skipped): array
    {
        if (null === ($line = $this->matchField($section, 'Certifications')) || !$this->isSafe($line)) {
            return [];
        }
        $certifications = [];
        foreach (explode(' ; ', rtrim($line, '. ')) as $item) {
            // Nom (virgules possibles), puis « , Organisme (AAAA) »
            if (1 !== preg_match('/^(.+),\s*([^,]+?)\s*\((\d{4})\)$/u', trim($item), $matches)) {
                $skipped[] = 'Certifications : « '.trim($item).' » au format non reconnu (« Nom, Organisme (AAAA) »), non importée.';
                continue;
            }
            $certifications[] = ['name' => $this->capitalize(trim($matches[1])), 'issuer' => trim($matches[2]), 'year' => (int) $matches[3]];
        }

        return $certifications;
    }

    /**
     * Extrait les données réservées à l'espace recruteur du § 3 : la ligne
     * « - **Prétentions :** 🔒 XX à YY k€ brut annuel, selon le poste et le périmètre (espace recruteur uniquement) ».
     *
     * Seule cette ligne est lue, et seulement si elle porte la marque 🔒 : une ligne 🚫 (plancher personnel)
     * n'est jamais lue, même si elle parle de rémunération. La mention « (espace recruteur uniquement) » est retirée.
     *
     * Liens :
     * - Appelée par : extract() ; écrite par CvContentSynchronizer::syncRecruiterOnly()
     *
     * @return array{salary?: string} vide si la ligne manque, n'est pas marquée 🔒 ou est « à compléter »
     */
    private function extractRecruiterOnly(string $section): array
    {
        if (1 !== preg_match('/^-\s*\*\*Prétentions\s*:\*\*\s*'.self::RECRUITER_MARKER.'\s*(.+)$/mu', $section, $matches)) {
            return [];
        }
        $salary = $this->cleanInline((string) preg_replace('/\s*\((?:espace recruteur|champ privé)[^)]*\)\s*$/iu', '', $matches[1]));
        if ('' === $salary || str_contains($salary, '🚫') || !PlaceholderDetector::isComplete($salary)) {
            return [];
        }

        return ['salary' => $salary];
    }

    /**
     * Extrait les études de cas (§ 12) : titre et compétences ; le récit (contexte, problème…) reste à
     * rédiger dans l'admin tant qu'il est « à compléter ».
     *
     * @return list<array{title: string, competences: list<string>}>
     */
    private function extractCaseStudies(string $section): array
    {
        $cases = [];
        $blocks = preg_split('/^### /mu', $section) ?: [];
        array_shift($blocks);
        foreach ($blocks as $block) {
            [$heading, $body] = array_pad(explode("\n", $block, 2), 2, '');
            $title = $this->cleanInline((string) preg_replace('/^Cas\s+\d+\s*[—–-]\s*/u', '', trim($heading)));
            if ('' === $title || !$this->isSafe($title)) {
                continue;
            }

            $competences = [];
            if (1 === preg_match('/^-\s*\*\*Compétences\s*:\*\*\s*(.+)$/mu', $body, $matches)) {
                $competences = array_values(array_filter(
                    array_map(fn (string $part): string => $this->cleanInline(trim($part)), explode(',', $matches[1])),
                    fn (string $part): bool => '' !== $part && $this->isSafe($part),
                ));
            }

            $cases[] = ['title' => $title, 'competences' => $competences];
        }

        return $cases;
    }

    /**
     * Sépare « ENTREPRISE · Lieu » (lieu facultatif).
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitCompanyLocation(string $text): array
    {
        [$company, $location] = array_pad(explode(' · ', $text, 2), 2, null);

        return [$this->cleanInline($company), null === $location ? null : $this->cleanInline($location)];
    }

    /**
     * Renvoie la première ligne en italique d'un bloc (« *Études techniques et amélioration de machines* »).
     *
     * @param list<string> $lines lignes du bloc
     */
    private function italicLine(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (1 === preg_match('/^\*(?!\*)(.+)\*$/u', trim($line), $matches) && $this->isSafe($matches[1])) {
                return $this->cleanInline($matches[1]);
            }
        }

        return null;
    }

    /**
     * Découpe un texte « a · b · c » et ne garde que les éléments sûrs et non vides.
     *
     * @return list<string>
     */
    private function safeParts(string $text): array
    {
        $parts = array_map(fn (string $part): string => $this->cleanInline(rtrim(trim($part), '.')), explode(' · ', $text));

        return array_values(array_filter($parts, fn (string $part): bool => '' !== $part && $this->isSafe($part)));
    }

    /**
     * Lit les lignes de données d'un tableau Markdown (sans l'en-tête ni la ligne de séparation).
     *
     * @return list<list<string>>
     */
    private function tableRows(string $section): array
    {
        $rows = [];
        $headerSeen = false;
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                $headerSeen = false;
                continue;
            }
            if (1 === preg_match('/^\|[\s|:-]+\|$/', $line)) {
                $headerSeen = true;
                continue;
            }
            if (!$headerSeen) {
                continue;
            }
            $rows[] = array_map(trim(...), \array_slice(explode('|', $line), 1, -1));
        }

        return $rows;
    }

    /**
     * Trouve « **Libellé :** valeur » (éventuellement en puce « - ») et renvoie la valeur nettoyée.
     */
    private function matchField(string $section, string $label): ?string
    {
        if (1 !== preg_match('/^(?:-\s*)?\*\*'.preg_quote($label, '/').'\s*:\*\*\s*(.+)$/mu', $section, $matches)) {
            return null;
        }

        return $this->cleanInline($matches[1]);
    }

    /**
     * Renvoie le bloc de citation (« > … ») qui suit un repère, paragraphes séparés par une ligne vide.
     *
     * @param string $marker texte qui précède la citation ('' : première citation de la section)
     *
     * @return string|null le texte, ou null si absent ou incomplet
     */
    private function quoteAfter(string $section, string $marker): ?string
    {
        $start = '' === $marker ? 0 : mb_strpos($section, $marker);
        if (false === $start) {
            return null;
        }

        $paragraphs = [];
        $current = [];
        $inQuote = false;
        foreach (explode("\n", mb_substr($section, $start)) as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, '>')) {
                $inQuote = true;
                $text = trim(substr($line, 1));
                if ('' === $text) {
                    $paragraphs[] = implode(' ', $current);
                    $current = [];
                } else {
                    $current[] = $text;
                }
            } elseif ($inQuote) {
                break;
            }
        }
        $paragraphs[] = implode(' ', $current);
        $text = implode("\n\n", array_filter($paragraphs, static fn (string $p): bool => '' !== $p));

        return '' !== $text && $this->isSafe($text) ? $this->cleanInline($text, true) : null;
    }

    /**
     * Interprète « (MM/AAAA – MM/AAAA) » ou « (AAAA – AAAA) ».
     *
     * @return array{start: string, end: ?string, in_years: bool}|null dates au format AAAA-MM-01, ou null si illisibles
     */
    private function parsePeriod(string $text): ?array
    {
        if (1 !== preg_match(self::PERIOD_PATTERN, $text, $matches)) {
            return null;
        }

        $toDate = static fn (string $value): string => str_contains($value, '/')
            ? substr($value, 3, 4).'-'.substr($value, 0, 2).'-01'
            : $value.'-01-01';

        return [
            'start' => $toDate($matches[1]),
            'end' => $toDate($matches[2]),
            'in_years' => !str_contains($matches[1], '/'),
        ];
    }

    /**
     * Met la première lettre en majuscule (« sur site · hybride » → « Sur site · hybride »).
     */
    private function capitalize(?string $text): ?string
    {
        return null === $text ? null : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Indique si un texte peut être importé : ni marque privée, ni repère à compléter, ni conseil.
     */
    private function isSafe(string $text): bool
    {
        foreach (self::PRIVATE_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return false;
            }
        }

        return !str_contains($text, '💡') && PlaceholderDetector::isComplete($text);
    }

    /**
     * Retire la mise en forme Markdown (gras, italique, accents graves) et les espaces superflus.
     *
     * @param bool $keepBold vrai : garder les mots-clés « **…** » (rendus en gras par le filtre cv_bold)
     */
    private function cleanInline(string $text, bool $keepBold = false): string
    {
        if (!$keepBold) {
            $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        }
        $text = (string) preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/u', '$1', $text);
        $text = str_replace('`', '', $text);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }

    /**
     * Dernier contrôle : aucune adresse e-mail, aucun téléphone, aucune date de naissance.
     *
     * @param array<string, mixed> $content données extraites
     *
     * @throws \RuntimeException au premier texte suspect (l'import est alors annulé)
     */
    private function assertNoPrivateData(array $content): void
    {
        array_walk_recursive($content, static function (mixed $value): void {
            if (!\is_string($value)) {
                return;
            }
            foreach ([self::EMAIL_PATTERN, self::PHONE_PATTERN, self::BIRTH_PATTERN] as $pattern) {
                if (1 === preg_match($pattern, $value)) {
                    throw new \RuntimeException('Donnée privée détectée dans le contenu extrait (e-mail, téléphone ou date de naissance) : import annulé.');
                }
            }
        });
    }
}
