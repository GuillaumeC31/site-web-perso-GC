<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\Recruiter;
use App\Enum\NotificationEvent;
use App\Enum\RecruiterStatus;
use App\Service\Notification\Notification;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Résumé d'un recruteur pour décider sans ouvrir l'admin (décisions du 2026-10-26 et du 2026-10-27) : nom,
 * entreprise, fonction, site web, DOMAINE de l'e-mail, score avec son niveau en clair et les critères qui
 * l'expliquent, début du message (200 caractères), synthèse de l'entreprise si elle existe. JAMAIS l'adresse IP
 * ni l'e-mail complet. Même contenu sur l'e-mail, Discord et Telegram, pour les nouvelles demandes et les
 * signaux d'intérêt (ceux-ci avec la liste des signaux, sans bouton de décision).
 *
 * Niveaux du score (fiabilité de la demande, décision du 2026-10-27) : 0 bloquée (adresse jetable ou IP listée),
 * 1 à 39 faible, 40 à 69 moyenne, 70 à 89 bonne, 90 à 100 très bonne.
 *
 * Liens :
 * - Utilisé par : RecruiterAccessManager::requestAccess(), InterestSignalNotifier, TelegramCallbackHandler
 *   (message reconstruit), RemoteDecisionController (page de confirmation)
 * - Testé par : tests/Service/Recruiter/AccessRequestSummaryTest
 */
