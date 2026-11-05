<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CalculatorToolSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des réglages d'un outil de la calculatrice : activation, texte d'aide,
 * norme citée et sa validation, ordre d'affichage.
 *
 * L'activation n'est proposée que pour un outil porté (option can_be_enabled).
 *
 * Liens :
 * - Utilisé par : Admin\CalculatorToolController::edit()
 * - Entité : CalculatorToolSetting
 *
 * @extends AbstractType<CalculatorToolSetting>
 */
final class CalculatorToolSettingType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire (can_be_enabled, default_reference)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (true === $options['can_be_enabled']) {
            $builder->add('enabled', CheckboxType::class, ['label' => 'admin.calculator_tool.field.enabled', 'help' => 'admin.calculator_tool.help.enabled', 'required' => false]);
        }

        $builder
            ->add('reference', TextType::class, [
                'label' => 'admin.calculator_tool.field.reference',
                'help' => 'admin.calculator_tool.help.reference',
                'required' => false,
                'attr' => ['placeholder' => $options['default_reference']],
            ])
            ->add('referenceVerified', CheckboxType::class, ['label' => 'admin.calculator_tool.field.reference_verified', 'help' => 'admin.calculator_tool.help.reference_verified', 'required' => false])
            ->add('helpText', TextareaType::class, ['label' => 'admin.calculator_tool.field.help_text', 'help' => 'admin.calculator_tool.help.help_text', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('position', IntegerType::class, ['label' => 'admin.calculator_tool.field.position', 'help' => 'admin.calculator_tool.help.position', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité et déclare ses options.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CalculatorToolSetting::class,
            'can_be_enabled' => false,
            'default_reference' => null,
        ]);
        $resolver->setAllowedTypes('can_be_enabled', 'bool');
        $resolver->setAllowedTypes('default_reference', ['null', 'string']);
    }
}
