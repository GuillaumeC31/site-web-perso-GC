<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProjectImage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Ligne de la galerie dans le formulaire d'un projet : texte alternatif et case « retirer ».
 *
 * Le fichier lui-même ne se modifie pas ici : on retire l'image et on en envoie une autre.
 *
 * Liens :
 * - Utilisé par : ProjectType (collection « images »)
 * - Traité par : Admin\ProjectController::beforeSave() (case « retirer »)
 *
 * @extends AbstractType<ProjectImage>
 */
final class ProjectImageType extends AbstractType
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
            ->add('alt', TextType::class, ['label' => 'admin.project.field.image_alt', 'required' => false])
            ->add('remove', CheckboxType::class, ['label' => 'admin.project.field.image_remove', 'mapped' => false, 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité ProjectImage.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProjectImage::class]);
    }
}
