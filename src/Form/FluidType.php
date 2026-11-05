<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Fluid;
use App\Enum\FluidState;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des fluides de référence de la calculatrice.
 *
 * Liens :
 * - Utilisé par : Admin\FluidController (via AbstractCrudController)
 * - Entité : Fluid
 *
 * @extends AbstractType<Fluid>
 */
final class FluidType extends AbstractType
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
            ->add('name', TextType::class, ['label' => 'admin.fluid.field.name'])
            ->add('state', EnumType::class, [
                'label' => 'admin.fluid.field.state',
                'class' => FluidState::class,
                'choice_label' => static fn (FluidState $state): string => $state->translationKey(),
            ])
            ->add('density', NumberType::class, ['label' => 'admin.fluid.field.density', 'help' => 'admin.fluid.help.density', 'scale' => 3, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('specificHeatCapacity', NumberType::class, ['label' => 'admin.fluid.field.specific_heat_capacity', 'help' => 'admin.fluid.help.specific_heat_capacity', 'html5' => true, 'attr' => ['step' => 'any']]);
    }

    /**
     * Associe le formulaire à l'entité Fluid.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Fluid::class]);
    }
}
