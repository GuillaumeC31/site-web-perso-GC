<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AbstractCrudController;
use App\Entity\PanelModel;
use App\Entity\Skill;
use App\Entity\SkillPillar;
use App\Entity\SortableInterface;
use App\Tests\Support\AdminTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Ouvre CHAQUE liste et CHAQUE formulaire (création et modification) du CRUD de l'admin, avec au moins une ligne
 * en base : chaque page doit répondre 200.
 *
 * Origine : bug de la v2.0.0 (listes des fluides frigorigènes et des profils climatiques en erreur 500, « Object
 * of class … could not be converted to string » dans templates/admin/crud/index.html.twig, confirmation de
 * suppression). Un contrôleur CRUD ajouté plus tard est découvert automatiquement (src/Controller/Admin).
 *
 * Liens :
 * - Couvre : Controller\Admin\AbstractCrudController et ses classes filles, templates/admin/crud/*
 * - Utilise : tests/Support/AdminTestTrait (connexion réelle : mot de passe + marqueur « clé vérifiée »)
 */
final class AdminCrudPagesTest extends WebTestCase
{
    use AdminTestTrait;

    /**
     * Liste, formulaire de création et formulaire de modification répondent 200 pour un contrôleur CRUD.
     *
     * @param class-string<AbstractCrudController<SortableInterface>> $controllerClass contrôleur testé
     */
    #[DataProvider('provideCrudControllers')]
    public function testListAndFormsAreDisplayed(string $controllerClass): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $controller = self::getContainer()->get($controllerClass);
        $entity = $this->call($controller, 'createEntity');
        self::assertInstanceOf(SortableInterface::class, $entity);
        $this->completeRequiredRelations($entity);
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();

        self::assertInstanceOf(\Stringable::class, $entity, $entity::class.' doit avoir un __toString() (confirmation de suppression).');

        $prefix = (string) $this->call($controller, 'getRoutePrefix');
        $router = self::getContainer()->get(RouterInterface::class);
        $urls = [
            'liste' => $router->generate($prefix.'_index'),
            'création' => $router->generate($prefix.'_new'),
            'modification' => $router->generate($prefix.'_edit', ['id' => $entity->getId()]),
        ];

        foreach ($urls as $page => $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(200, $controllerClass.' : page « '.$page.' » ('.$url.')');
        }
    }

    /**
     * Fournit tous les contrôleurs de l'admin qui étendent AbstractCrudController.
     *
     * @return iterable<string, array{class-string<AbstractCrudController<SortableInterface>>}>
     */
    public static function provideCrudControllers(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3).'/src/Controller/Admin/*Controller.php') ?: [] as $file) {
            $class = 'App\\Controller\\Admin\\'.basename($file, '.php');
            if (is_subclass_of($class, AbstractCrudController::class) && !(new \ReflectionClass($class))->isAbstract()) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    /**
     * Complète une entité neuve pour qu'elle puisse être enregistrée : relations obligatoires (une compétence
     * appartient à un pilier) et colonnes NOT NULL encore vides (dates, années), d'après les métadonnées Doctrine.
     *
     * @param SortableInterface $entity entité créée par le contrôleur
     */
    private function completeRequiredRelations(SortableInterface $entity): void
    {
        if ($entity instanceof Skill && null === $entity->getPillar()) {
            $pillar = new SkillPillar();
            $this->getEntityManager()->persist($pillar);
            $entity->setPillar($pillar);
        }
        // Un panneau exige U ou λ (PanelModel::validateThermalValue()) : sans eux, le U calculé n'existe pas
        if ($entity instanceof PanelModel) {
            $entity->setThickness(100)->setLambda(0.022);
        }

        $metadata = $this->getEntityManager()->getClassMetadata($entity::class);
        foreach ($metadata->getFieldNames() as $field) {
            $mapping = $metadata->getFieldMapping($field);
            if (($mapping->nullable ?? false) || ($mapping->id ?? false) || null !== $metadata->getFieldValue($entity, $field)) {
                continue;
            }
            $value = match ($mapping->type) {
                'date_immutable', 'datetime_immutable' => new \DateTimeImmutable('2020-01-01'),
                'date', 'datetime' => new \DateTime('2020-01-01'),
                'integer', 'smallint' => 2020,
                'float', 'decimal' => 1.0,
                'boolean' => false,
                default => 'Test',
            };
            $metadata->setFieldValue($entity, $field, $value);
        }
    }

    /**
     * Appelle une méthode protégée du contrôleur (créer l'entité, préfixe des routes).
     *
     * @param object $controller contrôleur CRUD
     * @param string $method     nom de la méthode
     */
    private function call(object $controller, string $method): mixed
    {
        return (new \ReflectionMethod($controller, $method))->invoke($controller);
    }
}
