<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ClimaticProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des profils de consigne de l'enceinte climatique.
 *
 * Liens :
 * - Utilisé par : Admin\ClimaticProfileController (via AbstractCrudController)
 * - Entité : ClimaticProfile
 *
 * @extends AbstractType<ClimaticProfile>
 */
final class ClimaticProfileType extends AbstractType
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
        $number = ['html5' => true, 'attr' => ['step' => 'any']];
        $builder
            ->add('code', TextType::class, ['label' => 'admin.climatic_profile.field.code', 'help' => 'admin.climatic_profile.help.code'])
            ->add('sector', TextType::class, ['label' => 'admin.climatic_profile.field.sector', 'help' => 'admin.climatic_profile.help.sector'])
            ->add('name', TextType::class, ['label' => 'admin.climatic_profile.field.name'])
            ->add('temperature', NumberType::class, ['label' => 'admin.climatic_profile.field.temperature'] + $number)
            ->add('temperatureTolerance', NumberType::class, ['label' => 'admin.climatic_profile.field.temperature_tolerance'] + $number)
            ->add('humidity', NumberType::class, ['label' => 'admin.climatic_profile.field.humidity', 'help' => 'admin.climatic_profile.help.humidity', 'required' => false] + $number)
            ->add('humidityTolerance', NumberType::class, ['label' => 'admin.climatic_profile.field.humidity_tolerance', 'required' => false] + $number)
            ->add('source', TextType::class, ['label' => 'admin.climatic_profile.field.source', 'help' => 'admin.climatic_profile.help.source'])
            ->add('defaultChoice', CheckboxType::class, ['label' => 'admin.climatic_profile.field.default_choice', 'help' => 'admin.climatic_profile.help.default_choice', 'required' => false])
            ->add('verified', CheckboxType::class, ['label' => 'admin.climatic_profile.field.verified', 'help' => 'admin.climatic_profile.help.verified', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité ClimaticProfile.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClimaticProfile::class]);
    }
}
