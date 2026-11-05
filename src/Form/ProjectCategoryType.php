<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProjectCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une catégorie de projet.
 *
 * Liens :
 * - Utilisé par : Admin\ProjectCategoryController (via AbstractCrudController)
 * - Entité : ProjectCategory
 *
 * @extends AbstractType<ProjectCategory>
 */
final class ProjectCategoryType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'admin.project_category.field.name'])
            ->add('slug', TextType::class, ['label' => 'admin.project.field.slug', 'help' => 'admin.project.help.slug', 'required' => false, 'empty_data' => '']);
    }

    /**
     * Associe le formulaire à l'entité ProjectCategory.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProjectCategory::class]);
    }
}
