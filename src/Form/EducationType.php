<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Education;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une formation ou d'un diplôme.
 *
 * Liens :
 * - Utilisé par : Admin\EducationController (via AbstractCrudController)
 * - Entité : Education
 *
 * @extends AbstractType<Education>
 */
final class EducationType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('year', IntegerType::class, ['label' => 'admin.education.field.year', 'attr' => ['min' => 1950, 'max' => 2100]])
            ->add('title', TextType::class, ['label' => 'admin.education.field.title'])
            ->add('institution', TextType::class, ['label' => 'admin.education.field.institution', 'required' => false])
            ->add('location', TextType::class, ['label' => 'admin.education.field.location', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité Education.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Education::class]);
    }
}
