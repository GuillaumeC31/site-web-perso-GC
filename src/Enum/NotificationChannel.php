<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Canal de notification : e-mail, Discord (webhook) ou Telegram (bot). Un canal n'est utilisé que
 * s'il est configuré : adresse de notification du compte admin pour l'e-mail ; DISCORD_WEBHOOK_URL et
 * TELEGRAM_* dans .env.local pour les autres.
 *
 * Liens :
 * - Utilisé par : SiteSettings, NotificationDispatcher
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Discord = 'discord';
    case Telegram = 'telegram';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : SiteSettingsType
     */
    public function translationKey(): string
    {
        return 'notification.channel.'.$this->value;
    }
}
