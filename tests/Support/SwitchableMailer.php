<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Faux serveur d'envoi des tests : injoignable (chaque envoi échoue comme une connexion refusée, comme Mailpit
 * arrêté) ou joignable (les e-mails sont gardés en mémoire). L'état se bascule pendant le test.
 *
 * Liens :
 * - Utilisé par : tests/Controller/MailFailureTest (ResilientMailer construit avec ce faux serveur)
 */
final class SwitchableMailer implements MailerInterface
{
    /** @var list<Email> e-mails « partis » quand le serveur était joignable */
    public array $sent = [];

    /**
     * Crée le faux serveur.
     *
     * @param bool $reachable faux : chaque envoi échoue (connexion refusée)
     */
    public function __construct(public bool $reachable = true)
    {
    }

    /**
     * « Envoie » l'e-mail, ou échoue comme un serveur SMTP injoignable.
     *
     * @throws TransportException si le serveur est injoignable
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (!$this->reachable) {
            throw new TransportException('Connection could not be established with host "127.0.0.1:1025": stream_socket_client(): Unable to connect');
        }
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}
