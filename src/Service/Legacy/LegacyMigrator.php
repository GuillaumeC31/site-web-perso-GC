<?php

declare(strict_types=1);

namespace App\Service\Legacy;

use App\Entity\EnergyProvider;
use App\Entity\Fluid;
use App\Entity\Printer3d;
use App\Entity\Project;
use App\Entity\ProjectCategory;
use App\Entity\ProjectImage;
use App\Entity\SocialLink;
use App\Enum\FluidState;
use App\Enum\ProjectType;
use App\Enum\SocialNetwork;
use App\Repository\EnergyProviderRepository;
use App\Repository\FluidRepository;
use App\Repository\Printer3dRepository;
use App\Repository\ProjectCategoryRepository;
use App\Repository\ProjectRepository;
use App\Repository\SocialLinkRepository;
use App\Service\Admin\PositionManager;
use App\Service\Admin\ProjectImageStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Reprend dans la v2 les données utiles de l'ancien site (lot 8), en deux temps : aperçu (rien n'est écrit),
 * puis application de la même liste.
 *
 * Repris :
 * - projets et catégories : projets du Labo, TOUJOURS créés en brouillon (à relire avant publication),
 *   images converties en WebP ; un projet dont l'adresse (slug) existe déjà est laissé tel quel ;
 * - LinkedIn : seul réseau repris, dans Communication › Réseaux sociaux (GitHub écarté, décision du 24/10/2026) ;
 * - données de la calculatrice (fluides, imprimantes 3D, offres d'électricité) : ajout de ce qui manque,
 *   mise à jour des valeurs changées dans l'ancien admin (la production fait foi).
 * Jamais repris : comptes, recruteurs et demandes, consignes du chatbot, journal des versions v1, réglages.
 *
 * Liens :
 * - Utilisé par : LegacyMigrateCommand (app:legacy:migrate)
 * - Utilise : LegacyDatabaseReader, ProjectImageStorage (WebP), PositionManager, dépôts v2
 * - Testé par : tests/Service/Legacy/LegacyMigratorTest
 */
final readonly class LegacyMigrator
{
    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectRepository $projectRepository,
        private ProjectCategoryRepository $categoryRepository,
        private FluidRepository $fluidRepository,
        private Printer3dRepository $printerRepository,
        private EnergyProviderRepository $providerRepository,
        private SocialLinkRepository $socialLinkRepository,
        private ProjectImageStorage $imageStorage,
        private PositionManager $positionManager,
    ) {
    }

    /**
     * Compare l'ancienne base à la v2 et, si demandé, applique les changements.
     *
     * Liens :
     * - Appelée par : LegacyMigrateCommand::execute()
     *
     * @param LegacyDatabaseReader $reader     ancienne base (lecture seule)
     * @param string|null          $uploadsDir dossier public/uploads de l'ancien site (images des projets) ; null : images non reprises
     * @param bool                 $apply      vrai : écrit en base ; faux : aperçu
     *
     * @return array{changes: list<array{area: string, item: string, action: string, detail: string}>, notes: list<string>}
     */
    public function migrate(LegacyDatabaseReader $reader, ?string $uploadsDir, bool $apply): array
    {
        $changes = [];
        $notes = [];
        $this->migrateProjects($reader->getProjects(), $uploadsDir, $apply, $changes, $notes);
        $this->migrateSocialNetworks($reader->getSocialNetworks(), $apply, $changes, $notes);
        $this->migrateCalculatorData($reader, $apply, $changes);
        if ($apply) {
            $this->entityManager->flush();
        }

        return ['changes' => $changes, 'notes' => $notes];
    }

    /**
     * Projets du Labo et leurs catégories : nouveaux projets créés en brouillon.
     *
     * @param list<array<string, mixed>>                                              $projects projets de l'ancienne base
     * @param list<array{area: string, item: string, action: string, detail: string}> $changes  changements relevés
     * @param list<string>                                                            $notes    remarques
     */
    private function migrateProjects(array $projects, ?string $uploadsDir, bool $apply, array &$changes, array &$notes): void
    {
        foreach ($projects as $data) {
            $slug = (string) $data['slug'];
            if (null !== $this->projectRepository->findOneBy(['slug' => $slug])) {
                $notes[] = \sprintf('Projet « %s » : déjà présent dans la v2 (/projets/%s), laissé tel quel.', $data['title'], $slug);
                continue;
            }

            $categories = array_map(static fn (array $c): string => $c['name'], $data['categories']);
            $changes[] = $this->change('Projets', (string) $data['title'], 'ajouté (brouillon)', \sprintf(
                'Labo, /projets/%s%s%s',
                $slug,
                [] === $categories ? '' : ', catégories : '.implode(', ', $categories),
                $data['is_published'] ? ' (publié sur l\'ancien site : à relire avant de le publier)' : '',
            ));
            if (!$apply) {
                continue;
            }

            $project = (new Project())->setType(ProjectType::Lab)->setTitle(mb_substr((string) $data['title'], 0, 160))->setSlug($slug)
                ->setSummary($data['excerpt'])->setRepositoryUrl($this->httpsOrNull($data['repository_url']))
                ->setDemoUrl($this->httpsOrNull($data['demo_url']))->setIsPublished(false);
            foreach ($data['categories'] as $row) {
                $project->addCategory($this->findOrCreateCategory($row['name'], $row['slug'], $changes));
            }
            $project->setContent($this->importContentImages($project, $data['content'], $uploadsDir, $notes));
            if (null !== $data['cover_image']) {
                $project->setCoverImage($this->storeImage($uploadsDir, $data['cover_image'], true, $notes)['filename'] ?? null);
            }
            $this->positionManager->placeLast($project, $this->projectRepository);
            $this->entityManager->persist($project);
        }
    }

    /**
     * Réseaux sociaux : seul LinkedIn est repris (Communication › Réseaux sociaux, visible partout) ; les autres sont
     * signalés.
     *
     * @param list<array{name: string, url: string}>                                  $networks réseaux de l'ancienne base
     * @param list<array{area: string, item: string, action: string, detail: string}> $changes  changements relevés
     * @param list<string>                                                            $notes    remarques
     */
    private function migrateSocialNetworks(array $networks, bool $apply, array &$changes, array &$notes): void
    {
        $link = $this->socialLinkRepository->findFirstLinkedIn();
        foreach ($networks as $network) {
            if (!str_contains(mb_strtolower($network['url']), 'linkedin.com')) {
                $notes[] = \sprintf('Réseau « %s » non repris (seul LinkedIn est affiché).', $network['name']);
                continue;
            }
            $url = rtrim($network['url'], '/');
            if (null !== $link && $url === rtrim($link->getUrl(), '/')) {
                $notes[] = 'LinkedIn : même adresse que dans la v2 (Communication › Réseaux sociaux).';
                continue;
            }
            $changes[] = $this->change('Réseaux', 'LinkedIn', null === $link ? 'ajouté' : 'remplacé', ($link?->getUrl() ?? '—').' → '.$url);
            if ($apply) {
                if (null === $link) {
                    // LinkedIn : visible partout (décision du 2026-10-29)
                    $link = (new SocialLink())->setNetwork(SocialNetwork::LinkedIn)->setVisible(true);
                    $this->positionManager->placeLast($link, $this->socialLinkRepository);
                }
                $link->setUrl($url);
                $this->entityManager->persist($link);
            }
        }
    }

    /**
     * Données de la calculatrice : ajout de ce qui manque, mise à jour des valeurs différentes.
     *
     * @param list<array{area: string, item: string, action: string, detail: string}> $changes changements relevés
     */
    private function migrateCalculatorData(LegacyDatabaseReader $reader, bool $apply, array &$changes): void
    {
        foreach ($reader->getFluids() as $row) {
            $fluid = $this->fluidRepository->findOneBy(['name' => $row['name']]);
            $new = \sprintf('%s, %s kg/m³, %s J/(kg·K)', $row['state'], $row['density'], $row['specific_heat']);
            if (null === $fluid) {
                $changes[] = $this->change('Calculatrice : fluides', $row['name'], 'ajouté', $new);
                if ($apply) {
                    $fluid = new Fluid();
                    $this->positionManager->placeLast($fluid, $this->fluidRepository);
                    $this->entityManager->persist($fluid);
                }
            } elseif ($fluid->getState()->value !== $row['state'] || abs($fluid->getDensity() - $row['density']) > 1e-9 || abs($fluid->getSpecificHeatCapacity() - $row['specific_heat']) > 1e-9) {
                $changes[] = $this->change('Calculatrice : fluides', $row['name'], 'remplacé', \sprintf('%s, %s kg/m³, %s J/(kg·K) → %s', $fluid->getState()->value, $fluid->getDensity(), $fluid->getSpecificHeatCapacity(), $new));
            } else {
                continue;
            }
            if ($apply && null !== $fluid) {
                $fluid->setName($row['name'])->setState(FluidState::from($row['state']))->setDensity($row['density'])->setSpecificHeatCapacity($row['specific_heat']);
            }
        }

        foreach ($reader->getPrinters() as $row) {
            $printer = $this->printerRepository->findOneBy(['brand' => $row['brand'], 'model' => $row['model']]);
            $label = $row['brand'].' '.$row['model'];
            if (null === $printer) {
                $changes[] = $this->change('Calculatrice : imprimantes 3D', $label, 'ajouté', $row['power'].' W');
                if ($apply) {
                    $printer = (new Printer3d())->setBrand($row['brand'])->setModel($row['model']);
                    $this->positionManager->placeLast($printer, $this->printerRepository);
                    $this->entityManager->persist($printer);
                }
            } elseif ($printer->getPowerConsumption() !== $row['power']) {
                $changes[] = $this->change('Calculatrice : imprimantes 3D', $label, 'remplacé', $printer->getPowerConsumption().' W → '.$row['power'].' W');
            } else {
                continue;
            }
            if ($apply && null !== $printer) {
                $printer->setPowerConsumption($row['power']);
            }
        }

        foreach ($reader->getEnergyProviders() as $row) {
            $provider = $this->providerRepository->findOneBy(['name' => $row['name'], 'planName' => $row['plan']]);
            $label = $row['name'].' — '.$row['plan'];
            if (null === $provider) {
                $changes[] = $this->change('Calculatrice : offres d\'électricité', $label, 'ajouté', $row['price_kwh'].' €/kWh');
                if ($apply) {
                    $provider = (new EnergyProvider())->setName($row['name'])->setPlanName($row['plan']);
                    $this->positionManager->placeLast($provider, $this->providerRepository);
                    $this->entityManager->persist($provider);
                }
            } elseif (abs($provider->getPriceKwh() - $row['price_kwh']) > 1e-9) {
                $changes[] = $this->change('Calculatrice : offres d\'électricité', $label, 'remplacé', $provider->getPriceKwh().' → '.$row['price_kwh'].' €/kWh');
            } else {
                continue;
            }
            if ($apply && null !== $provider) {
                $provider->setPriceKwh($row['price_kwh']);
            }
        }
    }

    /**
     * Renvoie la catégorie de projets de ce slug, créée si elle n'existe pas (et relevée dans le rapport).
     *
     * @param list<array{area: string, item: string, action: string, detail: string}> $changes changements relevés
     */
    private function findOrCreateCategory(string $name, string $slug, array &$changes): ProjectCategory
    {
        $category = $this->categoryRepository->findOneBySlug($slug);
        if (null === $category) {
            foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                if ($entity instanceof ProjectCategory && $entity->getSlug() === $slug) {
                    return $entity;
                }
            }
            $category = (new ProjectCategory())->setName(mb_substr($name, 0, 80))->setSlug($slug);
            $this->positionManager->placeLast($category, $this->categoryRepository);
            $this->entityManager->persist($category);
        }

        return $category;
    }

    /**
     * Reprend les images du contenu (/uploads/projects/…) : converties en WebP, ajoutées à la galerie,
     * adresses réécrites. Le contenu HTML de l'ancien site est gardé (nettoyé à l'affichage).
     *
     * @param list<string> $notes remarques
     */
    private function importContentImages(Project $project, ?string $content, ?string $uploadsDir, array &$notes): ?string
    {
        if (null === $content) {
            return null;
        }
        preg_match_all('#/uploads/projects/([A-Za-z0-9._-]+)#', $content, $matches);
        foreach (array_unique($matches[1]) as $original) {
            $stored = $this->storeImage($uploadsDir, $original, false, $notes);
            if (null === $stored) {
                continue;
            }
            $project->addImage((new ProjectImage())->setFilename($stored['filename'])->setDimensions($stored['width'], $stored['height']));
            $content = str_replace('/uploads/projects/'.$original, '/'.ProjectImageStorage::PUBLIC_DIRECTORY.'/'.$stored['filename'], $content);
        }

        return $content;
    }

    /**
     * Copie une image de l'ancien site (jamais modifiée) et la convertit en WebP.
     *
     * @param string       $filename nom du fichier dans public/uploads/projects de l'ancien site
     * @param list<string> $notes    remarques
     *
     * @return array{filename: string, width: int, height: int}|null null si l'image manque ou est illisible
     */
    private function storeImage(?string $uploadsDir, string $filename, bool $cover, array &$notes): ?array
    {
        $source = null === $uploadsDir ? null : rtrim($uploadsDir, '/\\').'/projects/'.basename($filename);
        if (null === $source || !is_file($source)) {
            $notes[] = \sprintf('Image « %s » non trouvée dans le dossier des images de l\'ancien site : à ajouter à la main.', basename($filename));

            return null;
        }
        // Copie temporaire : le fichier de l'ancien site n'est ni déplacé ni modifié
        $temporary = (string) tempnam(sys_get_temp_dir(), 'legacy_');
        try {
            copy($source, $temporary);
            if ($cover) {
                return ['filename' => $this->imageStorage->storeCover(new File($temporary)), 'width' => ProjectImageStorage::COVER_WIDTH, 'height' => ProjectImageStorage::COVER_HEIGHT];
            }

            return $this->imageStorage->storeGalleryImage(new File($temporary));
        } catch (\InvalidArgumentException) {
            $notes[] = \sprintf('Image « %s » illisible ou dans un format non accepté (SVG…) : à refaire en JPEG, PNG ou WebP.', basename($filename));

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Garde une adresse seulement si elle est en HTTPS.
     */
    private function httpsOrNull(?string $url): ?string
    {
        return null !== $url && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Construit une ligne du rapport.
     *
     * @return array{area: string, item: string, action: string, detail: string}
     */
    private function change(string $area, string $item, string $action, string $detail): array
    {
        return ['area' => $area, 'item' => $item, 'action' => $action, 'detail' => $detail];
    }
}
