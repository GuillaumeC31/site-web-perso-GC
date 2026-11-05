<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Question d'un recruteur connecté à Guillaume (formulaire privé de l'espace recruteur) : un seul champ, le
 * recruteur étant déjà identifié (nom, entreprise, e-mail de sa demande).
 *
 * Liens :
 * - Utilisé par : RecruiterQuestionController::ask()
 *
 * @extends AbstractType<array{message: string}>
 */
final class RecruiterQuestionType extends AbstractType
{
    /**
     * Déclare le champ du message.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('message', TextareaType::class, [
            'label' => 'recruiter_area.question.field',
            'help' => 'recruiter_area.question.help',
            'attr' => ['rows' => 6, 'maxlength' => 5000],
            'constraints' => [new NotBlank(), new Length(min: 10, max: 5000)],
        ]);
    }
}
