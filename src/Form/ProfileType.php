<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Profile;
use App\Service\Admin\ProfilePhotoStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Formulaire du profil : informations publiques, coordonnées privées et photo.
 *
 * La photo n'est pas un champ de l'entité (mapped: false) : le fichier est contrôlé
 * (type réel, taille, dimensions) puis enregistré sous un nom aléatoire par
 * ProfilePhotoStorage.
 *
 * Liens :
 * - Utilisé par : Admin\ProfileController::edit()
 * - Entité : Profile
 *
 * @extends AbstractType<Profile>
 */
final class ProfileType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $optional = ['required' => false];

        $builder
            ->add('fullName', TextType::class, ['label' => 'admin.profile.field.full_name'])
            ->add('jobTitle', TextType::class, ['label' => 'admin.profile.field.job_title', 'help' => 'admin.profile.help.job_title'])
            ->add('tagline', TextType::class, ['label' => 'admin.profile.field.tagline'] + $optional)
            ->add('pitch', TextareaType::class, ['label' => 'admin.profile.field.pitch', 'help' => 'admin.profile.help.pitch', 'attr' => ['rows' => 3]] + $optional)
            ->add('summary', TextareaType::class, ['label' => 'admin.profile.field.summary', 'help' => 'admin.profile.help.summary', 'attr' => ['rows' => 6]] + $optional)
            ->add('searchStatus', TextType::class, ['label' => 'admin.profile.field.search_status'] + $optional)
            ->add('location', TextType::class, ['label' => 'admin.profile.field.location'] + $optional)
            ->add('mobility', TextType::class, ['label' => 'admin.profile.field.mobility'] + $optional)
            ->add('workModes', TextType::class, ['label' => 'admin.profile.field.work_modes'] + $optional)
            ->add('targetRoles', TextType::class, ['label' => 'admin.profile.field.target_roles'] + $optional)
            ->add('targetCompanies', TextType::class, ['label' => 'admin.profile.field.target_companies'] + $optional)
            ->add('contractTypes', TextType::class, ['label' => 'admin.profile.field.contract_types'] + $optional)
            ->add('availability', TextType::class, ['label' => 'admin.profile.field.availability'] + $optional)
            ->add('privatePhone', TelType::class, ['label' => 'admin.profile.field.private_phone'] + $optional)
            ->add('privateEmail', EmailType::class, ['label' => 'admin.profile.field.private_email'] + $optional)
            ->add('privatePostalAddress', TextareaType::class, ['label' => 'admin.profile.field.private_postal_address', 'attr' => ['rows' => 3]] + $optional)
            ->add('privateSalaryExpectation', TextType::class, ['label' => 'admin.profile.field.private_salary_expectation', 'help' => 'admin.profile.help.private_salary_expectation'] + $optional)
            ->add('showSalaryInRecruiterArea', CheckboxType::class, ['label' => 'admin.profile.field.show_salary_in_recruiter_area', 'required' => false])
            // Rubriques du CV public (correction de recette n° 2) : décochées = espace recruteur seulement
            ->add('languagesPublic', CheckboxType::class, ['label' => 'admin.profile.field.languages_public', 'help' => 'admin.profile.help.public_rubric', 'required' => false])
            ->add('educationsPublic', CheckboxType::class, ['label' => 'admin.profile.field.educations_public', 'help' => 'admin.profile.help.public_rubric', 'required' => false])
            ->add('photo', FileType::class, [
                'label' => 'admin.profile.field.photo',
                'help' => 'admin.profile.help.photo',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    // Assez grande pour produire la photo 800 × 1000 sans agrandissement (ProfilePhotoStorage)
                    new Image(
                        maxSize: '10M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        minWidth: ProfilePhotoStorage::PHOTO_WIDTH,
                        minHeight: ProfilePhotoStorage::PHOTO_HEIGHT,
                        maxWidth: 6000,
                        maxHeight: 6000,
                    ),
                ],
            ])
            ->add('removePhoto', CheckboxType::class, ['label' => 'admin.profile.field.remove_photo', 'mapped' => false, 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité Profile.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Profile::class]);
    }
}
