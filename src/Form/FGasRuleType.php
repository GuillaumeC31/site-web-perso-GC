<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FGasRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des règles F-Gas (interdictions de mise sur le marché) de la chambre froide.
 *
 * Liens :
 * - Utilisé par : Admin\FGasRuleController (via AbstractCrudController)
 * - Entité : FGasRule
 *
 * @extends AbstractType<FGasRule>
 */
final class FGasRuleType extends AbstractType
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
            ->add('code', TextType::class, ['label' => 'admin.fgas_rule.field.code'])
            ->add('kind', ChoiceType::class, [
                'label' => 'admin.fgas_rule.field.kind',
                'choices' => array_combine(array_map(static fn (string $kind): string => 'admin.fgas_rule.kind.'.$kind, FGasRule::KINDS), FGasRule::KINDS),
            ])
            ->add('equipment', TextType::class, ['label' => 'admin.fgas_rule.field.equipment'])
            ->add('installationTypes', ChoiceType::class, [
                'label' => 'admin.fgas_rule.field.installation_types',
                'choices' => array_combine(array_map(static fn (string $type): string => 'admin.fgas_rule.type.'.$type, FGasRule::INSTALLATION_TYPES), FGasRule::INSTALLATION_TYPES),
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('minCapacityKw', NumberType::class, ['label' => 'admin.fgas_rule.field.min_capacity_kw', 'help' => 'admin.fgas_rule.help.min_capacity_kw', 'required' => false, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('minChargeTonnes', NumberType::class, ['label' => 'admin.fgas_rule.field.min_charge_tonnes', 'help' => 'admin.fgas_rule.help.min_charge_tonnes', 'required' => false, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('gwpThreshold', IntegerType::class, ['label' => 'admin.fgas_rule.field.gwp_threshold', 'help' => 'admin.fgas_rule.help.gwp_threshold'])
            ->add('since', DateType::class, ['label' => 'admin.fgas_rule.field.since', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('exception', TextareaType::class, ['label' => 'admin.fgas_rule.field.exception', 'required' => false])
            ->add('article', TextType::class, ['label' => 'admin.fgas_rule.field.article'])
            ->add('annexPoint', TextType::class, ['label' => 'admin.fgas_rule.field.annex_point', 'required' => false])
            ->add('source', TextareaType::class, ['label' => 'admin.fgas_rule.field.source'])
            ->add('verified', CheckboxType::class, ['label' => 'admin.fgas_rule.field.verified', 'help' => 'admin.fgas_rule.help.verified', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité FGasRule.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FGasRule::class]);
    }
}
