<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Certification;
use App\Entity\CvEntry;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\KeyFigure;
use App\Entity\Language;
use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\SkillPillar;
use App\Enum\CareerSection;
use App\Enum\CertificationType;
use App\Enum\CvEntrySection;
use App\Enum\ProjectType;
use App\Repository\CertificationRepository;
use App\Repository\CvEntryRepository;
use App\Repository\EducationRepository;
use App\Repository\ExperienceRepository;
use App\Repository\KeyFigureRepository;
use App\Repository\LanguageRepository;
use App\Repository\ProfileRepository;
use App\Repository\ProjectRepository;
use App\Repository\SkillPillarRepository;
use App\Repository\SkillRepository;
use App\Service\Content\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aligne le contenu du CV en base sur TEXTES_SITE.md (lui-même tiré du CV de référence).
 *
 * Deux temps, un seul chemin de code : synchronize($content, false) liste ce qui changerait (rien
 * n'est écrit), synchronize($content, true) applique exactement cette liste. Ainsi l'aperçu validé
 * par l'utilisateur est ce qui est écrit.
 *
 * Règles :
 * - profil : seuls les champs présents dans le fichier sont touchés (coordonnées privées, photo et mobilité
 *   ne le sont jamais) ; les prétentions 🔒 vont dans leur champ privé, avec l'affichage dans l'espace
 *   recruteur coché (jamais en public ni dans le chatbot) ; une ligne 🚫 n'est jamais lue ;
 * - chiffres clés, piliers, langues, formations, rubriques du CV : alignés ligne à ligne (mise à jour,
 *   ajout, suppression des lignes en trop) ; les compétences des anciens piliers sont supprimées ;
 * - expériences : alignées ligne à ligne quand leur nombre est le même (seules celles qui diffèrent sont
 *   mises à jour, identifiants et compétences liées gardés) ; si leur nombre change, rapprochées par
 *   rubrique, entreprise, poste et début : une ligne ajoutée au fichier donne un seul « ajouté », les
 *   autres sont gardées (et mises à jour si besoin) ;
 * - habilitations et certifications (lignes « Habilitations » et « Certifications » du § 10) : alignées ligne à
 *   ligne, chacune dans sa nature ; sans ligne « Certifications », celles de l'admin ne sont pas touchées ;
 * - visibilité publique des rubriques Langues et Formations (repère 🔒 du titre des § 9 et 10) ;
 * - études de cas : seuls les brouillons sont renommés (titre, adresse, compétences), jamais un projet publié ;
 * - CV public allégé : puces « Visible en public » (repère 🌐 du fichier) et résumés publics des rubriques
 *   alignés comme le reste (une case cochée dans l'admin mais absente du fichier est décochée).
 *
 * Liens :
 * - Utilisé par : SyncTextesSiteCommand (app:content:sync-textes)
 * - Utilise : TextesSiteParser (contenu en entrée), dépôts du contenu, SlugGenerator
 * - Testé par : tests/Command/SyncTextesSiteCommandTest
 */
final readonly class CvContentSynchronizer
{
    /** Champs du profil repris du fichier (clé du parseur => libellé du rapport). */
    private const PROFILE_FIELDS = [
        'fullName' => 'Nom',
        'jobTitle' => 'Titre',
        'tagline' => 'Ligne de domaines',
        'pitch' => 'Accroche',
        'summary' => 'Profil (résumé)',
        'searchStatus' => 'Statut de recherche',
        'availability' => 'Disponibilité',
        'location' => 'Localisation',
        'workModes' => 'Modalités de travail',
        'targetRoles' => 'Postes visés',
        'targetCompanies' => 'Structures visées',
        'contractTypes' => 'Contrat recherché',
    ];

    /** Icône de carte des quatre domaines (nom du pilier => icône de SkillPillar::ICONS). */
    private const PILLAR_ICONS = ['Conception' => 'design', 'Projets' => 'project', 'Industrie' => 'climate', 'Numérique' => 'digital'];

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProfileRepository $profileRepository,
        private KeyFigureRepository $keyFigureRepository,
        private SkillPillarRepository $skillPillarRepository,
        private SkillRepository $skillRepository,
        private ExperienceRepository $experienceRepository,
        private CvEntryRepository $cvEntryRepository,
        private LanguageRepository $languageRepository,
        private EducationRepository $educationRepository,
        private ProjectRepository $projectRepository,
        private CertificationRepository $certificationRepository,
        private SlugGenerator $slugGenerator,
    ) {
    }

    /**
     * Compare le contenu du fichier à la base ; avec $apply, enregistre les changements.
     *
     * Liens :
     * - Appelée par : SyncTextesSiteCommand::execute() (aperçu, puis --apply)
     *
     * @param array<string, mixed> $content résultat de TextesSiteParser::extract()
     * @param bool                 $apply   vrai : écrire en base ; faux : seulement lister
     *
     * @return list<array{area: string, item: string, action: string, old: ?string, new: ?string}>
     *                                                                                             action : « remplacé », « ajouté » ou « supprimé » ; liste vide si tout est déjà à jour
     */
    public function synchronize(array $content, bool $apply): array
    {
        $changes = [];
        // Un seul profil pour les deux étapes (sans profil en base, getOrCreate() en créerait deux)
        $profile = $this->profileRepository->getOrCreate();
        $this->syncProfile($profile, $content['profile'], $apply, $changes);
        $this->syncRecruiterOnly($profile, $content['recruiter_only'] ?? [], $apply, $changes);
        $this->syncSectionVisibility($profile, $content['section_visibility'] ?? null, $apply, $changes);
        $this->syncKeyFigures($content['key_figures'], $apply, $changes);
        $this->syncPillars($content['pillars'], $apply, $changes);
        $this->syncExperiences($content['experiences'], $apply, $changes);
        $this->syncEntries($content['entries'], $apply, $changes);
        $this->syncLanguages($content['languages'], $apply, $changes);
        $this->syncEducations($content['educations'], $apply, $changes);
        $this->syncCertificationType(CertificationType::Clearance, 'Habilitations', $content['clearances'] ?? [], $apply, $changes);
        $this->syncCertificationType(CertificationType::Certification, 'Certifications', $content['certifications'] ?? [], $apply, $changes);
        $this->syncCaseStudies($content['case_studies'], $apply, $changes);

        if ($apply) {
            $this->entityManager->flush();
        }

        return $changes;
    }

    /**
     * Profil : met à jour les champs repris du fichier.
     *
     * @param Profile                                                                             $profile profil en base (ou nouveau, enregistré ici)
     * @param array<string, string>                                                               $fields  champs du parseur
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function syncProfile(Profile $profile, array $fields, bool $apply, array &$changes): void
    {
        foreach (self::PROFILE_FIELDS as $field => $label) {
            if (!isset($fields[$field])) {
                continue;
            }
            $old = $profile->{'get'.ucfirst($field)}();
            if ($old === $fields[$field]) {
                continue;
            }
            $changes[] = $this->change('Profil', $label, null === $old || '' === $old ? 'ajouté' : 'remplacé', $old, $fields[$field]);
            if ($apply) {
                $profile->{'set'.ucfirst($field)}($fields[$field]);
            }
        }
        if ($apply && null === $profile->getId()) {
            $this->entityManager->persist($profile);
        }
    }

    /**
     * Données de l'espace recruteur (lignes 🔒 du § 3) : prétentions écrites dans leur champ privé, avec
     * l'affichage dans l'espace recruteur coché. Rien n'est touché si le fichier n'en donne pas.
     *
     * Liens :
     * - Appelée par : synchronize()
     * - Utilise : TextesSiteParser::extractRecruiterOnly() (en amont), Profile (prétentions privées)
     * - Voir aussi : CvContentProvider::getPrivateContent() (seul lecteur des prétentions)
     *
     * @param Profile                                                                             $profile profil en base (ou nouveau, enregistré par syncProfile())
     * @param array{salary?: string}                                                              $fields  données 🔒 du parseur
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function syncRecruiterOnly(Profile $profile, array $fields, bool $apply, array &$changes): void
    {
        if (!isset($fields['salary'])) {
            return;
        }
        $old = $profile->getPrivateSalaryExpectation();
        if ($old !== $fields['salary']) {
            $changes[] = $this->change('Profil (espace recruteur)', 'Prétentions', null === $old || '' === $old ? 'ajouté' : 'remplacé', $old, $fields['salary']);
        }
        if (!$profile->isShowSalaryInRecruiterArea()) {
            $changes[] = $this->change('Profil (espace recruteur)', 'Afficher les prétentions dans l\'espace recruteur', 'remplacé', 'non', 'oui');
        }
        if ($apply) {
            $profile->setPrivateSalaryExpectation($fields['salary'])->setShowSalaryInRecruiterArea(true);
            if (null === $profile->getId()) {
                $this->entityManager->persist($profile);
            }
        }
    }

    /**
     * Visibilité publique des rubriques « Langues » et « Formations et certifications » (repère 🔒 du titre des § 9
     * et 10, correction de recette n° 2). Rien n'est touché si le fichier ne la donne pas.
     *
     * Liens :
     * - Appelée par : synchronize()
     * - Utilise : TextesSiteParser::extractSectionVisibility() (en amont), Profile (languagesPublic, educationsPublic)
     *
     * @param Profile                                                                             $profile    profil en base (ou nouveau, enregistré par syncProfile())
     * @param array{languages: bool, educations: bool}|null                                       $visibility visibilité lue dans le fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes    changements relevés
     */
    private function syncSectionVisibility(Profile $profile, ?array $visibility, bool $apply, array &$changes): void
    {
        if (null === $visibility) {
            return;
        }
        $label = static fn (bool $public): string => $public ? 'CV public et espace recruteur' : 'espace recruteur seulement';
        if ($profile->isLanguagesPublic() !== $visibility['languages']) {
            $changes[] = $this->change('Visibilité des rubriques', 'Langues', 'remplacé', $label($profile->isLanguagesPublic()), $label($visibility['languages']));
        }
        if ($profile->isEducationsPublic() !== $visibility['educations']) {
            $changes[] = $this->change('Visibilité des rubriques', 'Formations et certifications', 'remplacé', $label($profile->isEducationsPublic()), $label($visibility['educations']));
        }
        if ($apply) {
            $profile->setLanguagesPublic($visibility['languages'])->setEducationsPublic($visibility['educations']);
            if (null === $profile->getId()) {
                $this->entityManager->persist($profile);
            }
        }
    }

    /**
     * Chiffres clés : alignés ligne à ligne.
     *
     * @param list<array{value: string, label: string}>                                           $figures chiffres du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function syncKeyFigures(array $figures, bool $apply, array &$changes): void
    {
        $existing = $this->keyFigureRepository->findAllOrdered();
        foreach ($figures as $index => $figure) {
            $new = $figure['value'].' '.$figure['label'];
            $current = $existing[$index] ?? null;
            if (null !== $current && $current->getValue().' '.$current->getLabel() === $new) {
                continue;
            }
            $changes[] = $this->change('Chiffres clés', 'Chiffre '.($index + 1), null === $current ? 'ajouté' : 'remplacé', null === $current ? null : $current->getValue().' '.$current->getLabel(), $new);
            if ($apply) {
                $current ??= $this->persistNew(new KeyFigure());
                $current->setValue($figure['value'])->setLabel($figure['label'])->setPosition($index + 1);
            }
        }
        $this->removeExtra('Chiffres clés', \array_slice($existing, \count($figures)), static fn (KeyFigure $f): string => $f->getValue().' '.$f->getLabel(), $apply, $changes);
    }

    /**
     * Piliers (quatre domaines du CV) : alignés ligne à ligne ; les compétences des anciens piliers sont supprimées.
     * L'icône est comparée aussi (self::PILLAR_ICONS) : un pilier au bon nom mais à l'ancienne icône est réaligné.
     *
     * Liens :
     * - Appelée par : self::synchronize()
     * - Utilise : SkillPillarRepository, self::PILLAR_ICONS (SkillPillar::ICONS)
     *
     * @param list<array{name: string, summary: string}>                                          $pillars piliers du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function syncPillars(array $pillars, bool $apply, array &$changes): void
    {
        // Compétences lues par leur dépôt (la collection d'un pilier déjà chargé peut ne pas être à jour)
        foreach ($this->skillRepository->findAllOrdered() as $skill) {
            $changes[] = $this->change('Compétences', (string) $skill->getPillar()?->getName(), 'supprimé', $skill->getName(), null);
            if ($apply) {
                $this->entityManager->remove($skill);
            }
        }
        $existing = $this->skillPillarRepository->findAllOrdered();
        // Description comparée et affichée dans le rapport : nom, résumé et icône
        $describe = static fn (string $name, string $summary, string $icon): string => $name.' : '.$summary.' (icône '.$icon.')';
        foreach ($pillars as $index => $data) {
            $current = $existing[$index] ?? null;
            $icon = self::PILLAR_ICONS[$data['name']] ?? 'generic';
            $new = $describe($data['name'], $data['summary'], $icon);
            $old = null === $current ? null : $describe($current->getName(), (string) $current->getSummary(), $current->getIcon());
            if (null !== $current && $old === $new && null === $current->getBadge()) {
                continue;
            }
            $changes[] = $this->change('Domaines (piliers)', 'Domaine '.($index + 1), null === $current ? 'ajouté' : 'remplacé', $old, $new);
            if ($apply) {
                $current ??= $this->persistNew(new SkillPillar());
                $current->setName($data['name'])->setSummary($data['summary'])->setBadge(null)
                    ->setIcon($icon)->setPosition($index + 1);
            }
        }
        $this->removeExtra('Domaines (piliers)', \array_slice($existing, \count($pillars)), static fn (SkillPillar $p): string => $p->getName(), $apply, $changes);
    }

    /**
     * Expériences, dans l'ordre du fichier : même nombre → mise à jour ligne à ligne des seules expériences
     * qui diffèrent ; nombre différent → remplacement en bloc. Rien n'est fait si elles sont identiques.
     *
     * @param list<array<string, mixed>>                                                          $experiences expériences du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes     changements relevés
     */
    private function syncExperiences(array $experiences, bool $apply, array &$changes): void
    {
        $existing = $this->experienceRepository->findAllOrdered();
        $describe = fn (Experience $e): string => $this->describeExperience($e);
        $built = array_map(fn (array $data): Experience => $this->buildExperience($data), $experiences);

        if (array_map($describe, $existing) === array_map($describe, $built)) {
            return;
        }

        // Même nombre : ligne à ligne. Sinon : rapprochement par clé (rubrique, entreprise, poste, début)
        $pairs = \count($existing) === \count($built) ? $existing : $this->matchExperiences($existing, $built);
        foreach ($built as $index => $experience) {
            $current = $pairs[$index] ?? null;
            if (null === $current) {
                $changes[] = $this->change('Expériences', $this->experienceLabel($experience), 'ajouté', null, $this->describeExperience($experience));
                if ($apply) {
                    $this->entityManager->persist($experience->setPosition($index + 1));
                }
                continue;
            }
            $old = $this->describeExperience($current);
            $new = $this->describeExperience($experience);
            if ($old !== $new) {
                $changes[] = $this->change('Expériences', $this->experienceLabel($experience), 'remplacé', $old, $new);
                if ($apply) {
                    $this->copyExperience($experience, $current);
                }
            }
            // Une expérience gardée suit l'ordre du fichier (ligne insérée avant elle)
            if ($apply && $current->getPosition() !== $index + 1) {
                $current->setPosition($index + 1);
            }
        }
        foreach ($existing as $experience) {
            if (\in_array($experience, $pairs, true)) {
                continue;
            }
            $changes[] = $this->change('Expériences', $this->experienceLabel($experience), 'supprimé', $this->describeExperience($experience), null);
            if ($apply) {
                $this->entityManager->remove($experience);
            }
        }
    }

    /**
     * Rapproche les expériences en base de celles du fichier quand leur nombre diffère : même rubrique,
     * même entreprise, même poste et même mois de début.
     *
     * Liens :
     * - Appelée par : syncExperiences()
     *
     * @param list<Experience> $existing expériences en base, dans l'ordre
     * @param list<Experience> $built    expériences du fichier (buildExperience()), dans l'ordre
     *
     * @return array<int, Experience> index dans $built => expérience en base correspondante (absente : à ajouter)
     */
    private function matchExperiences(array $existing, array $built): array
    {
        $key = static fn (Experience $e): string => implode('|', [$e->getCareerSection()->value, $e->getCompanyName(), $e->getJobTitle(), $e->getStartDate()?->format('Y-m')]);
        $available = [];
        foreach ($existing as $experience) {
            $available[$key($experience)][] = $experience;
        }
        $pairs = [];
        foreach ($built as $index => $experience) {
            if ([] !== ($available[$key($experience)] ?? [])) {
                $pairs[$index] = array_shift($available[$key($experience)]);
            }
        }

        return $pairs;
    }

    /**
     * Rubriques « liste » du CV : alignées ligne à ligne, rubrique par rubrique.
     *
     * @param list<array{section: CvEntrySection, title: ?string, content: string, public_summary?: ?string}> $entries éléments du fichier (résumé public : repère 🌐)
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}>             $changes changements relevés
     */
    private function syncEntries(array $entries, bool $apply, array &$changes): void
    {
        $existing = $this->cvEntryRepository->findGroupedBySection();
        $position = 0;
        foreach (CvEntrySection::cases() as $section) {
            $wanted = array_values(array_filter($entries, static fn (array $e): bool => $section === $e['section']));
            $current = $existing[$section->value] ?? [];
            foreach ($wanted as $index => $data) {
                ++$position;
                $entry = $current[$index] ?? null;
                $summary = $data['public_summary'] ?? null;
                $new = (null === $data['title'] ? '' : $data['title'].' : ').$data['content'].(null === $summary ? '' : ' | 🌐 '.$summary);
                $old = null === $entry ? null : (null === $entry->getTitle() ? '' : $entry->getTitle().' : ').$entry->getContent()
                    .(null === $entry->getPublicSummary() ? '' : ' | 🌐 '.$entry->getPublicSummary());
                if ($old !== $new) {
                    $changes[] = $this->change('Rubriques du CV', 'Rubrique « '.$this->sectionLabel($section).' »', null === $entry ? 'ajouté' : 'remplacé', $old, $new);
                }
                if ($apply) {
                    $entry ??= $this->persistNew(new CvEntry());
                    $entry->setSection($section)->setTitle($data['title'])->setContent($data['content'])->setPublicSummary($summary)->setPosition($position);
                }
            }
            $this->removeExtra('Rubriques du CV', \array_slice($current, \count($wanted)), static fn (CvEntry $e): string => (string) $e, $apply, $changes);
        }
    }

    /**
     * Langues : alignées ligne à ligne.
     *
     * @param list<array{name: string, proficiency: string}>                                      $languages langues du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes   changements relevés
     */
    private function syncLanguages(array $languages, bool $apply, array &$changes): void
    {
        $existing = $this->languageRepository->findAllOrdered();
        foreach ($languages as $index => $data) {
            $current = $existing[$index] ?? null;
            $new = $data['name'].' — '.$data['proficiency'];
            $old = null === $current ? null : $current->getName().' — '.$current->getProficiency();
            if ($old === $new) {
                continue;
            }
            $changes[] = $this->change('Langues', $data['name'], null === $current ? 'ajouté' : 'remplacé', $old, $new);
            if ($apply) {
                $current ??= $this->persistNew(new Language());
                $current->setName($data['name'])->setProficiency($data['proficiency'])->setPosition($index + 1);
            }
        }
        $this->removeExtra('Langues', \array_slice($existing, \count($languages)), static fn (Language $l): string => $l->getName(), $apply, $changes);
    }

    /**
     * Formations : alignées ligne à ligne (année, diplôme, lieu, organisme).
     *
     * @param list<array{year: int, title: string, location: ?string, institution: ?string}>      $educations formations du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes    changements relevés
     */
    private function syncEducations(array $educations, bool $apply, array &$changes): void
    {
        $existing = $this->educationRepository->findAllOrdered();
        $describe = static fn (?int $year, string $title, ?string $location, ?string $institution): string => \sprintf('%d · %s · %s · %s', $year, $title, $location ?? '—', $institution ?? '—');
        foreach ($educations as $index => $data) {
            $current = $existing[$index] ?? null;
            $new = $describe($data['year'], $data['title'], $data['location'], $data['institution']);
            $old = null === $current ? null : $describe($current->getYear(), $current->getTitle(), $current->getLocation(), $current->getInstitution());
            if ($old === $new) {
                continue;
            }
            $changes[] = $this->change('Formations', (string) $data['year'], null === $current ? 'ajouté' : 'remplacé', $old, $new);
            if ($apply) {
                $current ??= $this->persistNew(new Education());
                $current->setYear($data['year'])->setTitle($data['title'])->setLocation($data['location'])
                    ->setInstitution($data['institution'])->setPosition($index + 1);
            }
        }
        $this->removeExtra('Formations', \array_slice($existing, \count($educations)), static fn (Education $e): string => $e->getYear().' '.$e->getTitle(), $apply, $changes);
    }

    /**
     * Certifications d'une nature (habilitations : ligne « Habilitations » du § 10 ; certifications : ligne
     * « Certifications », correction de recette n° 3) : alignées ligne à ligne. Une nature absente du fichier n'est
     * pas touchée ; présente, le fichier fait foi (une certification de cette nature saisie dans l'admin et absente du
     * fichier est supprimée, comme pour les autres rubriques).
     *
     * Liens :
     * - Appelée par : synchronize()
     * - Utilise : CertificationRepository
     *
     * @param CertificationType                                                                   $type    nature alignée
     * @param string                                                                              $area    partie du rapport (« Habilitations », « Certifications »)
     * @param list<array{name: string, issuer: ?string, year: int}>                               $items   éléments du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function syncCertificationType(CertificationType $type, string $area, array $items, bool $apply, array &$changes): void
    {
        $existing = array_values(array_filter(
            $this->certificationRepository->findAllOrdered(),
            static fn (Certification $c): bool => $type === $c->getType(),
        ));
        if ([] === $items && CertificationType::Certification === $type) {
            // Sans ligne « Certifications » dans le fichier : celles de l'admin restent
            return;
        }
        $describe = static fn (string $name, ?string $issuer, ?int $year): string => \sprintf('%s · %s · %s', $year ?? '—', $name, $issuer ?? '—');
        foreach ($items as $index => $data) {
            $current = $existing[$index] ?? null;
            $new = $describe($data['name'], $data['issuer'], $data['year']);
            $old = null === $current ? null : $describe($current->getName(), $current->getIssuer(), null === $current->getObtainedOn() ? null : (int) $current->getObtainedOn()->format('Y'));
            if ($old === $new) {
                continue;
            }
            $changes[] = $this->change($area, $data['name'], null === $current ? 'ajouté' : 'remplacé', $old, $new);
            if ($apply) {
                $current ??= $this->persistNew(new Certification());
                $current->setType($type)->setName($data['name'])->setIssuer($data['issuer'])
                    ->setObtainedOn(new \DateTimeImmutable($data['year'].'-01-01'))->setPosition($index + 1);
            }
        }
        $this->removeExtra($area, \array_slice($existing, \count($items)), static fn (Certification $c): string => $c->getName(), $apply, $changes);
    }

    /**
     * Études de cas : renomme les brouillons (titre, adresse, compétences), dans l'ordre ; jamais un projet publié.
     *
     * @param list<array{title: string, competences: list<string>}>                               $cases   études de cas du fichier
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function syncCaseStudies(array $cases, bool $apply, array &$changes): void
    {
        $drafts = array_values(array_filter(
            $this->projectRepository->findAllOrdered(),
            static fn (Project $p): bool => ProjectType::CaseStudy === $p->getType() && !$p->isPublished(),
        ));
        foreach ($cases as $index => $data) {
            $draft = $drafts[$index] ?? null;
            $competences = implode("\n", $data['competences']);
            if (null === $draft || ($draft->getTitle() === $data['title'] && $draft->getCompetences() === $competences)) {
                continue;
            }
            $changes[] = $this->change('Études de cas (brouillons)', 'Cas '.($index + 1), 'remplacé', $draft->getTitle().' — '.str_replace("\n", ', ', (string) $draft->getCompetences()), $data['title'].' — '.implode(', ', $data['competences']));
            if ($apply) {
                $draft->setTitle($data['title'])->setSlug($this->slugGenerator->slugify($data['title']))->setCompetences($competences);
            }
        }
    }

    /**
     * Construit une expérience à partir d'une ligne du parseur (non enregistrée).
     *
     * @param array<string, mixed> $data expérience du fichier
     */
    private function buildExperience(array $data): Experience
    {
        $section = $data['section'];

        return (new Experience())
            ->setCareerSection($section instanceof CareerSection ? $section : CareerSection::Professional)
            ->setJobTitle((string) $data['job_title'])
            ->setCompanyName((string) $data['company'])
            ->setLocation(\is_string($data['location']) ? $data['location'] : null)
            ->setSummary(\is_string($data['summary']) ? $data['summary'] : null)
            ->setStartDate(new \DateTimeImmutable((string) $data['start']))
            ->setEndDate(\is_string($data['end']) ? new \DateTimeImmutable($data['end']) : null)
            ->setDatesInYears((bool) $data['dates_in_years'])
            ->setMissions(\is_array($data['missions']) ? implode("\n", $data['missions']) : null)
            ->setPublicItems(\is_array($data['public_missions'] ?? null) ? array_values(array_map('strval', $data['public_missions'])) : [])
            ->setPrivateNote(\is_string($data['private_note']) ? $data['private_note'] : null)
            ->setIsPublished(true);
    }

    /**
     * Recopie dans une expérience existante les champs repris du fichier (les autres champs, l'identifiant
     * et les compétences liées sont gardés).
     *
     * Liens :
     * - Appelée par : syncExperiences() (alignement ligne à ligne)
     *
     * @param Experience $source expérience construite d'après le fichier (buildExperience())
     * @param Experience $target expérience en base, mise à jour
     */
    private function copyExperience(Experience $source, Experience $target): void
    {
        $target
            ->setCareerSection($source->getCareerSection())
            ->setJobTitle($source->getJobTitle())
            ->setCompanyName($source->getCompanyName())
            ->setLocation($source->getLocation())
            ->setSummary($source->getSummary())
            ->setStartDate($source->getStartDate())
            ->setEndDate($source->getEndDate())
            ->setDatesInYears($source->isDatesInYears())
            ->setMissions($source->getMissions())
            ->setPublicItems($source->getPublicItems())
            ->setPrivateNote($source->getPrivateNote())
            ->setIsPublished(true);
    }

    /**
     * Intitulé court d'une expérience dans le rapport : « Poste — Entreprise (début – fin) ».
     */
    private function experienceLabel(Experience $experience): string
    {
        $format = $experience->isDatesInYears() ? 'Y' : 'm/Y';

        return \sprintf(
            '%s%s (%s – %s)',
            $experience->getJobTitle(),
            '' === $experience->getCompanyName() ? '' : ' — '.$experience->getCompanyName(),
            $experience->getStartDate()?->format($format),
            $experience->getEndDate()?->format($format) ?? '…',
        );
    }

    /**
     * Description complète d'une expérience (comparaison et rapport).
     */
    private function describeExperience(Experience $experience): string
    {
        return implode(' | ', array_filter([
            $this->experienceLabel($experience),
            $experience->getCareerSection()->value,
            $experience->getLocation(),
            $experience->getSummary(),
            $experience->getCompanyDetails(),
            // 🌐 : puce visible sur le CV public
            implode(' / ', array_map(static fn (string $m): string => ($experience->isItemPublic($m) ? '🌐 ' : '').$m, $experience->getMissionList())),
            null === $experience->getPrivateNote() ? null : '🔒 '.$experience->getPrivateNote(),
        ]));
    }

    /**
     * Supprime les lignes en trop (et les relève dans le rapport).
     *
     * @template T of object
     *
     * @param list<T>                                                                             $extra    lignes à supprimer
     * @param callable(T): string                                                                 $describe description d'une ligne
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes  changements relevés
     */
    private function removeExtra(string $area, array $extra, callable $describe, bool $apply, array &$changes): void
    {
        foreach ($extra as $entity) {
            $changes[] = $this->change($area, $describe($entity), 'supprimé', $describe($entity), null);
            if ($apply) {
                $this->entityManager->remove($entity);
            }
        }
    }

    /**
     * Enregistre une nouvelle entité (persist) et la renvoie.
     *
     * @template T of object
     *
     * @param T $entity entité neuve
     *
     * @return T
     */
    private function persistNew(object $entity): object
    {
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * Libellé lisible d'une rubrique « liste ».
     */
    private function sectionLabel(CvEntrySection $section): string
    {
        return match ($section) {
            CvEntrySection::Tools => 'Outils techniques',
            CvEntrySection::Expertise => 'Expertises métiers',
            CvEntrySection::Software => 'Logiciels',
            CvEntrySection::SoftSkill => 'Savoir-être',
            CvEntrySection::Interest => 'Centres d\'intérêt',
        };
    }

    /**
     * Construit une ligne du rapport.
     *
     * @return array{area: string, item: string, action: string, old: ?string, new: ?string}
     */
    private function change(string $area, string $item, string $action, ?string $old, ?string $new): array
    {
        return ['area' => $area, 'item' => $item, 'action' => $action, 'old' => $old, 'new' => $new];
    }
}
