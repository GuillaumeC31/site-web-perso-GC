<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\Experience;
use App\Entity\KeyFigure;
use App\Repository\CertificationRepository;
use App\Repository\ExperienceRepository;
use App\Repository\KeyFigureRepository;
use App\Repository\ProfileRepository;
use App\Repository\ProjectRepository;
use App\Repository\SkillPillarRepository;
use App\Service\Calculator\CalculatorRegistry;

/**
 * Liste ce qui manque au contenu du site, pour l'afficher dans l'admin.
 *
 * Le site public masque les éléments incomplets (chiffres, réalisations) et l'espace recruteur
 * affiche « Coordonnées à compléter » sans coordonnées privées ; l'admin,
 * elle, doit montrer ce qu'il reste à faire, avec un lien vers l'écran concerné.
 *
 * Les points qui bloquent la mise en ligne (le « TOP ») sont marqués « blocking » :
 * coordonnées privées réelles (téléphone, e-mail), mises en avant sur le tableau de bord.
 *
 * Liens :
 * - Utilisé par : App\Service\Dashboard\DashboardMetrics (tuile « Contenu à compléter »)
 * - Utilise : PlaceholderDetector, dépôts du contenu, Project::getMissingSections(),
 *   CalculatorRegistry (normes à vérifier)
 */
