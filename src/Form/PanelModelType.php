<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PanelModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des modèles de panneaux isolants des marques (chambre froide).
 *
 * Liens :
 * - Utilisé par : Admin\PanelModelController (via AbstractCrudController)
 * - Entité : PanelModel
 *
 * @extends AbstractType<PanelModel>
 */
final class PanelModelType extends AbstractType
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
            ->add('brand', TextType::class, ['label' => 'admin.panel_model.field.brand'])
            ->add('productRange', TextType::class, ['label' => 'admin.panel_model.field.product_range'])
            ->add('insulation', TextType::class, ['label' => 'admin.panel_model.field.insulation', 'help' => 'admin.panel_model.help.insulation'])
            ->add('thickness', IntegerType::class, ['label' => 'admin.panel_model.field.thickness'])
            ->add('uValue', NumberType::class, ['label' => 'admin.panel_model.field.u_value', 'help' => 'admin.panel_model.help.u_value', 'required' => false, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('lambda', NumberType::class, ['label' => 'admin.panel_model.field.lambda', 'help' => 'admin.panel_model.help.lambda', 'required' => false, 'html5' => true, 'attr' => ['step' => 'any']])
            ->add('sourceDocument', TextType::class, ['label' => 'admin.panel_model.field.source_document', 'help' => 'admin.panel_model.help.source_document'])
            ->add('sourceDate', DateType::class, ['label' => 'admin.panel_model.field.source_date', 'widget' => 'single_text', 'input' => 'datetime_immutable']);
    }

    /**
     * Associe le formulaire à l'entité PanelModel.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PanelModel::class]);
    }
}
