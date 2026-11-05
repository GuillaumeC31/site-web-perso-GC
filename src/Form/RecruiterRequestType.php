<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Recruiter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Demande d'accès à l'espace recruteur (CDC §7, 4 champs au maximum) : e-mail professionnel, nom,
 * entreprise, message facultatif ; plus le consentement et le champ piège.
 *
 * Liens :
 * - Utilisé par : RecruiterAreaController::index() (route recruiter_access, /espace-recruteur)
 * - Entité : Recruiter (données de la demande, recopiées par RecruiterAccessManager::requestAccess())
 *
 * @extends AbstractType<Recruiter>
 */
final class RecruiterRequestType extends AbstractType
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
            ->add('email', EmailType::class, ['label' => 'recruiter_area.field.email', 'attr' => ['autocomplete' => 'email']])
            ->add('fullName', TextType::class, ['label' => 'recruiter_area.field.full_name', 'attr' => ['autocomplete' => 'name']])
            ->add('company', TextType::class, ['label' => 'recruiter_area.field.company', 'attr' => ['autocomplete' => 'organization']])
            ->add('jobTitle', TextType::class, ['label' => 'recruiter_area.field.job_title', 'required' => false, 'attr' => ['autocomplete' => 'organization-title', 'maxlength' => 120]])
            // Nommé autrement que le piège à robots « website » (HoneypotOptions)
            ->add('companyWebsite', UrlType::class, ['label' => 'recruiter_area.field.company_website', 'required' => false, 'default_protocol' => 'https', 'attr' => ['autocomplete' => 'url', 'maxlength' => 255]])
            ->add('message', TextareaType::class, ['label' => 'recruiter_area.field.message', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('consent', CheckboxType::class, [
                'label' => 'recruiter_area.field.consent', 'mapped' => false, 'constraints' => [new IsTrue(message: 'contact.consent_required')],
            ])
            ->add('website', TextType::class, HoneypotOptions::OPTIONS);
    }

    /**
     * Associe le formulaire à l'entité Recruiter ; l'unicité de l'e-mail n'est pas contrôlée ici (groupe
     * « admin » seulement : la réponse doit rester la même pour une adresse connue).
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recruiter::class]);
    }
}
