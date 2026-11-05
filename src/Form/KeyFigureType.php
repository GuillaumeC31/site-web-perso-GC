<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\KeyFigure;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'un chiffre clé de l'accueil.
 *
 * Liens :
 * - Utilisé par : Admin\KeyFigureController (via AbstractCrudController)
 * - Entité : KeyFigure
 *
 * @extends AbstractType<KeyFigure>
 */
final class KeyFigureType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('value', TextType::class, ['label' => 'admin.key_figure.field.value', 'help' => 'admin.key_figure.help.value'])
            ->add('label', TextType::class, ['label' => 'admin.key_figure.field.label']);
    }

    /**
     * Associe le formulaire à l'entité KeyFigure.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => KeyFigure::class]);
    }
}
