<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\Certification;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\KeyFigure;
use App\Entity\Profile;
use App\Enum\CareerSection;
use App\Enum\CvEntrySection;
use App\Repository\CertificationRepository;
use App\Repository\CvEntryRepository;
use App\Repository\EducationRepository;
use App\Repository\ExperienceRepository;
use App\Repository\KeyFigureRepository;
use App\Repository\LanguageRepository;
use App\Repository\ProfileRepository;
use App\Repository\SkillPillarRepository;

/**
 * Rassemble le contenu du CV, dans l'ordre et les rubriques du CV de référence, à
 * trois niveaux :
 * - getContent() : CV PUBLIC SYNTHÉTIQUE (accueil, page /cv, PDF publics, assistant guidé) — correction de
 *   recette n° 1 du 2026-10-30 : seules les puces cochées « Visible en public » (avec le nombre de puces
 *   réservées, « hidden_count »), « Parcours terrain » réduit à une ligne par poste, résumé public des rubriques
 *   Outils techniques, Expertises métiers et Logiciels, ni centres d'intérêt ni outils des expériences ; langues et
 *   formations seulement si leur rubrique est publique (Profile, corrections de recette n° 2 du 2026-10-30) ;
 * - getFullContent() : tout le détail du CV, sans aucun champ privé (contexte de l'assistant IA de l'espace
 *   recruteur) ;
 * - getPrivateContent() : le détail, plus le téléphone, l'e-mail, les prétentions (si « Afficher dans l'espace
 *   recruteur » est coché), les vrais noms d'entreprises et les notes privées (espace recruteur, CV complet en
 *   PDF) ; jamais l'adresse postale (ni de date de naissance, que le site ne stocke pas).
 *
 * C'est le seul point d'entrée des pages vers les données du CV : les templates reçoivent des tableaux, pas les
 * entités des expériences.
 *
 * Règles de publication :
 * - expériences publiées seulement ; nom d'entreprise remplacé par le libellé public si anonymisée ;
 * - chiffres clés, réalisations et textes « à compléter » écartés (PlaceholderDetector).
 *
 * Liens :
 * - Utilisé par : HomeController, CvController, CvPdfRenderer, RecruiterAreaController, Admin\RecruiterController
 *   (aperçu), GuidedAssistant, ChatContextBuilder
 * - Utilise : ProfilePhotoProvider (photo de l'admin ou photo par défaut), CvEntryRepository (rubriques « liste »)
 * - Testé par : tests/Controller/CvPublicLeakTest
 */
