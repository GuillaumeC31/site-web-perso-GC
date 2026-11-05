<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Language;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une langue (nom et maîtrise en texte libre).
 *
 * Liens :
 * - Utilisé par : Admin\LanguageController (via AbstractCrudController)
 * - Entité : Language
 *
 * @extends AbstractType<Language>
 */
final class LanguageType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'admin.language.field.name'])
            ->add('proficiency', TextType::class, ['label' => 'admin.language.field.proficiency', 'help' => 'admin.language.help.proficiency']);
    }

    /**
     * Associe le formulaire à l'entité Language.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Language::class]);
    }
}
