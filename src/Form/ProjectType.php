<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Entity\ProjectCategory;
use App\Enum\ProjectType as ProjectKind;
use App\Enum\ProjectVisibility;
use App\Service\Admin\ProjectImageStorage;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Formulaire d'un projet : informations, plan de l'étude de cas, contenu Markdown,
 * couverture, galerie, publication.
 *
 * Les fichiers (couverture, nouvelles images) ne sont pas des champs de l'entité
 * (mapped: false) : ils sont contrôlés ici (contrainte Image), puis convertis en WebP
 * par ProjectImageStorage dans Admin\ProjectController::beforeSave().
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController (via AbstractCrudController)
 * - Entité : Project ; sous-formulaire : ProjectImageType
 * - Rendu par : templates/admin/project/form.html.twig
 *
 * @extends AbstractType<Project>
 */
final class ProjectType extends AbstractType
{
    /** Types d'images acceptés à l'envoi. */
    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Nombre maximal d'images envoyées en une fois. */
    private const MAX_IMAGES_PER_UPLOAD = 5;

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
        $optional = ['required' => false];
        $text = static fn (int $rows): array => ['required' => false, 'attr' => ['rows' => $rows]];

        $builder
            // Informations
            ->add('type', EnumType::class, [
                'label' => 'admin.project.field.type',
                'class' => ProjectKind::class,
                'choice_label' => static fn (ProjectKind $kind): string => $kind->translationKey(),
                'expanded' => true,
            ])
            ->add('title', TextType::class, ['label' => 'admin.project.field.title'])
            ->add('slug', TextType::class, ['label' => 'admin.project.field.slug', 'help' => 'admin.project.help.slug', 'required' => false, 'empty_data' => ''])
            ->add('summary', TextareaType::class, ['label' => 'admin.project.field.summary', 'help' => 'admin.project.help.summary'] + $text(3))
            ->add('clientLabel', TextType::class, ['label' => 'admin.project.field.client_label', 'help' => 'admin.project.help.client_label'] + $optional)
            ->add('projectDate', DateType::class, ['label' => 'admin.project.field.project_date', 'widget' => 'single_text', 'input' => 'datetime_immutable'] + $optional)
            ->add('categories', EntityType::class, [
                'label' => 'admin.project.field.categories',
                'class' => ProjectCategory::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')->orderBy('c.position', 'ASC'),
            ])
            ->add('technologies', TextareaType::class, ['label' => 'admin.project.field.technologies', 'help' => 'admin.form.one_per_line'] + $text(4))
            // Plan imposé des études de cas
            ->add('context', TextareaType::class, ['label' => 'project.section.context'] + $text(4))
            ->add('problem', TextareaType::class, ['label' => 'project.section.problem'] + $text(4))
            ->add('solution', TextareaType::class, ['label' => 'project.section.solution'] + $text(4))
            ->add('result', TextareaType::class, ['label' => 'project.section.result', 'help' => 'admin.project.help.result'] + $text(3))
            ->add('competences', TextareaType::class, ['label' => 'project.section.competences', 'help' => 'admin.form.one_per_line'] + $text(4))
            // Contenu riche (Markdown)
            ->add('content', TextareaType::class, ['label' => 'admin.project.field.content', 'help' => 'admin.project.help.content'] + $text(14))
            // Liens (Labo)
            ->add('repositoryUrl', UrlType::class, ['label' => 'admin.project.field.repository_url', 'default_protocol' => 'https'] + $optional)
            ->add('demoUrl', UrlType::class, ['label' => 'admin.project.field.demo_url', 'default_protocol' => 'https'] + $optional)
            // Couverture
            ->add('coverFile', FileType::class, [
                'label' => 'admin.project.field.cover',
                'help' => 'admin.project.help.cover',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => implode(',', self::IMAGE_MIME_TYPES)],
                'constraints' => [
                    new Image(
                        maxSize: '10M',
                        mimeTypes: self::IMAGE_MIME_TYPES,
                        minWidth: ProjectImageStorage::COVER_WIDTH,
                        minHeight: ProjectImageStorage::COVER_HEIGHT,
                        maxWidth: 8000,
                        maxHeight: 8000,
                    ),
                ],
            ])
            ->add('coverAlt', TextType::class, ['label' => 'admin.project.field.cover_alt'] + $optional)
            ->add('removeCover', CheckboxType::class, ['label' => 'admin.project.field.remove_cover', 'mapped' => false, 'required' => false])
            // Galerie
            ->add('images', CollectionType::class, [
                'label' => false,
                'entry_type' => ProjectImageType::class,
                'entry_options' => ['label' => false],
                'allow_add' => false,
                'allow_delete' => false,
            ])
            ->add('newImages', FileType::class, [
                'label' => 'admin.project.field.new_images',
                'help' => 'admin.project.help.new_images',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => ['accept' => implode(',', self::IMAGE_MIME_TYPES)],
                'constraints' => [
                    // Limite alignée sur public/.user.ini (post_max_size 55M, max_file_uploads 6)
                    new Count(max: self::MAX_IMAGES_PER_UPLOAD, maxMessage: 'project.new_images_too_many'),
                    new All(constraints: [
                        new Image(
                            maxSize: '10M',
                            mimeTypes: self::IMAGE_MIME_TYPES,
                            minWidth: 600,
                            minHeight: 300,
                            maxWidth: 8000,
                            maxHeight: 8000,
                        ),
                    ]),
                ],
            ])
            // Publication
            ->add('isPublished', CheckboxType::class, ['label' => 'admin.project.field.is_published', 'help' => 'admin.project.help.is_published'] + $optional)
            ->add('visibility', EnumType::class, [
                'label' => 'admin.project.field.visibility',
                'class' => ProjectVisibility::class,
                'choice_label' => static fn (ProjectVisibility $visibility): string => $visibility->translationKey(),
                'expanded' => true,
            ])
            ->add('isFeatured', CheckboxType::class, ['label' => 'admin.project.field.is_featured', 'help' => 'admin.project.help.is_featured'] + $optional);
    }

    /**
     * Associe le formulaire à l'entité Project.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Project::class]);
    }
}
