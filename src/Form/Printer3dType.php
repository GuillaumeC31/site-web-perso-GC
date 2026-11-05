<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Printer3d;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire des imprimantes 3D de référence de la calculatrice.
 *
 * Liens :
 * - Utilisé par : Admin\Printer3dController (via AbstractCrudController)
 * - Entité : Printer3d
 *
 * @extends AbstractType<Printer3d>
 */
final class Printer3dType extends AbstractType
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
            ->add('brand', TextType::class, ['label' => 'admin.printer3d.field.brand'])
            ->add('model', TextType::class, ['label' => 'admin.printer3d.field.model'])
            ->add('powerConsumption', IntegerType::class, ['label' => 'admin.printer3d.field.power_consumption', 'help' => 'admin.printer3d.help.power_consumption']);
    }

    /**
     * Associe le formulaire à l'entité Printer3d.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Printer3d::class]);
    }
}
