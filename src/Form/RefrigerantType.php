<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Refrigerant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des fluides frigorigènes de référence de la calculatrice.
 *
 * Liens :
 * - Utilisé par : Admin\RefrigerantController (via AbstractCrudController)
 * - Entité : Refrigerant
 *
 * @extends AbstractType<Refrigerant>
 */
final class RefrigerantType extends AbstractType
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
            ->add('code', TextType::class, ['label' => 'admin.refrigerant.field.code', 'help' => 'admin.refrigerant.help.code'])
            ->add('name', TextType::class, ['label' => 'admin.refrigerant.field.name'])
            ->add('family', TextType::class, ['label' => 'admin.refrigerant.field.family', 'help' => 'admin.refrigerant.help.family'])
            ->add('gwp', IntegerType::class, ['label' => 'admin.refrigerant.field.gwp'])
            ->add('gwpReport', ChoiceType::class, ['label' => 'admin.refrigerant.field.gwp_report', 'choices' => ['AR4' => 'AR4', 'AR5' => 'AR5'], 'help' => 'admin.refrigerant.help.gwp_report'])
            ->add('status', TextType::class, ['label' => 'admin.refrigerant.field.status', 'help' => 'admin.refrigerant.help.status'])
            ->add('safetyClass', TextType::class, ['label' => 'admin.refrigerant.field.safety_class', 'help' => 'admin.refrigerant.help.safety_class'])
            ->add('lfl', NumberType::class, ['label' => 'admin.refrigerant.field.lfl', 'help' => 'admin.refrigerant.help.lfl', 'required' => false, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('glide', NumberType::class, ['label' => 'admin.refrigerant.field.glide', 'required' => false, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('oil', TextType::class, ['label' => 'admin.refrigerant.field.oil', 'required' => false, 'empty_data' => ''])
            ->add('replacement', TextType::class, ['label' => 'admin.refrigerant.field.replacement', 'required' => false, 'empty_data' => ''])
            ->add('application', TextType::class, ['label' => 'admin.refrigerant.field.application', 'required' => false, 'empty_data' => ''])
            ->add('defaultChoice', CheckboxType::class, ['label' => 'admin.refrigerant.field.default_choice', 'help' => 'admin.refrigerant.help.default_choice', 'required' => false])
            ->add('verified', CheckboxType::class, ['label' => 'admin.refrigerant.field.verified', 'help' => 'admin.refrigerant.help.verified', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité Refrigerant.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Refrigerant::class]);
    }
}
