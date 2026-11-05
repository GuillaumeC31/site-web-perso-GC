<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SiteSettings;
use App\Entity\User;
use App\Repository\SiteSettingsRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Service\AppVersion;
use App\Service\Dashboard\DashboardMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Affiche le tableau de bord de l'administration : grille de tuiles (recruteurs, messages, contenu à
 * compléter, assistant IA, communication, sécurité, sauvegardes et tâches planifiées, site, calculatrice), chacune
 * avec sa valeur, son statut (icône et texte) et son lien ; bouton « Actualiser » ; récapitulatif en
 * fenêtre à l'ouverture, seulement si quelque chose a changé depuis la dernière visite.
 *
 * Liens :
 * - Routes : admin_dashboard, admin_backup_downloaded (rappel de sauvegarde)
 * - Rend : templates/admin/dashboard/index.html.twig
 * - Utilise : DashboardMetrics (seule source des indicateurs), User::getDashboardSeen()
 * - Voir aussi : scripts/security/audit-route-guards.sh (vérifie le #[IsGranted('ADMIN_KEY_VERIFIED')])
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
final class DashboardController extends AbstractController
{
    /**
     * Affiche le tableau de bord ; avec ?actualiser=1, relit les indicateurs puis revient à la page.
     *
     * Liens :
     * - Route : admin_dashboard (/{ADMIN_PATH}, GET ; ?actualiser=1 : bouton « Actualiser »)
     * - Utilise : DashboardMetrics::getTiles(), getChangedTiles(), getDigest() ; état déjà vu enregistré
     *   sur le compte admin (User::setDashboardSeen())
     * - Rend : templates/admin/dashboard/index.html.twig
     *
     * @param Request                      $request              requête (paramètre « actualiser »)
     * @param DashboardMetrics             $metrics              tuiles du tableau de bord
     * @param EntityManagerInterface       $entityManager        enregistrement de l'état déjà vu
     * @param WebauthnCredentialRepository $credentialRepository clés enregistrées (rappel « clé de secours »)
     * @param AppVersion                   $appVersion           version (fichier VERSION)
     * @param SiteSettingsRepository       $settingsRepository   date de la dernière copie de sauvegarde téléchargée
     * @param ClockInterface               $clock                horloge (rappel de sauvegarde après 30 jours)
     *
     * @return Response la page HTML, ou la redirection après « Actualiser »
     */
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, DashboardMetrics $metrics, EntityManagerInterface $entityManager, WebauthnCredentialRepository $credentialRepository, AppVersion $appVersion, SiteSettingsRepository $settingsRepository, ClockInterface $clock): Response
    {
        if ($request->query->getBoolean('actualiser')) {
            $metrics->getTiles(true);
            $this->addFlash('info', 'admin.dashboard.refreshed');

            return $this->redirectToRoute('admin_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        $dashboard = $metrics->getTiles();
        $user = $this->getUser();
        $changed = [];
        if ($user instanceof User) {
            $changed = $metrics->getChangedTiles($dashboard['tiles'], $user->getDashboardSeen());
            $digest = $metrics->getDigest($dashboard['tiles']);
            if ($digest !== $user->getDashboardSeen()) {
                $user->setDashboardSeen($digest);
                $entityManager->flush();
            }
        }
        $tiles = array_column($dashboard['tiles'], null, 'id');

        return $this->render('admin/dashboard/index.html.twig', [
            'tiles' => $tiles,
            'computed_at' => $dashboard['computed_at'],
            'changed_tiles' => $changed,
            'app_version' => $appVersion->get(),
            'security_key_count' => $user instanceof User ? $credentialRepository->countByUser($user) : 0,
            'failed_emails' => (int) $tiles['communication']['value'],
            'backup_reminder' => $this->isBackupDownloadDue($settingsRepository->getOrCreate(), $clock->now()),
            'backup_downloaded_at' => $settingsRepository->getOrCreate()->getBackupDownloadedAt(),
        ]);
    }

    /**
     * Note qu'une copie de la sauvegarde vient d'être téléchargée hors de l’hébergeur (bouton du rappel) : le rappel
     * disparaît pour 30 jours.
     *
     * Liens :
     * - Route : admin_backup_downloaded (/{ADMIN_PATH}/sauvegarde-telechargee, POST, jeton CSRF « backup_downloaded »)
     * - Utilise : SiteSettings::setBackupDownloadedAt()
     *
     * @return Response redirection vers le tableau de bord
     */
    #[Route('/sauvegarde-telechargee', name: 'backup_downloaded', methods: ['POST'])]
    #[IsCsrfTokenValid('backup_downloaded')]
    public function backupDownloaded(SiteSettingsRepository $settingsRepository, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        // persist : la ligne des paramètres n'existe peut-être pas encore (getOrCreate() renvoie alors un objet neuf)
        $entityManager->persist($settingsRepository->getOrCreate()->setBackupDownloadedAt($clock->now()));
        $entityManager->flush();
        $this->addFlash('success', 'admin.dashboard.backup_reminder.done');

        return $this->redirectToRoute('admin_dashboard', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Dit si le rappel « télécharger une copie de la sauvegarde » est dû : jamais fait, ou il y a plus de 30 jours.
     *
     * Liens :
     * - Appelée par : index()
     */
    private function isBackupDownloadDue(SiteSettings $settings, \DateTimeImmutable $now): bool
    {
        $last = $settings->getBackupDownloadedAt();

        return null === $last || $last < $now->modify(\sprintf('-%d days', SiteSettings::BACKUP_DOWNLOAD_DAYS));
    }
}
