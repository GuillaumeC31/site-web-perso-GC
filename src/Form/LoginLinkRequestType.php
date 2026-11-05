<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demande d'un lien de connexion à l'espace recruteur (recruteur déjà validé) : e-mail et champ piège.
 *
 * Liens :
 * - Utilisé par : RecruiterAreaController::index(), RecruiterAreaController::linkInvalid()
 *
 * @extends AbstractType<array{email: string}>
 */
final class LoginLinkRequestType extends AbstractType
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
            ->add('email', EmailType::class, [
                'label' => 'recruiter_area.field.email_validated', 'attr' => ['autocomplete' => 'email'],
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('website', TextType::class, HoneypotOptions::OPTIONS);
    }
}