final readonly class AccessRequestSummary
{
    /** Longueur du début du message repris dans la notification. */
    public const MESSAGE_LENGTH = 200;

    /** Longueur maximale de la synthèse de l'entreprise reprise dans la notification. */
    public const SYNTHESIS_LENGTH = 600;

    /** Critères du score repris au plus. */
    public const MAX_CRITERIA = 3;

    /**
     * Reçoit le générateur d'adresses (lien vers la fiche dans l'admin).
     */
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * Renvoie les lignes « libellé => valeur » d'un recruteur ; les champs vides sont omis.
     *
     * Liens :
     * - Appelée par : notification(), interestNotification(), RemoteDecisionController (page de confirmation)
     *
     * @param array<string, string> $extra lignes ajoutées après le score (ex. « Signaux »)
     *
     * @return array<string, string>
     */
    public function lines(Recruiter $recruiter, array $extra = []): array
    {
        $message = (string) preg_replace('/\s+/u', ' ', trim((string) $recruiter->getMessage()));
        $synthesis = (string) preg_replace('/\s+/u', ' ', trim((string) $recruiter->getCompanySynthesis()));

        return array_filter([
            'Nom' => $recruiter->getFullName(),
            'Entreprise' => $recruiter->getCompany(),
            'Fonction' => (string) $recruiter->getJobTitle(),
            'Site web' => (string) $recruiter->getCompanyWebsite(),
            'E-mail' => $recruiter->getEmailDomain(),
            'Score' => self::scoreLabel($recruiter->getScore()),
            'Critères' => implode(' ; ', $this->criteria($recruiter)),
            ...$extra,
            'Message' => mb_strimwidth($message, 0, self::MESSAGE_LENGTH, '…'),
            'Synthèse' => mb_strimwidth($synthesis, 0, self::SYNTHESIS_LENGTH, '…'),
        ], static fn (string $value): bool => '' !== $value);
    }

    /**
     * Construit la notification d'une nouvelle demande (détails sur tous les canaux ; boutons de décision à
     * distance si la demande attend une décision).
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::requestAccess(), TelegramCallbackHandler
     */
    public function notification(Recruiter $recruiter): Notification
    {
        $pending = RecruiterStatus::Pending === $recruiter->getStatus();

        return new Notification(
            NotificationEvent::AccessRequest,
            $pending ? 'Nouvelle demande d\'accès recruteur' : 'Demande d\'accès validée automatiquement',
            $this->lines($recruiter),
            $this->adminUrl($recruiter),
            chatDetails: true,
            decisionRequest: $pending ? $recruiter : null,
            senderEmail: $recruiter->getEmail(),
            emailContact: $recruiter->getEmail(),
        );
    }

    /**
     * Construit la notification d'un signal d'intérêt EXPLICITE (clic sur « Je suis intéressé », v2.0.3) : mêmes
     * informations, signal reçu, lien « Voir dans l'admin » seulement (rien à accepter).
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier
     *
     * @param list<string> $signals signaux lisibles (« Je suis intéressé (27/10 14:05) »)
     */
    public function interestNotification(Recruiter $recruiter, array $signals): Notification
    {
        return new Notification(
            NotificationEvent::Interest,
            'Signal d\'intérêt d\'un recruteur',
            $this->lines($recruiter, ['Signaux' => implode(', ', $signals)]),
            $this->adminUrl($recruiter),
            chatDetails: true,
            senderEmail: $recruiter->getEmail(),
            emailContact: $recruiter->getEmail(),
        );
    }

    /**
     * Construit la notification d'activité d'un recruteur (v2.0.3) : mêmes informations, activité observée (première
     * connexion, CV téléchargé), lien « Voir dans l'admin » seulement. Jamais titrée « Signal d'intérêt ».
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier
     *
     * @param list<string> $activities activités lisibles (« Première connexion (27/10 14:05) »)
     */
    public function activityNotification(Recruiter $recruiter, array $activities): Notification
    {
        return new Notification(
            NotificationEvent::RecruiterActivity,
            'Activité d\'un recruteur',
            $this->lines($recruiter, ['Activité' => implode(', ', $activities)]),
            $this->adminUrl($recruiter),
            chatDetails: true,
            senderEmail: $recruiter->getEmail(),
            emailContact: $recruiter->getEmail(),
        );
    }

    /**
     * Construit la notification de test d'un canal : texte d'essai et, si l'interrupteur est actif, boutons
     * (Telegram) ou liens (Discord) d'essai qui suivent tout le parcours sans rien décider.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher::sendTest(), TelegramCallbackHandler (message d'essai reconstruit)
     */
    public function trialNotification(): Notification
    {
        return new Notification(
            NotificationEvent::Contact,
            'Notification de test du site',
            [
                'Nom' => 'Demande d\'essai (fictive)',
                'Entreprise' => 'Essai de la validation à distance',
                'Score' => self::scoreLabel(70),
                'Action' => 'Accepter ou refuser cet essai ne décide rien : le parcours est vérifié de bout en bout.',
            ],
            $this->urlGenerator->generate('admin_site_settings_edit', [], UrlGeneratorInterface::ABSOLUTE_URL),
            chatDetails: true,
            trial: true,
        );
    }

    /**
     * Libellé du score avec son niveau en clair (« 70/100 — fiabilité bonne »).
     *
     * Liens :
     * - Appelée par : lines() ; templates (page de confirmation)
     */
    public static function scoreLabel(int $score): string
    {
        $level = match (true) {
            $score <= 0 => 'bloquée (adresse jetable ou IP listée)',
            $score < 40 => 'faible',
            $score < 70 => 'moyenne',
            $score < 90 => 'bonne',
            default => 'très bonne',
        };

        return \sprintf('%d/100 — fiabilité %s', $score, $level);
    }

    /**
     * Renvoie les 2 ou 3 critères qui expliquent le mieux le score : blocages d'abord, puis critères qui
     * rapportent le plus de points, puis points d'attention.
     *
     * @return list<string> critères lisibles (« Domaine professionnel : acme.fr (+40) ») ; vide si aucun détail
     */
    private function criteria(Recruiter $recruiter): array
    {
        $ranked = [];
        foreach ($recruiter->getScoringDetails() as $detail) {
            $level = $detail['level'];
            $result = $detail['result'];
            $points = 1 === preg_match('/\(\+(\d+)\)/', $result, $m) ? (int) $m[1] : 0;
            $rank = match (true) {
                'danger' === $level => 1000,
                $points > 0 => $points,
                'warning' === $level => 1,
                default => 0,
            };
            if ($rank > 0) {
                $ranked[] = ['rank' => $rank, 'text' => \sprintf('%s : %s', $detail['label'], $result)];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return array_column(\array_slice($ranked, 0, self::MAX_CRITERIA), 'text');
    }

    /**
     * Lien absolu vers la fiche du recruteur dans l'admin.
     */
    private function adminUrl(Recruiter $recruiter): string
    {
        return $this->urlGenerator->generate('admin_recruiter_show', ['id' => $recruiter->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
