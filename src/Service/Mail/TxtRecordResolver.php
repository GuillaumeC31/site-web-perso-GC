<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Lecture des enregistrements DNS TXT (SPF, DKIM, DMARC), remplaçable dans les tests (aucune requête DNS réelle).
 *
 * Liens :
 * - Implémentée par : SystemTxtRecordResolver (dns_get_record)
 * - Utilisée par : DeliverabilityChecker
 */
interface TxtRecordResolver
{
    /**
     * Renvoie les enregistrements TXT d'un nom (morceaux d'un même enregistrement déjà réunis).
     *
     * @param string $host nom interrogé (ex. « _dmarc.guillaume-couget.fr »)
     *
     * @return list<string> textes des enregistrements ; vide si aucun ou si le DNS ne répond pas
     */
    public function getTxtRecords(string $host): array;
}
