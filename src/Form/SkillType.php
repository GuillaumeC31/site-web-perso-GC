<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Skill;
use App\Entity\SkillPillar;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une compétence (intitulé et pilier, sans niveau).
 *
 * Liens :
 * - Utilisé par : Admin\SkillController (via AbstractCrudController)
 * - Entité : Skill
 *
 * @extends AbstractType<Skill>
 */
final class SkillType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'admin.skill.field.name'])
            ->add('pillar', EntityType::class, [
                'label' => 'admin.skill.field.pillar',
                'class' => SkillPillar::class,
                'placeholder' => 'admin.skill.field.pillar_placeholder',
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('p')->orderBy('p.position', 'ASC'),
            ]);
    }

    /**
     * Associe le formulaire à l'entité Skill.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Skill::class]);
    }
}
