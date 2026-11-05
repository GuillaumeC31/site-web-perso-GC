<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SiteSettingsRepository;
use App\Service\Ai\AiModelMonitor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales (lot 8) : mentions légales (éditeur non professionnel, hébergeur, propriété
 * intellectuelle, crédits) et politique de confidentialité complète. Les deux pages sont indexables.
 * Textes fixes dans les traductions (écart volontaire au CDC, décision du 24/10/2026) ; seules les
 * informations de l'hébergeur se règlent dans l'admin (Paramètres).
 *
 * Liens :
 * - Routes : legal_notice (/mentions-legales), legal_privacy (/confidentialite)
 * - Rend : templates/legal/notice.html.twig, templates/legal/privacy.html.twig
 * - Testé par : tests/Controller/LegalPagesTest
 */
final class LegalController extends AbstractController
{
    /** Date de dernière mise à jour des textes légaux (affichée en bas des pages). */
    public const UPDATED_AT = '2026-10-30';

    /**
     * Adresse dédiée à l'exercice des droits RGPD (alias créé chez l’hébergeur), affichée seulement dans la
     * politique de confidentialité : jamais en clair dans le HTML (assemblée par le navigateur,
     * assets/controllers/protected_email_controller.js).
     */
    public const RGPD_EMAIL_USER = 'rgpd';
    public const RGPD_EMAIL_DOMAIN = 'guillaume-couget.fr';

    /**
     * Affiche les mentions légales.
     *
     * Liens :
     * - Route : legal_notice (/mentions-legales) ; lien du pied de page
     * - Rend : templates/legal/notice.html.twig
     * - Utilise : SiteSettingsRepository (hébergeur, réglable dans l'admin)
     */
    #[Route('/mentions-legales', name: 'legal_notice', methods: ['GET'])]
    public function notice(SiteSettingsRepository $settingsRepository): Response
    {
        return $this->render('legal/notice.html.twig', [
            'updated_at' => new \DateTimeImmutable(self::UPDATED_AT),
            'settings' => $settingsRepository->getOrCreate(),
        ]);
    }

    /**
     * Affiche la politique de confidentialité.
     *
     * Liens :
     * - Route : legal_privacy (/confidentialite) ; lien du pied de page et de l'assistant de l'espace recruteur
     * - Rend : templates/legal/privacy.html.twig
     * - Utilise : AiModelMonitor (modèles cités), self::RGPD_EMAIL_USER et RGPD_EMAIL_DOMAIN (adresse protégée),
     *   SiteSettingsRepository (validation automatique des demandes, décrite dans la section « Score et synthèse »)
     */
    #[Route('/confidentialite', name: 'legal_privacy', methods: ['GET'])]
    public function privacy(AiModelMonitor $modelMonitor, SiteSettingsRepository $settingsRepository): Response
    {
        $settings = $settingsRepository->getOrCreate();

        return $this->render('legal/privacy.html.twig', [
            // Seuil de validation automatique, ou null quand toutes les demandes sont décidées à la main (article 22)
            'auto_approval' => $settings->isAutoApprovalEnabled() ? $settings->getAutoApprovalThreshold() : null,
            'ai_models' => array_values(array_unique(array_filter([$modelMonitor->getPrimaryModel(), $modelMonitor->getFallbackModel()]))),
            'updated_at' => new \DateTimeImmutable(self::UPDATED_AT),
            // Parties inversées (reconstituées par le navigateur) et texte de repli sans arobase
            'rgpd_email' => [
                'user' => strrev(self::RGPD_EMAIL_USER),
                'domain' => strrev(self::RGPD_EMAIL_DOMAIN),
                'fallback' => self::RGPD_EMAIL_USER.' [arobase] '.str_replace('.', ' [point] ', self::RGPD_EMAIL_DOMAIN),
            ],
        ]);
    }
}
