<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SiteSettings;
use App\Service\Mail\MailRecipients;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Communication › E-mails : trois adresses de réception (Recrutement, Général du site, Calculatrice à venir), nom
 * d'expéditeur affiché, sélecteur DKIM vérifié par la délivrabilité, plafond quotidien des e-mails aux personnes non validées. Les secrets (mot de passe SMTP…) restent dans
 * .env.local : jamais dans ce formulaire.
 *
 * Option reject_fictitious (vraie en production) : refuse les adresses fictives (example.test, example.com,
 * localhost…, MailRecipients::isFictitious()).
 *
 * Liens :
 * - Utilisé par : Admin\CommunicationController::emails()
 * - Entité : SiteSettings (recruitmentEmail, generalEmail, calculatorEmail, mailFromName, dkimSelector,
 *   unvalidatedEmailDailyCap)
 *
 * @extends AbstractType<SiteSettings>
 */
final class CommunicationAddressesType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire (reject_fictitious)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fictitious = $options['reject_fictitious'] ? [new Assert\Callback(static function (?string $value, ExecutionContextInterface $context): void {
            if (null !== $value && '' !== trim($value) && MailRecipients::isFictitious($value)) {
                $context->buildViolation('admin.communication.address_fictitious')->addViolation();
            }
        })] : [];
        foreach (['recruitmentEmail' => 'recruitment', 'generalEmail' => 'general', 'calculatorEmail' => 'calculator'] as $field => $key) {
            $builder->add($field, EmailType::class, [
                'label' => 'admin.communication.field.'.$key.'_email', 'help' => 'admin.communication.help.'.$key.'_email', 'required' => false,
                'attr' => ['maxlength' => 180, 'autocomplete' => 'email'], 'constraints' => $fictitious,
            ]);
        }
        $builder
            ->add('mailFromName', TextType::class, ['label' => 'admin.communication.field.from_name', 'help' => 'admin.communication.help.from_name', 'required' => false, 'attr' => ['maxlength' => 100]])
            ->add('dkimSelector', TextType::class, ['label' => 'admin.communication.field.dkim_selector', 'help' => 'admin.communication.help.dkim_selector', 'attr' => ['maxlength' => 63, 'spellcheck' => 'false', 'autocomplete' => 'off']])
            ->add('unvalidatedEmailDailyCap', IntegerType::class, ['label' => 'admin.communication.field.unvalidated_cap', 'help' => 'admin.communication.help.unvalidated_cap', 'attr' => ['min' => 1, 'max' => 500, 'inputmode' => 'numeric']]);
    }

    /**
     * Associe le formulaire à l'entité SiteSettings ; reject_fictitious vaut faux par défaut.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SiteSettings::class, 'reject_fictitious' => false]);
        $resolver->setAllowedTypes('reject_fictitious', 'bool');
    }
}