final readonly class ContentCompletenessChecker
{
    /** Champs du profil attendus (champ => clé de traduction du libellé). */
    private const PROFILE_FIELDS = [
        'pitch' => 'admin.profile.field.pitch',
        'summary' => 'admin.profile.field.summary',
        'availability' => 'admin.profile.field.availability',
        'location' => 'admin.profile.field.location',
        'workModes' => 'admin.profile.field.work_modes',
        'contractTypes' => 'admin.profile.field.contract_types',
    ];

    /** Coordonnées privées, affichées dans l'espace recruteur (« Coordonnées à compléter » si vides). */
    private const PRIVATE_CONTACT_FIELDS = [
        'privatePhone' => 'admin.profile.field.private_phone',
        'privateEmail' => 'admin.profile.field.private_email',
        'privateSalaryExpectation' => 'admin.profile.field.private_salary_expectation',
    ];

    /** Coordonnées privées qui bloquent la mise en ligne (TOP) tant qu'elles ne sont pas réelles. */
    private const BLOCKING_FIELDS = ['privatePhone', 'privateEmail'];

    /** Écart minimal entre deux expériences signalé comme trou dans le parcours (en mois). */
    private const GAP_MONTHS = 3;

    /** Nombre de chiffres clés attendus sur l'accueil. */
    private const EXPECTED_KEY_FIGURES = 3;

    /**
     * Reçoit les dépôts du contenu.
     */
    public function __construct(
        private ProfileRepository $profileRepository,
        private KeyFigureRepository $keyFigureRepository,
        private ExperienceRepository $experienceRepository,
        private SkillPillarRepository $skillPillarRepository,
        private CertificationRepository $certificationRepository,
        private ProjectRepository $projectRepository,
        private CalculatorRegistry $calculatorRegistry,
    ) {
    }

    /**
     * Renvoie la liste des points à compléter.
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics
     *
     * @return list<array{message: string, parameters: array<string, string|int>, route: string, route_parameters: array<string, int>, blocking: bool}>
     *                                                                                                                                                  message = clé de traduction ; liste vide si tout est complet
     */
    public function findMissingItems(): array
    {
        $items = [];
        $profile = $this->profileRepository->getOrCreate();

        foreach (self::PROFILE_FIELDS as $field => $label) {
            if (!PlaceholderDetector::isComplete($profile->{'get'.ucfirst($field)}())) {
                $items[] = $this->item('admin.completeness.profile_field', 'admin_profile_edit', ['%field%' => $label]);
            }
        }
        foreach (self::PRIVATE_CONTACT_FIELDS as $field => $label) {
            if (!PlaceholderDetector::isComplete($profile->{'get'.ucfirst($field)}())) {
                $items[] = $this->item('admin.completeness.private_contact', 'admin_profile_edit', ['%field%' => $label], [], \in_array($field, self::BLOCKING_FIELDS, true));
            }
        }

        $figures = $this->keyFigureRepository->findAllOrdered();
        $completeFigures = \count(array_filter($figures, static fn (KeyFigure $figure): bool => $figure->isComplete()));
        foreach ($figures as $figure) {
            if (!$figure->isComplete()) {
                $items[] = $this->item('admin.completeness.key_figure_incomplete', 'admin_key_figure_edit', ['%label%' => $figure->getLabel()], ['id' => (int) $figure->getId()]);
            }
        }
        if ($completeFigures < self::EXPECTED_KEY_FIGURES) {
            $items[] = $this->item('admin.completeness.key_figures_missing', 'admin_key_figure_index', ['%count%' => self::EXPECTED_KEY_FIGURES - $completeFigures]);
        }

        $experiences = $this->experienceRepository->findAllOrdered();
        foreach ($experiences as $experience) {
            $this->checkExperience($experience, $items);
        }
        $this->checkCareerGaps($experiences, $items);

        foreach ($this->skillPillarRepository->findAllOrdered() as $pillar) {
            if (!PlaceholderDetector::isComplete($pillar->getSummary())) {
                $items[] = $this->item('admin.completeness.pillar_summary', 'admin_pillar_edit', ['%name%' => $pillar->getName()], ['id' => (int) $pillar->getId()]);
            }
        }

        if (0 === $this->certificationRepository->count([])) {
            $items[] = $this->item('admin.completeness.certifications', 'admin_certification_index');
        }

        // Calculatrice : normes proposées pas encore vérifiées (non affichées en public).
        $unverified = \count($this->calculatorRegistry->getToolsWithUnverifiedReference());
        if ($unverified > 0) {
            $items[] = $this->item('admin.completeness.calculator_references', 'admin_calculator_tool_index', ['%count%' => $unverified]);
        }

        // Projets (brouillons compris) : un projet incomplet n'est jamais affiché sur le site.
        foreach ($this->projectRepository->findAllOrdered() as $project) {
            $missing = \count($project->getMissingSections());
            if ($missing > 0) {
                $items[] = $this->item('admin.completeness.project', 'admin_project_edit', ['%name%' => $project->getTitle(), '%count%' => $missing], ['id' => (int) $project->getId()]);
            }
        }

        return $items;
    }

    /**
     * Ajoute les manques d'une expérience publiée (réalisations chiffrées, textes à compléter).
     *
     * @param list<array{message: string, parameters: array<string, string|int>, route: string, route_parameters: array<string, int>, blocking: bool}> $items
     */
    private function checkExperience(Experience $experience, array &$items): void
    {
        if (!$experience->isPublished()) {
            return;
        }

        // Sans employeur (ex. « Projet entrepreneurial & développement web ») : l'intitulé seul
        $name = $experience->getJobTitle().('' === $experience->getCompanyName() ? '' : ' — '.$experience->getCompanyName());
        $route = ['id' => (int) $experience->getId()];

        // Le « Parcours terrain » n'appelle pas de réalisations chiffrées.
        if (!$experience->isFieldCareer() && $experience->hasIncompleteAchievements()) {
            $items[] = $this->item('admin.completeness.achievements', 'admin_experience_edit', ['%name%' => $name], $route);
        }
        if (\count($experience->getMissionList()) !== \count($experience->getPublicMissionList())) {
            $items[] = $this->item('admin.completeness.missions_placeholder', 'admin_experience_edit', ['%name%' => $name], $route);
        }
    }

    /**
     * Signale les périodes sans expérience de plus de GAP_MONTHS mois (ex. 06/2001 – 09/2001), à expliquer
     * en une ligne (poste, formation…).
     *
     * @param list<Experience>                                                                                                                         $experiences expériences (toutes, publiées ou non)
     * @param list<array{message: string, parameters: array<string, string|int>, route: string, route_parameters: array<string, int>, blocking: bool}> $items
     */
    private function checkCareerGaps(array $experiences, array &$items): void
    {
        $periods = array_values(array_filter(
            $experiences,
            static fn (Experience $e): bool => $e->isPublished() && null !== $e->getStartDate(),
        ));
        usort($periods, static fn (Experience $a, Experience $b): int => $a->getStartDate() <=> $b->getStartDate());

        $coveredUntil = null;
        foreach ($periods as $experience) {
            $start = $experience->getStartDate();
            if (null !== $coveredUntil && null !== $start && $coveredUntil->modify('+'.self::GAP_MONTHS.' months') < $start) {
                $items[] = $this->item('admin.completeness.career_gap', 'admin_experience_index', [
                    '%from%' => $coveredUntil->format('m/Y'),
                    '%to%' => $start->format('m/Y'),
                ]);
            }
            $end = $experience->getEndDate() ?? new \DateTimeImmutable();
            if (null === $coveredUntil || $end > $coveredUntil) {
                $coveredUntil = $end;
            }
        }
    }

    /**
     * Construit un point à compléter.
     *
     * @param array<string, string|int> $parameters      paramètres du message
     * @param array<string, int>        $routeParameters paramètres de la route
     * @param bool                      $blocking        vrai : bloque la mise en ligne (TOP)
     *
     * @return array{message: string, parameters: array<string, string|int>, route: string, route_parameters: array<string, int>, blocking: bool}
     */
    private function item(string $message, string $route, array $parameters = [], array $routeParameters = [], bool $blocking = false): array
    {
        return ['message' => $message, 'parameters' => $parameters, 'route' => $route, 'route_parameters' => $routeParameters, 'blocking' => $blocking];
    }
}
