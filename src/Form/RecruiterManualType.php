<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Recruiter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Création manuelle d'un accès recruteur dans l'admin (validé tout de suite, e-mail de connexion envoyé).
 *
 * Liens :
 * - Utilisé par : Admin\RecruiterController::new()
 * - Entité : Recruiter (unicité de l'e-mail contrôlée : groupe « admin »)
 *
 * @extends AbstractType<Recruiter>
 */
final class RecruiterManualType extends AbstractType
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
            ->add('email', EmailType::class, ['label' => 'admin.recruiter.field.email'])
            ->add('fullName', TextType::class, ['label' => 'admin.recruiter.field.full_name'])
            ->add('company', TextType::class, ['label' => 'admin.recruiter.field.company']);
    }

    /**
     * Associe le formulaire à l'entité Recruiter, avec le contrôle d'unicité de l'e-mail.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recruiter::class, 'validation_groups' => ['Default', 'admin']]);
    }
}
