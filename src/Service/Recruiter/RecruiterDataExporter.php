<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\ChatMessage;
use App\Entity\Recruiter;
use App\Entity\RecruiterEvent;
use App\Repository\ChatMessageRepository;
use App\Repository\RecruiterEventRepository;

/**
 * Export des données d'un recruteur (droit d'accès et à la portabilité, RGPD art. 15 et 20) : un JSON lisible, aux clés
 * en français, avec tout ce que le site garde à son sujet (demande, accès, évaluation de la demande, évènements, échanges
 * avec l'assistant). Jamais de donnée d'un autre recruteur ni de note de Guillaume Couget.
 *
 * Liens :
 * - Utilisée par : RecruiterAreaController::exportData() (route recruiter_data_export)
 * - Utilise : RecruiterEventRepository, ChatMessageRepository
 */
final readonly class RecruiterDataExporter
{
    /**
     * Reçoit les dépôts des évènements et des échanges avec l'assistant.
     */
    public function __construct(
        private RecruiterEventRepository $eventRepository,
        private ChatMessageRepository $chatMessageRepository,
    ) {
    }

    /**
     * Rassemble les données du recruteur.
     *
     * Liens :
     * - Appelée par : export()
     *
     * @return array<string, mixed> données, prêtes à encoder
     */
    public function collect(Recruiter $recruiter, \DateTimeImmutable $now): array
    {
        $date = static fn (?\DateTimeImmutable $value): ?string => $value?->format(\DATE_ATOM);

        return [
            'export' => ['site' => 'guillaume-couget.fr', 'date' => $date($now)],
            'demande' => [
                'adresse_e_mail' => $recruiter->getEmail(),
                'nom' => $recruiter->getFullName(),
                'entreprise' => $recruiter->getCompany(),
                'fonction' => $recruiter->getJobTitle(),
                'site_web_entreprise' => $recruiter->getCompanyWebsite(),
                'message' => $recruiter->getMessage(),
                'date_de_la_demande' => $date($recruiter->getRequestedAt()),
                'date_du_consentement' => $date($recruiter->getConsentAt()),
                'adresse_ip' => $recruiter->getIpAddress(),
                'compte_cree_par_guillaume_couget' => $recruiter->isCreatedManually(),
            ],
            'acces' => [
                'statut' => $recruiter->getStatusValue(),
                'date_de_la_decision' => $date($recruiter->getDecidedAt()),
                'fin_de_l_acces' => $date($recruiter->getAccessExpiresAt()),
                'derniere_connexion' => $date($recruiter->getLastLoginAt()),
                'derniere_activite' => $date($recruiter->getLastActivityAt()),
                'rappel_d_echeance_envoye_le' => $date($recruiter->getReminderSentAt()),
                'rappels_refuses_le' => $date($recruiter->getRemindersStoppedAt()),
                'prolongation_demandee_le' => $date($recruiter->getExtensionRequestedAt()),
                'consentement_assistant_ia' => $date($recruiter->getAiConsentAt()),
            ],
            // Aide à la décision seulement : la décision est toujours prise par Guillaume Couget (RGPD art. 22)
            'evaluation_de_la_demande' => [
                'score' => $recruiter->getScore(),
                'details_du_score' => $recruiter->getScoringDetails(),
                'synthese_de_l_entreprise' => $recruiter->getCompanySynthesis(),
                'synthese_du' => $date($recruiter->getCompanySynthesisAt()),
            ],
            'evenements' => array_map(static fn (RecruiterEvent $event): array => [
                'type' => $event->getType()->value,
                'date' => $date($event->getOccurredAt()),
            ], $this->eventRepository->findBy(['recruiter' => $recruiter], ['occurredAt' => 'ASC'])),
            'echanges_avec_l_assistant' => array_map(static fn (ChatMessage $message): array => [
                'date' => $date($message->getCreatedAt()),
                'question' => $message->getQuestion(),
                'reponse' => $message->getAnswer(),
                'type' => $message->getOutcome()->value,
                'modele' => $message->getModel(),
            ], $this->chatMessageRepository->findBy(['recruiter' => $recruiter], ['createdAt' => 'ASC'])),
        ];
    }

    /**
     * Encode les données du recruteur en JSON lisible (indenté, accents et barres obliques gardés tels quels).
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::exportData()
     *
     * @return string JSON
     *
     * @throws \JsonException si l'encodage échoue (texte invalide)
     */
    public function export(Recruiter $recruiter, \DateTimeImmutable $now): string
    {
        return json_encode($this->collect($recruiter, $now), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
