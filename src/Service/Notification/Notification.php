<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Recruiter;
use App\Enum\NotificationEvent;

/**
 * Contenu d'une notification envoyée à Guillaume : titre, quelques lignes et le lien vers l'admin.
 * - Nouvelle demande d'accès (décision du 2026-10-26) : détails pour décider sur TOUS les canaux (nom,
 *   entreprise, fonction, site web, domaine de l'e-mail, début du message, synthèse ; jamais l'IP ; e-mail
 *   complet dans l'e-mail à Guillaume seulement, v2.0.3 : Recruiter\AccessRequestSummary, emailLines()), et boutons ou liens de décision à distance si la demande attend.
 * - Autres événements : e-mail avec nom et entreprise ; Discord et Telegram (hors de l'Union européenne)
 *   SANS aucune donnée personnelle (type d'événement et lien vers l'admin seulement).
 * Le titre est toujours générique : jamais de nom, d'entreprise ni de message dedans.
 *
 * Les valeurs saisies par les visiteurs sont neutralisées dans les messageries (code en ligne Discord, HTML
 * échappé dans une balise code sur Telegram) : ni lien cliquable, ni mise en forme, ni faux bouton.
 *
 * Liens :
 * - Créée par : Recruiter\AccessRequestSummary, RecruiterAccessManager, ContactController, InterestSignalNotifier
 * - Utilisée par : NotificationDispatcher, TelegramWebhookController
 */
