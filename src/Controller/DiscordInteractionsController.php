<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RemoteDecisionToken;
use App\Enum\NotificationChannel;
use App\Enum\RemoteDecision;
use App\Service\Notification\DiscordApp;
use App\Service\Recruiter\AccessRequestSummary;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée des interactions de l'application Discord (« Interactions Endpoint URL », décision du
 * 2026-10-27) : accepter ou refuser une demande DANS Discord, avec le code PIN.
 *
 * Contrôles, dans l'ordre :
 * 1. signature Ed25519 OBLIGATOIRE (en-têtes X-Signature-Ed25519 et X-Signature-Timestamp, clé publique de
 *    l'application, horodatage récent) : sinon 401, comme l'exige Discord ;
 * 2. PING (type 1) : PONG ;
 * 3. clic sur « ✅ Accepter » ou « ❌ Refuser » (type 3, custom_id « d:jeton ») : compte DISCORD_ADMIN_USER_ID
 *    seulement, jeton valable (canal Discord, usage unique, 72 h, demande en attente), code PIN défini ; réponse :
 *    fenêtre (modal) avec un champ « Code PIN » ;
 * 4. validation de la fenêtre (type 5, custom_id « m:jeton ») : mêmes contrôles, puis code PIN (même code que
 *    Telegram et la page de confirmation, 5 essais puis jeton bloqué) ; bon code : décision appliquée comme depuis
 *    l'admin, message modifié (résultat, boutons retirés).
 * Tout se fait sans appel sortant : réponse à Discord bien en moins de 3 s. Chaque action et chaque refus sont
 * notés au journal de sécurité, jamais le jeton, le code ni l'adresse. Adresse publique : elle est protégée par
 * la signature.
 *
 * Liens :
 * - Route : discord_interactions (/discord/interactions, POST), déclarée sur le portail développeur Discord
 * - Utilise : DiscordApp, RemoteDecisionTokens, RemoteDecisionExecutor, AccessRequestSummary
 * - Testé par : tests/Controller/DiscordInteractionsControllerTest
 */
final class DiscordInteractionsController extends AbstractController
{
    /** Types d'interaction (documentation Discord « Receiving and Responding »). */
    private const PING = 1;
    private const MESSAGE_COMPONENT = 3;
    private const MODAL_SUBMIT = 5;

    /** Types de réponse : PONG, message, mise à jour du message, fenêtre. */
    private const PONG = 1;
    private const CHANNEL_MESSAGE = 4;
    private const UPDATE_MESSAGE = 7;
    private const MODAL = 9;

    /** Message visible seulement par la personne qui a cliqué (indicateur EPHEMERAL). */
    private const EPHEMERAL = 64;

    /** Fuseau des dates affichées. */
    private const TIMEZONE = 'Europe/Paris';

    /** Motifs de refus présentés à Guillaume. */
    private const ERROR_TEXTS = [
        RemoteDecisionTokens::ERROR_DISABLED => 'La validation à distance est désactivée (Paramètres de l\'admin).',
        RemoteDecisionTokens::ERROR_UNKNOWN => 'Bouton inconnu : utilisez l\'admin.',
        RemoteDecisionTokens::ERROR_EXPIRED => 'Bouton expiré (72 h) : décidez depuis l\'admin.',
        RemoteDecisionTokens::ERROR_USED => 'Bouton déjà utilisé.',
        RemoteDecisionTokens::ERROR_BLOCKED => 'Bouton bloqué (trop de codes PIN faux) : décidez depuis l\'admin.',
        RemoteDecisionTokens::ERROR_PROCESSED => 'Demande déjà traitée.',
    ];

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private readonly DiscordApp $app,
        private readonly RemoteDecisionTokens $tokens,
        private readonly RemoteDecisionExecutor $executor,
        private readonly AccessRequestSummary $summary,
    ) {
    }

    /**
     * Traite une interaction envoyée par Discord.
     *
     * Liens :
     * - Route : discord_interactions (/discord/interactions, POST)
     *
     * @return Response 401 si la signature est absente ou invalide ; sinon la réponse JSON attendue par Discord
     */
    #[Route('/discord/interactions', name: 'discord_interactions', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        if (!$this->app->verifySignature($request->headers->get('X-Signature-Ed25519'), $request->headers->get('X-Signature-Timestamp'), $body)) {
            $this->executor->reject(NotificationChannel::Discord, $this->app->isConfigured() ? 'signature_invalid' : 'app_not_configured');

            return new Response('Signature invalide.', Response::HTTP_UNAUTHORIZED);
        }
        $interaction = json_decode($body, true);
        $type = \is_array($interaction) ? (int) ($interaction['type'] ?? 0) : 0;
        if (self::PING === $type) {
            return new JsonResponse(['type' => self::PONG]);
        }
        if (!\is_array($interaction) || !\in_array($type, [self::MESSAGE_COMPONENT, self::MODAL_SUBMIT], true)) {
            return $this->ephemeral('Interaction non prise en charge.');
        }
        // Dans un serveur, le compte est dans member.user ; en message privé, dans user
        $userId = (string) ($interaction['member']['user']['id'] ?? $interaction['user']['id'] ?? '');
        if (!$this->app->isAdmin($userId)) {
            $this->executor->reject(NotificationChannel::Discord, 'foreign_user');

            return $this->ephemeral('Action non autorisée.');
        }
        $customId = (string) ($interaction['data']['custom_id'] ?? '');
        $prefix = self::MESSAGE_COMPONENT === $type ? 'd' : 'm';
        if (1 !== preg_match('/^'.$prefix.':('.RemoteDecisionTokens::TOKEN_PATTERN.')$/', $customId, $matches)) {
            $this->executor->reject(NotificationChannel::Discord, 'unknown');

            return $this->ephemeral(self::ERROR_TEXTS[RemoteDecisionTokens::ERROR_UNKNOWN]);
        }
        $token = $this->tokens->find($matches[1]);
        $error = null === $token || NotificationChannel::Discord !== $token->getChannel() ? RemoteDecisionTokens::ERROR_UNKNOWN : $this->tokens->stateError($token);
        if (null !== $error || null === $token) {
            if (RemoteDecisionTokens::ERROR_PROCESSED !== $error) {
                $this->executor->reject(NotificationChannel::Discord, (string) $error, $token);
            }

            return $this->ephemeral(self::ERROR_TEXTS[$error ?? RemoteDecisionTokens::ERROR_UNKNOWN]);
        }
        if (!$this->tokens->hasPin()) {
            return $this->ephemeral('Aucun code PIN défini : Paramètres › Validation à distance.');
        }

        return self::MESSAGE_COMPONENT === $type ? $this->pinModal($token, $matches[1]) : $this->submit($token, $interaction);
    }

    /**
     * Ouvre la fenêtre du code PIN (le jeton est repris dans l'identifiant de la fenêtre).
     */
    private function pinModal(RemoteDecisionToken $token, string $plainToken): JsonResponse
    {
        return new JsonResponse(['type' => self::MODAL, 'data' => [
            'custom_id' => 'm:'.$plainToken,
            'title' => RemoteDecision::Approve === $token->getDecision() ? 'Accepter la demande' : 'Refuser la demande',
            'components' => [[
                'type' => 18,
                'label' => 'Code PIN',
                'description' => 'Votre code personnel (6 à 12 chiffres).',
                'component' => ['type' => 4, 'custom_id' => 'pin', 'style' => 1, 'min_length' => 6, 'max_length' => 12, 'required' => true],
            ]],
        ]]);
    }

    /**
     * Vérifie le code PIN de la fenêtre, puis applique la décision et met à jour le message.
     *
     * @param array<string, mixed> $interaction interaction « validation de la fenêtre »
     */
    private function submit(RemoteDecisionToken $token, array $interaction): JsonResponse
    {
        $remaining = $this->executor->checkPin($token, self::findValue($interaction['data']['components'] ?? [], 'pin'));
        if (0 === $remaining) {
            return $this->update($token, self::ERROR_TEXTS[RemoteDecisionTokens::ERROR_BLOCKED]);
        }
        if (null !== $remaining) {
            return $this->ephemeral(\sprintf('Code PIN incorrect : encore %d essai%s.', $remaining, $remaining > 1 ? 's' : ''));
        }
        $at = $this->executor->execute($token)->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $when = \sprintf('le %s à %s', $at->format('d/m'), $at->format('H \h i'));

        return $this->update($token, match (true) {
            $token->isTrial() => \sprintf('✅ Essai réussi %s : rien n\'a été décidé (notification de test).', $when),
            RemoteDecision::Approve === $token->getDecision() => \sprintf('✅ Accepté %s : accès ouvert pour 90 jours, e-mail envoyé au recruteur.', $when),
            default => \sprintf('❌ Refusé %s.', $when),
        });
    }

    /**
     * Met à jour le message des boutons : texte d'origine, résultat, boutons retirés.
     */
    private function update(RemoteDecisionToken $token, string $result): JsonResponse
    {
        $recruiter = $token->getRecruiter();
        $notification = null === $recruiter ? $this->summary->trialNotification() : $this->summary->notification($recruiter);
        $content = mb_substr($notification->toDiscordText(null, [], true)."\n\n**".$result.'**', 0, 2000);

        return new JsonResponse(['type' => self::UPDATE_MESSAGE, 'data' => ['content' => $content, 'components' => [], 'allowed_mentions' => ['parse' => []]]]);
    }

    /**
     * Réponse visible seulement par la personne qui a cliqué.
     */
    private function ephemeral(string $text): JsonResponse
    {
        return new JsonResponse(['type' => self::CHANNEL_MESSAGE, 'data' => ['content' => $text, 'flags' => self::EPHEMERAL, 'allowed_mentions' => ['parse' => []]]]);
    }

    /**
     * Cherche la valeur d'un champ de la fenêtre (Label → Text Input, ou ancienne rangée d'actions).
     *
     * @param mixed $components composants reçus
     *
     * @return string valeur saisie ; chaîne vide si le champ est absent
     */
    private static function findValue(mixed $components, string $customId): string
    {
        if (!\is_array($components)) {
            return '';
        }
        if (($components['custom_id'] ?? null) === $customId && \is_string($components['value'] ?? null)) {
            return trim($components['value']);
        }
        foreach ($components as $child) {
            $value = self::findValue($child, $customId);
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }
}
