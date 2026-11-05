<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Dashboard\DashboardMetrics;
use Twig\Attribute\AsTwigFunction;

/**
 * Expose aux gabarits de l'admin le nombre de points à traiter (pastille du menu « Tableau de bord ») et le nombre
 * de messages non lus (pastille du menu « Messages »).
 *
 * Usage : {{ admin_attention_count() }}, {{ admin_unread_messages() }}
 *
 * Liens :
 * - Utilise : DashboardMetrics::getAttentionCount() (tuiles en cache 60 s)
 * - Utilisée par : templates/admin/base.html.twig (menu)
 */
final class DashboardExtension
{
    /**
     * Reçoit les indicateurs du tableau de bord.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring), seulement quand la fonction est utilisée
     */
    public function __construct(private readonly DashboardMetrics $metrics)
    {
    }

    /**
     * Renvoie le nombre de points à traiter : demandes en attente, e-mails non envoyés, sauvegarde en
     * retard, problème de l'assistant IA.
     *
     * Liens :
     * - Appelée par : la fonction Twig admin_attention_count()
     *
     * @return int 0 si rien n'attend (pas de pastille)
     */
    #[AsTwigFunction('admin_attention_count')]
    public function getAttentionCount(): int
    {
        return $this->metrics->getAttentionCount();
    }

    /**
     * Renvoie le nombre de messages non lus de la boîte (hors archivés).
     *
     * Liens :
     * - Appelée par : la fonction Twig admin_unread_messages() (templates/admin/base.html.twig)
     *
     * @return int 0 si tout est lu (pas de pastille)
     */
    #[AsTwigFunction('admin_unread_messages')]
    public function getUnreadMessages(): int
    {
        return $this->metrics->getUnreadMessages();
    }
}
