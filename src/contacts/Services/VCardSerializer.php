<?php

namespace Contacts\Services;

/**
 * Sérialisation vCard 4.0 (RFC 6350) — côté serveur, comme IcsGenerator pour l'ICS.
 *
 * Types de la fiche → paramètres TYPE vCard :
 *   courriels : perso→home, pro→work, autre→other
 *   telephones: mobile→cell, fixe→voice, fax→fax, autre→other
 */
class VCardSerializer
{
    private const EMAIL_TYPES = ['perso' => 'home', 'pro' => 'work', 'autre' => 'other'];
    private const TEL_TYPES   = ['mobile' => 'cell', 'fixe' => 'voice', 'fax' => 'fax', 'autre' => 'other'];

    /** Échappe les caractères réservés d'une valeur vCard (RFC 6350 §3.4). */
    private function esc(?string $value): string
    {
        $value = (string) $value;
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value
        );
    }

    /** Plie une ligne à 75 octets (RFC 6350 §3.2) — continuation préfixée d'une espace. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out   = substr($line, 0, 75);
        $rest  = substr($line, 75);
        while (strlen($rest) > 74) {
            $out .= "\r\n " . substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }
        return $out . "\r\n " . $rest;
    }

    public function build(array $contact): string
    {
        $lines   = [];
        $lines[] = 'BEGIN:VCARD';
        $lines[] = 'VERSION:4.0';

        $prenom = (string) ($contact['prenom'] ?? '');
        $nom    = (string) ($contact['nom'] ?? '');
        $fn     = trim($prenom . ' ' . $nom);
        if ($fn === '') {
            $fn = (string) ($contact['organisation'] ?? 'Sans nom');
        }

        // N:famille;prénom;autres;préfixes;suffixes
        $lines[] = 'N:' . $this->esc($nom) . ';' . $this->esc($prenom) . ';;;';
        $lines[] = 'FN:' . $this->esc($fn);

        if (!empty($contact['organisation'])) {
            $lines[] = 'ORG:' . $this->esc($contact['organisation']);
        }
        if (!empty($contact['fonction'])) {
            $lines[] = 'TITLE:' . $this->esc($contact['fonction']);
        }

        foreach ($contact['courriels'] ?? [] as $mail) {
            if (empty($mail['valeur'])) { continue; }
            $type    = self::EMAIL_TYPES[$mail['type'] ?? 'autre'] ?? 'other';
            $lines[] = 'EMAIL;TYPE=' . $type . ':' . $this->esc($mail['valeur']);
        }

        foreach ($contact['telephones'] ?? [] as $tel) {
            if (empty($tel['valeur'])) { continue; }
            $type    = self::TEL_TYPES[$tel['type'] ?? 'autre'] ?? 'other';
            $numero  = trim(($tel['indicatif'] ?? '') . ' ' . $tel['valeur']);
            $lines[] = 'TEL;TYPE=' . $type . ':' . $this->esc($numero);
        }

        foreach ($contact['adresses'] ?? [] as $adr) {
            // ADR:boîte;complément;rue;ville;région;code postal;pays
            $rue     = trim(($adr['ligne1'] ?? '') . ' ' . ($adr['ligne2'] ?? ''));
            $type    = !empty($adr['type']) ? ';TYPE=' . $this->esc($adr['type']) : '';
            $lines[] = 'ADR' . $type . ':;;' . $this->esc($rue) . ';' . $this->esc($adr['ville'] ?? '')
                     . ';' . $this->esc($adr['region'] ?? '') . ';' . $this->esc($adr['code_postal'] ?? '')
                     . ';' . $this->esc($adr['pays'] ?? '');
        }

        foreach ($contact['sites'] ?? [] as $site) {
            if (empty($site['url'])) { continue; }
            // Pas d'échappement des ':' et '/' — seule la virgule/point-virgule est réservée.
            $lines[] = 'URL:' . $this->esc($site['url']);
        }

        foreach ($contact['reseaux'] ?? [] as $res) {
            if (empty($res['handle'])) { continue; }
            $lines[] = 'X-SOCIALPROFILE;TYPE=' . $this->esc($res['type'] ?? 'autre')
                     . ':' . $this->esc($res['handle']);
        }

        if (!empty($contact['anniversaire'])) {
            $lines[] = 'BDAY:' . str_replace('-', '', (string) $contact['anniversaire']);
        }

        if (!empty($contact['categories'])) {
            $cats    = array_map([$this, 'esc'], $contact['categories']);
            $lines[] = 'CATEGORIES:' . implode(',', $cats);
        }

        if (!empty($contact['notes'])) {
            $lines[] = 'NOTE:' . $this->esc($contact['notes']);
        }

        if (!empty($contact['maj_le'])) {
            $lines[] = 'REV:' . gmdate('Ymd\THis\Z', strtotime((string) $contact['maj_le']));
        }

        $lines[] = 'UID:urn:cmem2:contact:' . (int) ($contact['id'] ?? 0);
        $lines[] = 'END:VCARD';

        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }
}
