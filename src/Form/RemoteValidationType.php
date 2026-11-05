<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SiteSettings;
use App\Service\Recruiter\RemoteDecisionExecutor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Communication › Validation à distance : interrupteur et code PIN (saisi deux fois, jamais réaffiché, haché par
 * Admin\CommunicationController ; vide : code inchangé). Déplacé de Paramètres le 2026-10-29.
 *
 * Liens :
 * - Utilisé par : Admin\CommunicationController::remote()
 * - Entité : SiteSettings (remoteValidationEnabled ; remotePinHash écrit par le contrôleur)
 *
 * @extends AbstractType<SiteSettings>
 */
final class RemoteValidationType extends AbstractType
{
    /**
     * Déclare l'interrupteur et le code PIN.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('remoteValidationEnabled', CheckboxType::class, ['label' => 'admin.site_settings.field.remote_validation', 'help' => 'admin.site_settings.help.remote_validation', 'required' => false])
            ->add('remotePin', RepeatedType::class, [
                'type' => PasswordType::class, 'mapped' => false, 'required' => false,
                'invalid_message' => 'admin.communication.pin_mismatch',
                'first_options' => ['label' => 'admin.site_settings.field.remote_pin', 'help' => 'admin.site_settings.help.remote_pin', 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'new-password', 'maxlength' => 12]],
                'second_options' => ['label' => 'admin.communication.field.remote_pin_repeat', 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'new-password', 'maxlength' => 12]],
                'constraints' => [new Assert\Regex(pattern: RemoteDecisionExecutor::PIN_PATTERN, message: 'admin.site_settings.remote_pin_invalid')],
            ]);
    }

    /**
     * Associe le formulaire à l'entité SiteSettings.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SiteSettings::class]);
    }
}
