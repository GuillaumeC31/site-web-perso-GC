<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SiteSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Paramètres généraux du site : délai de réponse affiché, validation automatique des demandes recruteurs et son
 * seuil de score, assistant IA (activation des questions libres, plafonds, débit, seuil d'alerte), référencement de
 * l'accueil, hébergeur (mentions légales), mode maintenance (message, date de réactivation, adresses IP
 * autorisées). Notifications, adresses e-mail et validation à distance : page Communication (2026-10-29).
 *
 * Liens :
 * - Utilisé par : Admin\SiteSettingsController::edit()
 * - Entité : SiteSettings
 * - Voir aussi : CommunicationAddressesType, CommunicationNotificationsType, RemoteValidationType
 *
 * @extends AbstractType<SiteSettings>
 */
final class SiteSettingsType extends AbstractType
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
        $builder->add('responseDelay', TextType::class, ['label' => 'admin.site_settings.field.response_delay', 'help' => 'admin.site_settings.help.response_delay']);
        $builder
            ->add('autoApprovalEnabled', CheckboxType::class, ['label' => 'admin.site_settings.field.auto_approval', 'help' => 'admin.site_settings.help.auto_approval', 'required' => false])
            ->add('autoApprovalThreshold', IntegerType::class, ['label' => 'admin.site_settings.field.auto_approval_threshold', 'attr' => ['min' => 0, 'max' => 100]])
            ->add('aiAssistantEnabled', CheckboxType::class, ['label' => 'admin.site_settings.field.ai_enabled', 'help' => 'admin.site_settings.help.ai_enabled', 'required' => false])
            ->add('aiRecruiterDailyLimit', IntegerType::class, ['label' => 'admin.site_settings.field.ai_recruiter_daily', 'attr' => ['min' => 0, 'max' => 500]])
            ->add('aiSiteDailyLimit', IntegerType::class, ['label' => 'admin.site_settings.field.ai_site_daily', 'attr' => ['min' => 0, 'max' => 10000]])
            ->add('aiMonthlyTokenLimit', IntegerType::class, ['label' => 'admin.site_settings.field.ai_monthly_tokens', 'help' => 'admin.site_settings.help.ai_monthly_tokens', 'attr' => ['min' => 0, 'step' => 100000]])
            ->add('aiKnowledgeTokenLimit', IntegerType::class, ['label' => 'admin.site_settings.field.ai_knowledge_token_limit', 'help' => 'admin.site_settings.help.ai_knowledge_token_limit', 'attr' => ['min' => 0, 'max' => 50000, 'step' => 500]])
            ->add('aiRequestsPerSecond', IntegerType::class, ['label' => 'admin.site_settings.field.ai_requests_per_second', 'help' => 'admin.site_settings.help.ai_requests_per_second', 'attr' => ['min' => 1, 'max' => 20]])
            ->add('aiAlertPercent', IntegerType::class, ['label' => 'admin.site_settings.field.ai_alert_percent', 'help' => 'admin.site_settings.help.ai_alert_percent', 'attr' => ['min' => 1, 'max' => 100]])
            ->add('seoTitle', TextType::class, ['label' => 'admin.site_settings.field.seo_title', 'help' => 'admin.site_settings.help.seo_title', 'required' => false, 'attr' => ['maxlength' => 70]])
            ->add('seoDescription', TextareaType::class, ['label' => 'admin.site_settings.field.seo_description', 'help' => 'admin.site_settings.help.seo_description', 'required' => false, 'attr' => ['maxlength' => 170, 'rows' => 3]])
            ->add('hostName', TextType::class, ['label' => 'admin.site_settings.field.host_name', 'help' => 'admin.site_settings.help.host_name'])
            ->add('hostAddress', TextType::class, ['label' => 'admin.site_settings.field.host_address'])
            ->add('hostPhone', TextType::class, ['label' => 'admin.site_settings.field.host_phone'])
            ->add('hostWebsite', UrlType::class, ['label' => 'admin.site_settings.field.host_website', 'default_protocol' => 'https'])
            ->add('maintenanceEnabled', CheckboxType::class, ['label' => 'admin.site_settings.field.maintenance_enabled', 'help' => 'admin.site_settings.help.maintenance_enabled', 'required' => false])
            ->add('maintenanceMessage', TextareaType::class, ['label' => 'admin.site_settings.field.maintenance_message', 'help' => 'admin.site_settings.help.maintenance_message', 'required' => false, 'attr' => ['maxlength' => 300, 'rows' => 3]])
            // Saisie à l'heure de Paris, enregistrée en UTC (heure du serveur et de l'horloge de l'application)
            ->add('maintenanceUntil', DateTimeType::class, [
                'label' => 'admin.site_settings.field.maintenance_until', 'help' => 'admin.site_settings.help.maintenance_until', 'required' => false,
                'widget' => 'single_text', 'input' => 'datetime_immutable', 'model_timezone' => 'UTC', 'view_timezone' => 'Europe/Paris', 'with_seconds' => false,
            ])
            ->add('maintenanceAllowedIps', TextareaType::class, ['label' => 'admin.site_settings.field.maintenance_allowed_ips', 'help' => 'admin.site_settings.help.maintenance_allowed_ips', 'required' => false, 'attr' => ['maxlength' => 1000, 'rows' => 2, 'spellcheck' => 'false', 'autocomplete' => 'off']]);
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