final readonly class CvContentProvider
{
    /** Nombre maximal de chiffres clés affichés sur l'accueil. */
    public const MAX_KEY_FIGURES = 3;

    /**
     * Reçoit les dépôts du contenu.
     */
    public function __construct(
        private ProfileRepository $profileRepository,
        private KeyFigureRepository $keyFigureRepository,
        private ExperienceRepository $experienceRepository,
        private SkillPillarRepository $skillPillarRepository,
        private EducationRepository $educationRepository,
        private CertificationRepository $certificationRepository,
        private LanguageRepository $languageRepository,
        private CvEntryRepository $cvEntryRepository,
        private ProfilePhotoProvider $profilePhotoProvider,
    ) {
    }

    /**
     * Renvoie le CV public synthétique (puces publiques seulement, résumés des rubriques, ni centres d'intérêt
     * ni détail du parcours terrain).
     *
     * Liens :
     * - Appelée par : HomeController::index(), CvController::show(), CvPdfRenderer::renderHtml(),
     *   GuidedAssistant::answer()
     *
     * @return array{
     *     synthetic: bool,
     *     profile: array<string, string|null>,
     *     key_figures: list<KeyFigure>,
     *     career: array{professional: list<array<string, mixed>>, foundation: list<array<string, mixed>>, field: list<array<string, mixed>>},
     *     pillars: list<\App\Entity\SkillPillar>,
     *     entries: array<string, list<\App\Entity\CvEntry|array{title: ?string, content: string}>>,
     *     educations: list<Education>,
     *     certifications: list<Certification>,
     *     languages: list<\App\Entity\Language>
     * }
     */
    public function getContent(): array
    {
        return $this->content(false, false);
    }

    /**
     * Renvoie tout le détail du CV (toutes les puces, parcours terrain détaillé, rubriques complètes, centres
     * d'intérêt), sans aucun champ privé : ni coordonnées, ni prétentions, ni vrais noms anonymisés, ni notes.
     *
     * Liens :
     * - Appelée par : ChatContextBuilder (contexte de l'assistant IA de l'espace recruteur)
     *
     * @return array<string, mixed> mêmes clés que getContent(), « synthetic » à faux
     */
    public function getFullContent(): array
    {
        return $this->content(true, false);
    }

    /**
     * Renvoie le contenu du CV pour l'espace recruteur : tout le détail, vrais noms d'entreprises (même
     * anonymisées), notes privées des expériences, coordonnées directes et prétentions si leur affichage est coché.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::home(), CvPdfRenderer::renderHtml($variant, true),
     *   Admin\RecruiterController::preview()
     *
     * @return array<string, mixed> contenu de getFullContent(), avec « private » (phone, email, salary)
     */
    public function getPrivateContent(): array
    {
        $profile = $this->profileRepository->getOrCreate();

        return [
            'private' => [
                'phone' => $this->completeOrNull($profile->getPrivatePhone()),
                'email' => $this->completeOrNull($profile->getPrivateEmail()),
                'salary' => $profile->isShowSalaryInRecruiterArea() ? $this->completeOrNull($profile->getPrivateSalaryExpectation()) : null,
            ],
        ] + $this->content(true, true);
    }

    /**
     * Construit le contenu à l'un des trois niveaux.
     *
     * @param bool $full    vrai : tout le détail (sinon CV synthétique)
     * @param bool $private vrai : espace recruteur (vrais noms, notes privées)
     *
     * @return array{
     *     synthetic: bool,
     *     profile: array<string, string|null>,
     *     key_figures: list<KeyFigure>,
     *     career: array{professional: list<array<string, mixed>>, foundation: list<array<string, mixed>>, field: list<array<string, mixed>>},
     *     pillars: list<\App\Entity\SkillPillar>,
     *     entries: array<string, list<\App\Entity\CvEntry|array{title: ?string, content: string}>>,
     *     educations: list<Education>,
     *     certifications: list<Certification>,
     *     languages: list<\App\Entity\Language>
     * }
     */
    private function content(bool $full, bool $private): array
    {
        $profile = $this->profileRepository->getOrCreate();
        // Rubriques réservées à l'espace recruteur (correction de recette n° 2) : absentes du CV synthétique
        $showEducations = $full || $profile->isEducationsPublic();

        return [
            'synthetic' => !$full,
            'profile' => $this->publicProfile($profile),
            'key_figures' => \array_slice(array_values(array_filter(
                $this->keyFigureRepository->findAllOrdered(),
                static fn (KeyFigure $figure): bool => $figure->isComplete(),
            )), 0, self::MAX_KEY_FIGURES),
            'career' => $this->career($full, $private),
            'pillars' => $this->skillPillarRepository->findAllOrdered(),
            'entries' => $full ? $this->cvEntryRepository->findGroupedBySection() : $this->syntheticEntries(),
            'educations' => $showEducations ? $this->educationsByYear() : [],
            'certifications' => $showEducations ? $this->certificationsByYear() : [],
            'languages' => $full || $profile->isLanguagesPublic() ? $this->languageRepository->findAllOrdered() : [],
        ];
    }

    /**
     * Diplômes et titres, du plus récent au plus ancien (tri automatique par année, correction de recette n° 3) ;
     * à année égale, l'ordre de l'admin.
     *
     * Liens :
     * - Appelée par : content()
     *
     * @return list<Education>
     */
    private function educationsByYear(): array
    {
        $educations = $this->educationRepository->findAllOrdered();
        usort($educations, static fn (Education $a, Education $b): int => ($b->getYear() ?? 0) <=> ($a->getYear() ?? 0));

        return $educations;
    }

    /**
     * Certifications et habilitations, de la plus récente à la plus ancienne (année d'obtention ; sans date : à la
     * fin) ; à année égale, l'ordre de l'admin.
     *
     * Liens :
     * - Appelée par : content()
     *
     * @return list<Certification>
     */
    private function certificationsByYear(): array
    {
        $certifications = $this->certificationRepository->findAllOrdered();
        usort($certifications, static fn (Certification $a, Certification $b): int => (int) $b->getObtainedOn()?->format('Y') <=> (int) $a->getObtainedOn()?->format('Y'));

        return $certifications;
    }

    /**
     * Rubriques « liste » du CV synthétique : savoir-être en entier ; outils techniques, expertises et logiciels
     * réduits à leur résumé public (sans résumé, l'élément n'apparaît pas) ; jamais les centres d'intérêt.
     *
     * Liens :
     * - Appelée par : content()
     * - Utilise : CvEntryRepository::findGroupedBySection(), CvEntry::getPublicSummary()
     *
     * @return array<string, list<\App\Entity\CvEntry|array{title: ?string, content: string}>> rubriques non vides
     */
    private function syntheticEntries(): array
    {
        $entries = [];
        foreach ($this->cvEntryRepository->findGroupedBySection() as $section => $items) {
            if (CvEntrySection::Interest->value === $section) {
                continue;
            }
            if (CvEntrySection::SoftSkill->value === $section) {
                $entries[$section] = $items;
                continue;
            }
            $summaries = [];
            foreach ($items as $entry) {
                if (null !== $entry->getPublicSummary() && PlaceholderDetector::isComplete($entry->getPublicSummary())) {
                    $summaries[] = ['title' => $entry->getTitle(), 'content' => $entry->getPublicSummary()];
                }
            }
            if ([] !== $summaries) {
                $entries[$section] = $summaries;
            }
        }

        return $entries;
    }

    /**
     * Range les expériences publiées dans les trois rubriques du CV. « Expérience fondatrice » est
     * regroupée par entreprise : un en-tête (entreprise, lieu, période totale), puis les postes successifs.
     *
     * @param bool $full    vrai : toutes les puces (sinon les puces publiques seulement)
     * @param bool $private vrai : espace recruteur (vrais noms, notes privées)
     *
     * @return array{professional: list<array<string, mixed>>, foundation: list<array<string, mixed>>, field: list<array<string, mixed>>}
     */
    private function career(bool $full, bool $private): array
    {
        $career = ['professional' => [], 'foundation' => [], 'field' => []];
        foreach ($this->experienceRepository->findPublishedWithSkills() as $experience) {
            $item = $this->publicExperience($experience, $private, $full);
            if (CareerSection::Foundation !== $experience->getCareerSection()) {
                $career[$experience->getCareerSection()->value][] = $item;
                continue;
            }

            $last = array_key_last($career['foundation']);
            if (null !== $last && $career['foundation'][$last]['company'] === $item['company']) {
                $group = &$career['foundation'][$last];
                $group['roles'][] = $item;
                // Période totale : du début du poste le plus ancien à la fin du plus récent
                if ($item['start'] instanceof \DateTimeImmutable && $item['start'] < $group['start']) {
                    $group['start'] = $item['start'];
                }
                unset($group);
                continue;
            }
            $career['foundation'][] = [
                'company' => $item['company'],
                'location' => $item['location'],
                'start' => $item['start'],
                'end' => $item['end'],
                'dates_in_years' => false,
                'roles' => [$item],
            ];
        }

        return $career;
    }

    /**
     * Ne garde que les champs publics du profil.
     *
     * @return array<string, string|null>
     */
    private function publicProfile(Profile $profile): array
    {
        return [
            'full_name' => $profile->getFullName(),
            'job_title' => $profile->getJobTitle(),
            'tagline' => $profile->getTagline(),
            'pitch' => $this->completeOrNull($profile->getPitch()),
            'summary' => $this->completeOrNull($profile->getSummary()),
            'search_status' => $this->completeOrNull($profile->getSearchStatus()),
            'location' => $this->completeOrNull($profile->getLocation()),
            'mobility' => $this->completeOrNull($profile->getMobility()),
            'work_modes' => $this->completeOrNull($profile->getWorkModes()),
            'target_roles' => $this->completeOrNull($profile->getTargetRoles()),
            'target_companies' => $this->completeOrNull($profile->getTargetCompanies()),
            'contract_types' => $this->completeOrNull($profile->getContractTypes()),
            'availability' => $this->completeOrNull($profile->getAvailability()),
        ] + $this->publicPhoto($profile);
    }

    /**
     * Renvoie la photo à afficher : celle de l'admin si elle existe, sinon la photo par défaut.
     *
     * @return array{photo: string, photo_miniature: string}
     */
    private function publicPhoto(Profile $profile): array
    {
        $photo = $this->profilePhotoProvider->getPhoto($profile->getPhotoFilename());

        return [
            // Chemins relatifs à public/, pour asset()
            'photo' => $photo['photo'],
            'photo_miniature' => $photo['miniature'],
        ];
    }

    /**
     * Prépare une expérience pour l'affichage (nom d'entreprise public, textes complets).
     *
     * CV synthétique ($full faux) : seules les puces cochées « Visible en public » ; « hidden_count » compte les
     * puces réservées à l'espace recruteur (mention « + n points détaillés ») ; une ligne du « Parcours terrain »
     * se réduit au poste, à l'entreprise, au lieu et aux dates.
     *
     * @param bool $private vrai : espace recruteur (vrai nom de l'entreprise, précisions, note privée)
     * @param bool $full    vrai : toutes les puces
     *
     * @return array<string, mixed>
     */
    private function publicExperience(Experience $experience, bool $private, bool $full): array
    {
        $anonymized = $experience->isCompanyAnonymized() && !$private;
        $achievements = $experience->getPublicAchievementList();
        $missions = $experience->getPublicMissionList();
        $hidden = 0;
        if (!$full) {
            $total = \count($achievements) + \count($missions);
            $public = $experience->isFieldCareer() ? [] : $experience->getPublicItems();
            $achievements = array_values(array_intersect($achievements, $public));
            $missions = array_values(array_intersect($missions, $public));
            $hidden = $experience->isFieldCareer() ? 0 : $total - \count($achievements) - \count($missions);
        }

        return [
            'id' => $experience->getId(),
            'job_title' => $experience->getJobTitle(),
            // Jamais le vrai nom si l'expérience est anonymisée (contrôle côté serveur).
            'company' => $private ? $experience->getCompanyName() : $experience->getPublicCompanyName(),
            'company_details' => $anonymized ? null : $this->completeOrNull($experience->getCompanyDetails()),
            'sector' => $experience->getSector(),
            'company_size' => $experience->getCompanySize(),
            'location' => $experience->getLocation(),
            'start' => $experience->getStartDate(),
            'end' => $experience->getEndDate(),
            'dates_in_years' => $experience->isDatesInYears(),
            'summary' => $this->completeOrNull($experience->getSummary()),
            'missions' => $missions,
            'achievements' => $achievements,
            'hidden_count' => $hidden,
            // Outils d'une expérience : détail réservé à l'espace recruteur
            'tools' => $full ? array_values(array_filter($experience->getToolList(), PlaceholderDetector::isComplete(...))) : [],
            // Note privée (ex. motif de fin de poste) : jamais dans le contenu public
            'private_note' => $private ? $this->completeOrNull($experience->getPrivateNote()) : null,
        ];
    }

    /**
     * Renvoie le texte s'il est complet, sinon null (il ne sera pas affiché).
     */
    private function completeOrNull(?string $text): ?string
    {
        return PlaceholderDetector::isComplete($text) ? trim((string) $text) : null;
    }
}
