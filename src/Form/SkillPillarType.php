<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SkillPillar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'un pilier de compétences (nom, résumé, étiquette, icône ; l'ordre se règle depuis la liste).
 *
 * Liens :
 * - Utilisé par : Admin\SkillPillarController (via AbstractCrudController)
 * - Entité : SkillPillar
 *
 * @extends AbstractType<SkillPillar>
 */
final class SkillPillarType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'admin.skill_pillar.field.name'])
            ->add('summary', TextareaType::class, ['label' => 'admin.skill_pillar.field.summary', 'help' => 'admin.skill_pillar.help.summary', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('badge', TextType::class, ['label' => 'admin.skill_pillar.field.badge', 'help' => 'admin.skill_pillar.help.badge', 'required' => false])
            ->add('icon', ChoiceType::class, [
                'label' => 'admin.skill_pillar.field.icon',
                'choices' => array_combine(
                    array_map(static fn (string $icon): string => 'admin.skill_pillar.icon.'.$icon, SkillPillar::ICONS),
                    SkillPillar::ICONS,
                ),
            ]);
    }

    /**
     * Associe le formulaire à l'entité SkillPillar.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SkillPillar::class]);
    }
}