final readonly class Notification
{
    /**
     * Crée une notification.
     *
     * @param NotificationEvent     $event           type d'événement (choisit les canaux)
     * @param string                $title           titre court et générique, sans donnée personnelle (« Nouvelle demande d'accès »)
     * @param array<string, string> $lines           lignes « libellé => valeur » (nom, entreprise…)
     * @param string                $adminUrl        lien absolu vers l'écran de l'admin
     * @param bool                  $chatDetails     vrai : les lignes vont aussi sur Discord et Telegram (nouvelle demande seulement)
     * @param Recruiter|null        $decisionRequest demande en attente à décider à distance ; null : aucun bouton
     * @param string|null           $senderEmail     adresse du visiteur à l'origine de l'événement, JAMAIS affichée :
     *                                               sert seulement à reconnaître une adresse de test (isFromTestDomain())
     * @param bool                  $trial           vrai : notification de test (boutons d'essai sans demande réelle)
     * @param string|null           $emailContact    adresse COMPLÈTE du recruteur, affichée SEULEMENT dans l'e-mail envoyé à
     *                                               Guillaume (lien mailto, v2.0.3) ; jamais sur Discord ni Telegram
     *                                               (lignes $lines : domaine seulement)
     */
    public function __construct(
        public NotificationEvent $event,
        public string $title,
        public array $lines,
        public string $adminUrl,
        public bool $chatDetails = false,
        public ?Recruiter $decisionRequest = null,
        public ?string $senderEmail = null,
        public bool $trial = false,
        public ?string $emailContact = null,
    ) {
    }

    /** Libellé de la ligne de l'adresse e-mail (domaine dans les messageries, adresse complète dans l'e-mail). */
    public const EMAIL_LINE = 'E-mail';

    /**
     * Lignes de l'e-mail envoyé à Guillaume : celles des messageries, avec l'adresse complète du recruteur à la place
     * de son domaine (si emailContact est renseigné).
     *
     * Liens :
     * - Appelée par : toText(), templates/email/notification.html.twig
     *
     * @return array<string, string>
     */
    public function emailLines(): array
    {
        if (null === $this->emailContact || '' === $this->emailContact) {
            return $this->lines;
        }

        // Adresse complète à la place du domaine, au même rang ; ajoutée après le nom si la ligne manque
        if (\array_key_exists(self::EMAIL_LINE, $this->lines)) {
            return array_replace($this->lines, [self::EMAIL_LINE => $this->emailContact]);
        }

        return array_slice($this->lines, 0, 1, true) + [self::EMAIL_LINE => $this->emailContact] + $this->lines;
    }

    /**
     * Indique si l'événement vient d'une adresse des domaines réservés aux tests (« example.test » et ses
     * sous-domaines, RFC 2606) : jamais d'envoi vers Discord ni Telegram dans ce cas, quel que soit
     * l'environnement (tests de bout en bout, captures, vérifications).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher::notify()
     */
    public function isFromTestDomain(): bool
    {
        $domain = mb_strtolower((string) substr((string) strrchr((string) $this->senderEmail, '@'), 1));

        return 'example.test' === $domain || str_ends_with($domain, '.example.test');
    }

    /**
     * Texte brut complet de la notification (version texte de l'e-mail).
     *
     * Liens :
     * - Appelée par : templates/email/notification.txt.twig
     */
    public function toText(): string
    {
        $lines = [$this->title];
        foreach ($this->emailLines() as $label => $value) {
            $lines[] = \sprintf('%s : %s', $label, $value);
        }
        $lines[] = $this->adminUrl;

        return implode("\n", $lines);
    }

    /**
     * Texte générique des messageries, sans aucune donnée personnelle (ni nom, ni entreprise, ni message).
     *
     * Liens :
     * - Appelée par : toDiscordText(), toTelegramHtml() (événements sans détails)
     *
     * Exemple : « Nouvelle demande d'accès recruteur — voir l'admin : https://… »
     */
    public function toChatText(): string
    {
        return \sprintf('%s — voir l\'admin : %s', $this->title, $this->adminUrl);
    }

    /**
     * Texte Discord : générique, ou détaillé (valeurs en code en ligne) avec la décision à distance : sous forme
     * de boutons (envoyés à part, discordComponents()) ou, en repli, de liens dans le texte. Les liens sont entre
     * chevrons : Discord n'en affiche pas d'aperçu (aucune visite automatique).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Discord)
     *
     * @param array{approve: string, refuse: string}|null $decisionLinks liens « Accepter » et « Refuser » ; null : aucun
     * @param list<string>                                $notes         remarques ajoutées (liens indisponibles, liens locaux…)
     * @param bool                                        $buttons       vrai : les liens sont portés par des boutons (aucun lien dans le texte)
     */
    public function toDiscordText(?array $decisionLinks = null, array $notes = [], bool $buttons = false): string
    {
        if (!$this->chatDetails) {
            return $this->toChatText();
        }
        $lines = ['**'.$this->title.'**'];
        foreach ($this->lines as $label => $value) {
            // Code en ligne : ni lien, ni mention, ni mise en forme (accents graves retirés)
            $lines[] = \sprintf('%s : `%s`', $label, str_replace('`', "'", $value));
        }
        if (null !== $decisionLinks && $buttons) {
            $lines[] = '';
            $lines[] = 'Boutons à usage unique, valables 72 h, code PIN demandé.';
        } elseif (null !== $decisionLinks) {
            $lines[] = '';
            $lines[] = \sprintf('✅ Accepter : <%s>', $decisionLinks['approve']);
            $lines[] = \sprintf('❌ Refuser : <%s>', $decisionLinks['refuse']);
            $lines[] = 'Lien à usage unique, valable 72 h, code PIN demandé.';
        }
        foreach ($notes as $note) {
            $lines[] = '⚠️ '.$note;
        }
        if (!$buttons) {
            $lines[] = \sprintf('🔗 Voir dans l\'admin : <%s>', $this->adminUrl);
        }

        return mb_substr(implode("\n", $lines), 0, 2000);
    }

    /**
     * Boutons Discord de type lien (composant 2, style 5) : « ✅ Accepter », « ❌ Refuser » (s'il y a une décision)
     * et « 🔗 Voir dans l'admin », dans une rangée (composant 1). Non interactifs : un webhook qui n'appartient pas
     * à une application ne peut envoyer que ceux-là, avec le paramètre with_components=true (documentation
     * Discord « Execute Webhook »). Ils ouvrent la page de confirmation, rien d'autre.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Discord)
     *
     * @param array{approve: string, refuse: string}|null $decisionLinks liens « Accepter » et « Refuser » ; null : bouton vers l'admin seul
     *
     * @return list<array{type: int, components: list<array{type: int, style: int, label: string, url: string}>}>
     */
    public function discordComponents(?array $decisionLinks): array
    {
        $buttons = [];
        if (null !== $decisionLinks) {
            $buttons[] = ['type' => 2, 'style' => 5, 'label' => '✅ Accepter', 'url' => $decisionLinks['approve']];
            $buttons[] = ['type' => 2, 'style' => 5, 'label' => '❌ Refuser', 'url' => $decisionLinks['refuse']];
        }
        $buttons[] = ['type' => 2, 'style' => 5, 'label' => '🔗 Voir dans l\'admin', 'url' => $this->adminUrl];

        return [['type' => 1, 'components' => $buttons]];
    }

    /**
     * Boutons Discord INTERACTIFS (application Discord) : « ✅ Accepter » (style 3), « ❌ Refuser » (style 4), dont le
     * custom_id ne porte que le jeton à usage unique (jamais l'identifiant de la demande) ; « 🔗 Voir dans l'admin »
     * reste un bouton de type lien (style 5).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Discord, application configurée)
     * - Voir aussi : DiscordInteractionsController (clic : fenêtre du code PIN)
     *
     * @param array{approve: string, refuse: string}|null $tokens jetons en clair ; null : bouton vers l'admin seul
     *
     * @return list<array{type: int, components: list<array<string, int|string>>}>
     */
    public function discordInteractiveComponents(?array $tokens): array
    {
        $buttons = [];
        if (null !== $tokens) {
            $buttons[] = ['type' => 2, 'style' => 3, 'label' => '✅ Accepter', 'custom_id' => 'd:'.$tokens['approve']];
            $buttons[] = ['type' => 2, 'style' => 4, 'label' => '❌ Refuser', 'custom_id' => 'd:'.$tokens['refuse']];
        }
        $buttons[] = ['type' => 2, 'style' => 5, 'label' => '🔗 Voir dans l\'admin', 'url' => $this->adminUrl];

        return [['type' => 1, 'components' => $buttons]];
    }

    /**
     * Texte Telegram en HTML (parse_mode HTML) : générique, ou détaillé (valeurs échappées dans une balise
     * code, jamais transformées en liens). Les boutons sont envoyés à part (reply_markup).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Telegram), TelegramCallbackHandler (message modifié)
     *
     * @param bool         $withAdminLink vrai : lien vers l'admin dans le texte (quand le bouton « Voir dans l'admin » est impossible)
     * @param list<string> $notes         remarques ajoutées (boutons indisponibles…)
     */
    public function toTelegramHtml(bool $withAdminLink = true, array $notes = []): string
    {
        if (!$this->chatDetails) {
            return self::escape($this->toChatText());
        }
        $lines = ['<b>'.self::escape($this->title).'</b>'];
        foreach ($this->lines as $label => $value) {
            $lines[] = \sprintf('%s : <code>%s</code>', self::escape($label), self::escape($value));
        }
        foreach ($notes as $note) {
            $lines[] = '⚠️ '.self::escape($note);
        }
        if ($withAdminLink) {
            $lines[] = \sprintf('Voir dans l\'admin : %s', self::escape($this->adminUrl));
        }

        return mb_substr(implode("\n", $lines), 0, 3500);
    }

    /**
     * Échappe un texte pour le HTML de Telegram (&, < et >, seuls caractères exigés hors des attributs).
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
