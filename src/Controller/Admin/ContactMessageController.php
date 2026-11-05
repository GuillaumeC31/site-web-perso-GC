<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ContactMessage;
use App\Entity\User;
use App\Enum\ContactSource;
use App\Enum\ContactStatus;
use App\Repository\ContactMessageRepository;
use App\Service\ActionConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Messages reçus (CDC §9) : formulaire de contact public et questions des recruteurs posées depuis l'assistant.
 *
 * Page de liste (décision du 2026-10-29) : filtres Tous (hors archivés) / Non lus / Lus / Répondus / Archivés avec
 * leur nombre, filtre par type, recherche (nom, entreprise, mot du message), tri par date (récents d'abord par
 * défaut), pagination au-delà de 25 messages ; filtre, type et tri mémorisés sur le compte admin. Actions groupées :
 * marquer lus, marquer répondus, archiver, supprimer (confirmation ; sans JavaScript, page de confirmation).
 * « Marquer comme répondu » : Guillaume répond depuis webmail, le site ne peut pas le savoir seul.
 *
 * Liens :
 * - Routes : admin_contact_message_index, _show, _action, _bulk, _delete (/{ADMIN_PATH}/messages)
 * - Rend : templates/admin/contact_message/*.html.twig
 * - Utilise : ContactMessageRepository (findFiltered(), countByFilter()), ActionConfirmation, User::setMessageFilters()
 * - Testé par : tests/Controller/Admin/ContactMessageFiltersTest, tests/e2e/messages.spec.js
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/messages', name: 'contact_message_')]
final class ContactMessageController extends AbstractController
{
    /** Messages par page. */
    public const PER_PAGE = 25;

    /** Valeurs du paramètre « filtre » dans l'adresse => filtre du dépôt. */
    private const FILTER_PARAMS = ['tous' => 'all', 'non-lus' => 'unread', 'lus' => 'read', 'repondus' => 'replied', 'archives' => 'archived'];

    /** Valeurs du paramètre « type » => origine (null : tous les types). */
    private const TYPE_PARAMS = ['tous' => null, 'contact' => ContactSource::ContactForm, 'question' => ContactSource::RecruiterQuestion];

    /** Valeurs du paramètre « tri » => tri du dépôt. */
    private const SORT_PARAMS = ['recents' => 'newest', 'anciens' => 'oldest'];

    /** Actions possibles sur un message (bouton de la fiche) ou un groupe de messages. */
    private const ACTIONS = ['read', 'unread', 'replied', 'unreplied', 'archive', 'unarchive'];

    /** Actions groupées (case à cocher), dont la suppression (confirmée). */
    private const BULK_ACTIONS = ['read', 'replied', 'archive', 'delete'];

    /**
     * Reçoit les dépendances communes.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Liste les messages selon les filtres ; sans paramètre, reprend les filtres mémorisés du compte ; avec des
     * paramètres, les mémorise (filtre, type, tri ; jamais la recherche).
     *
     * Liens :
     * - Route : admin_contact_message_index (/{ADMIN_PATH}/messages?filtre=…&type=…&q=…&tri=…&page=…)
     * - Rend : templates/admin/contact_message/index.html.twig
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ContactMessageRepository $repository): Response
    {
        $user = $this->getUser();
        $query = $request->query;
        $hasChoice = $query->has('filtre') || $query->has('type') || $query->has('tri');
        $saved = $user instanceof User ? ($user->getMessageFilters() ?? []) : [];
        $params = [
            'filtre' => $this->pick($hasChoice ? $query->getString('filtre') : ($saved['filtre'] ?? ''), self::FILTER_PARAMS, 'tous'),
            'type' => $this->pick($hasChoice ? $query->getString('type') : ($saved['type'] ?? ''), self::TYPE_PARAMS, 'tous'),
            'tri' => $this->pick($hasChoice ? $query->getString('tri') : ($saved['tri'] ?? ''), self::SORT_PARAMS, 'recents'),
        ];
        if ($hasChoice && $user instanceof User && $params !== $saved) {
            $user->setMessageFilters($params);
            $this->entityManager->flush();
        }
        $search = mb_substr(trim($query->getString('q')), 0, 100);
        $source = self::TYPE_PARAMS[$params['type']];
        $result = $repository->findFiltered(self::FILTER_PARAMS[$params['filtre']], $source, $search, self::SORT_PARAMS[$params['tri']], max(1, $query->getInt('page', 1)), self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        $counts = $repository->countByFilter($source, $search);

        return $this->render('admin/contact_message/index.html.twig', [
            'messages' => $result['items'],
            'total' => $result['total'],
            'page' => min(max(1, $query->getInt('page', 1)), $pages),
            'pages' => $pages,
            'params' => $params,
            'search' => $search,
            'filters' => array_combine(array_keys(self::FILTER_PARAMS), array_map(static fn (string $filter): int => $counts[$filter], self::FILTER_PARAMS)),
            'types' => array_keys(self::TYPE_PARAMS),
            'sorts' => array_keys(self::SORT_PARAMS),
            'return_query' => http_build_query(array_filter($params + ['q' => $search, 'page' => $query->getInt('page', 1) > 1 ? (string) $query->getInt('page') : ''])),
        ]);
    }

    /**
     * Affiche un message ; un message non lu passe en « lu ».
     *
     * Liens :
     * - Route : admin_contact_message_show (/{ADMIN_PATH}/messages/{id}) ; lien des notifications
     * - Rend : templates/admin/contact_message/show.html.twig
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ContactMessage $message): Response
    {
        if (ContactStatus::New === $message->getStatus()) {
            $message->setStatus(ContactStatus::Read);
            $this->entityManager->flush();
        }

        return $this->render('admin/contact_message/show.html.twig', ['message' => $message]);
    }

    /**
     * Applique une action à un message : lu, non lu, répondu (date du jour), non répondu, archivé, désarchivé.
     *
     * Liens :
     * - Route : admin_contact_message_action (/{ADMIN_PATH}/messages/{id}/action, POST, jeton CSRF)
     *
     * @throws BadRequestHttpException si l'action ou le jeton est invalide
     */
    #[Route('/{id}/action', name: 'action', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function action(ContactMessage $message, Request $request): Response
    {
        $action = $request->request->getString('action');
        if (!\in_array($action, self::ACTIONS, true) || !$this->isCsrfTokenValid('contact_message_action_'.$message->getId(), $request->request->getString('_token'))) {
            throw new BadRequestHttpException('Action invalide.');
        }
        $this->apply($message, $action);
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.contact_message.flash.'.$action);

        return $this->redirectToRoute('admin_contact_message_show', ['id' => $message->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Applique une action aux messages cochés : marquer lus, marquer répondus, archiver, supprimer. La suppression
     * demande une confirmation (fenêtre ; sans JavaScript, page de confirmation qui renvoie les mêmes cases).
     *
     * Liens :
     * - Route : admin_contact_message_bulk (/{ADMIN_PATH}/messages/actions-groupees, POST, jeton CSRF)
     * - Utilise : ContactMessageRepository::findByIds(), ActionConfirmation
     *
     * @throws BadRequestHttpException si l'action ou le jeton est invalide
     */
    #[Route('/actions-groupees', name: 'bulk', methods: ['POST'])]
    public function bulk(Request $request, ContactMessageRepository $repository, ActionConfirmation $confirmation): Response
    {
        $action = $request->request->getString('action');
        if (!\in_array($action, self::BULK_ACTIONS, true) || !$this->isCsrfTokenValid('contact_message_bulk', $request->request->getString('_token'))) {
            throw new BadRequestHttpException('Action invalide.');
        }
        // Retour à la liste avec les mêmes filtres (seulement une chaîne de requête, jamais une autre adresse)
        $return = $this->generateUrl('admin_contact_message_index').(1 === preg_match('/^[a-z0-9=&%+._-]*$/i', $request->request->getString('retour')) && '' !== $request->request->getString('retour') ? '?'.$request->request->getString('retour') : '');
        $ids = array_values(array_unique(array_map('intval', array_filter($request->request->all('ids'), 'is_scalar'))));
        $messages = $repository->findByIds($ids);
        if ([] === $messages) {
            $this->addFlash('warning', 'admin.contact_message.flash.none_selected');

            return $this->redirect($return, Response::HTTP_SEE_OTHER);
        }
        if ('delete' === $action && !$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $this->translator->trans('admin.contact_message.confirm_bulk_delete', ['%count%' => \count($messages)]),
                $this->translator->trans('admin.contact_message.confirm_bulk_delete_text'),
                $this->translator->trans('admin.contact_message.delete'),
                $return,
            );
        }
        foreach ($messages as $message) {
            if ('delete' === $action) {
                $this->entityManager->remove($message);
            } else {
                $this->apply($message, $action);
            }
        }
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('admin.contact_message.flash.bulk_'.$action, ['%count%' => \count($messages)]));

        return $this->redirect($return, Response::HTTP_SEE_OTHER);
    }

    /**
     * Supprime un message (jeton CSRF), après confirmation (sans JavaScript : page de confirmation).
     *
     * Liens :
     * - Route : admin_contact_message_delete (/{ADMIN_PATH}/messages/{id}/supprimer, POST)
     * - Utilise : ActionConfirmation
     */
    #[Route('/{id}/supprimer', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(ContactMessage $message, Request $request, ActionConfirmation $confirmation): Response
    {
        $tokenValid = $this->isCsrfTokenValid('contact_message_delete_'.$message->getId(), $request->request->getString('_token'));
        if ($tokenValid && !$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $this->translator->trans('admin.contact_message.confirm_delete'),
                '',
                $this->translator->trans('admin.contact_message.delete'),
                $this->generateUrl('admin_contact_message_show', ['id' => $message->getId()]),
            );
        }
        if ($tokenValid) {
            $this->entityManager->remove($message);
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.contact_message.flash.deleted');
        }

        return $this->redirectToRoute('admin_contact_message_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Applique une action (hors suppression) à un message.
     *
     * @param string $action une des ACTIONS
     */
    private function apply(ContactMessage $message, string $action): void
    {
        $now = $this->clock->now();
        match ($action) {
            'read' => $message->setStatus(ContactStatus::Read),
            'unread' => $message->setStatus(ContactStatus::New),
            // Déjà répondu : la première date est gardée
            'replied' => $message->setRepliedAt($message->getRepliedAt() ?? $now),
            'unreplied' => $message->setRepliedAt(null),
            'archive' => $message->setArchivedAt($message->getArchivedAt() ?? $now),
            'unarchive' => $message->setArchivedAt(null),
            default => null,
        };
    }

    /**
     * Garde une valeur de paramètre connue, sinon la valeur par défaut.
     *
     * @param array<string, mixed> $allowed valeurs permises (clés)
     */
    private function pick(string $value, array $allowed, string $default): string
    {
        return \array_key_exists($value, $allowed) ? $value : $default;
    }
}
