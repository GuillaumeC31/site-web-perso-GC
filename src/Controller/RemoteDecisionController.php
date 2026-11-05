<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RemoteDecisionToken;
use App\Enum\NotificationChannel;
use App\Service\Recruiter\AccessRequestSummary;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liens « Accepter » et « Refuser » des notifications Discord (le webhook Discord ne reçoit pas les clics).
 *
 * - GET : page de confirmation (résumé de la demande, code PIN, bouton). Ne déclenche JAMAIS rien : les
 *   aperçus automatiques des messageries (Discord, Telegram) n'ont aucun effet.
 * - POST : jeton CSRF ET code PIN correct exigés ; décision appliquée comme depuis l'admin, lien consommé.
 *   5 codes faux bloquent le lien.
 * Lien inconnu, expiré, déjà utilisé, bloqué, ou demande déjà traitée : message clair. Pages en noindex,
 * sans cache ni référent ; aucune adresse ni aucun jeton dans les journaux (SecretRedactionProcessor).
 *
 * Liens :
 * - Routes : remote_decision_show (/validation-distance/{token}, GET), remote_decision_confirm (même adresse, POST)
 * - Rend : templates/remote_decision/confirm.html.twig, templates/remote_decision/result.html.twig
 * - Utilise : RemoteDecisionTokens, RemoteDecisionExecutor, AccessRequestSummary
 * - Testé par : tests/Controller/RemoteDecisionControllerTest
 */
final class RemoteDecisionController extends AbstractController
{
    /** Réponse HTTP de chaque refus. */
    private const ERROR_STATUS = [
        RemoteDecisionTokens::ERROR_DISABLED => Response::HTTP_FORBIDDEN,
        RemoteDecisionTokens::ERROR_UNKNOWN => Response::HTTP_NOT_FOUND,
        RemoteDecisionTokens::ERROR_EXPIRED => Response::HTTP_GONE,
        RemoteDecisionTokens::ERROR_USED => Response::HTTP_GONE,
        RemoteDecisionTokens::ERROR_BLOCKED => Response::HTTP_GONE,
        RemoteDecisionTokens::ERROR_PROCESSED => Response::HTTP_CONFLICT,
    ];

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private readonly RemoteDecisionTokens $tokens,
        private readonly RemoteDecisionExecutor $executor,
        private readonly AccessRequestSummary $summary,
    ) {
    }

    /**
     * Affiche la page de confirmation d'un lien (aucun effet).
     *
     * Liens :
     * - Route : remote_decision_show (/validation-distance/{token}, GET)
     * - Rend : templates/remote_decision/confirm.html.twig (ou result.html.twig si le lien est refusé)
     */
    #[Route('/validation-distance/{token}', name: 'remote_decision_show', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function show(string $token): Response
    {
        ['token' => $entry, 'error' => $error] = $this->tokens->check($token, NotificationChannel::Discord);
        if (null !== $error || null === $entry) {
            return $this->refuse((string) $error, $entry);
        }

        return $this->confirmPage($entry, $token, null, Response::HTTP_OK);
    }

    /**
     * Applique la décision si le jeton CSRF et le code PIN sont corrects.
     *
     * Liens :
     * - Route : remote_decision_confirm (/validation-distance/{token}, POST)
     * - Rend : templates/remote_decision/result.html.twig, confirm.html.twig (code PIN faux)
     */
    #[Route('/validation-distance/{token}', name: 'remote_decision_confirm', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function confirm(string $token, Request $request): Response
    {
        ['token' => $entry, 'error' => $error] = $this->tokens->check($token, NotificationChannel::Discord);
        if (null !== $error || null === $entry) {
            return $this->refuse((string) $error, $entry);
        }
        if (!$this->isCsrfTokenValid('remote_decision', $request->request->getString('_token'))) {
            $this->executor->reject(NotificationChannel::Discord, 'csrf', $entry);

            return $this->confirmPage($entry, $token, 'remote_decision.error.csrf', Response::HTTP_BAD_REQUEST);
        }
        $remaining = $this->executor->checkPin($entry, $request->request->getString('pin'));
        if (0 === $remaining) {
            return $this->refuse(RemoteDecisionTokens::ERROR_BLOCKED, $entry, false);
        }
        if (null !== $remaining) {
            return $this->confirmPage($entry, $token, 'remote_decision.error.pin', Response::HTTP_UNPROCESSABLE_ENTITY, $remaining);
        }
        $this->executor->execute($entry);

        return $this->page('remote_decision/result.html.twig', [
            'title' => $entry->isTrial() ? 'remote_decision.trial.done_title' : $entry->getDecision()->translationKey('done_title'),
            'text' => $entry->isTrial() ? 'remote_decision.trial.done_text' : $entry->getDecision()->translationKey('done_text'),
            'name' => $this->nameOf($entry),
            'success' => true,
        ], Response::HTTP_OK);
    }

    /**
     * Rend la page de confirmation (résumé, code PIN, bouton).
     *
     * @param string|null $error     clé de traduction de l'erreur affichée, ou null
     * @param int|null    $remaining essais de code PIN restants (après un code faux)
     */
    private function confirmPage(RemoteDecisionToken $entry, string $token, ?string $error, int $status, ?int $remaining = null): Response
    {
        return $this->page('remote_decision/confirm.html.twig', [
            'decision' => $entry->getDecision(),
            'name' => $this->nameOf($entry),
            'trial' => $entry->isTrial(),
            'lines' => null === $entry->getRecruiter() ? $this->summary->trialNotification()->lines : $this->summary->lines($entry->getRecruiter()),
            'token' => $token,
            'expires_at' => $entry->getExpiresAt(),
            'error' => $error,
            'remaining' => $remaining,
        ], $status);
    }

    /**
     * Rend la page d'un lien refusé (message clair) et note le refus de sécurité.
     *
     * @param string $error motif (RemoteDecisionTokens::ERROR_*)
     * @param bool   $log   faux si le refus est déjà noté (code PIN faux qui bloque le lien)
     */
    private function refuse(string $error, ?RemoteDecisionToken $entry, bool $log = true): Response
    {
        // Demande déjà traitée : pas un refus de sécurité
        if ($log && RemoteDecisionTokens::ERROR_PROCESSED !== $error) {
            $this->executor->reject(NotificationChannel::Discord, $error, $entry);
        }

        return $this->page('remote_decision/result.html.twig', [
            'title' => 'remote_decision.error.'.$error.'_title',
            'text' => 'remote_decision.error.'.$error.'_text',
            'name' => null === $entry ? null : $this->nameOf($entry),
            'success' => false,
        ], self::ERROR_STATUS[$error] ?? Response::HTTP_NOT_FOUND);
    }

    /**
     * Désigne la demande : « Camille Martin (Acme) », ou « la demande d'essai » (notification de test).
     */
    private function nameOf(RemoteDecisionToken $entry): string
    {
        $recruiter = $entry->getRecruiter();

        return null === $recruiter ? 'la demande d\'essai' : \sprintf('%s (%s)', $recruiter->getFullName(), $recruiter->getCompany());
    }

    /**
     * Rend une page de la validation à distance : jamais indexée, jamais en cache, sans référent.
     *
     * @param array<string, mixed> $parameters variables du template
     */
    private function page(string $template, array $parameters, int $status): Response
    {
        $response = $this->render($template, $parameters, new Response(null, $status));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
