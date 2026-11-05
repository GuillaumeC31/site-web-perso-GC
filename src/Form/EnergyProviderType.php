<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EnergyProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des offres d'électricité de référence de la calculatrice.
 *
 * Liens :
 * - Utilisé par : Admin\EnergyProviderController (via AbstractCrudController)
 * - Entité : EnergyProvider
 *
 * @extends AbstractType<EnergyProvider>
 */
final class EnergyProviderType extends AbstractType
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
            ->add('name', TextType::class, ['label' => 'admin.energy_provider.field.name'])
            ->add('planName', TextType::class, ['label' => 'admin.energy_provider.field.plan_name'])
            ->add('priceKwh', NumberType::class, ['label' => 'admin.energy_provider.field.price_kwh', 'help' => 'admin.energy_provider.help.price_kwh', 'scale' => 4, 'html5' => true, 'attr' => ['step' => 'any']]);
    }

    /**
     * Associe le formulaire à l'entité EnergyProvider.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EnergyProvider::class]);
    }
}
