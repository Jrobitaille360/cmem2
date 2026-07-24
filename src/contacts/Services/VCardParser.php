<?php

namespace Contacts\Services;

/**
 * Parseur vCard 3.0/4.0 (RFC 6350) → tableaux de champs du modèle Contact.
 *
 * Tolérant : les propriétés inconnues sont ignorées, une carte sans nom exploitable
 * remonte comme erreur plutôt que d'interrompre l'import.
 */
class VCardParser
{
    private const EMAIL_TYPES = ['home' => 'perso', 'work' => 'pro', 'internet' => 'autre'];
    private const TEL_TYPES   = ['cell' => 'mobile', 'voice' => 'fixe', 'home' => 'fixe',
                                 'work' => 'fixe', 'fax' => 'fax'];

    /** Déplie les lignes de continuation (RFC 6350 §3.2). */
    private function unfold(string $raw): array
    {
        $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw   = preg_replace("/\n[ \t]/", '', $raw);
        $lines = explode("\n", $raw);
        return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
    }

    private function unesc(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    }

    /** Découpe sur ';' non échappés. */
    private function splitComponents(string $value): array
    {
        $parts = preg_split('/(?<!\\\\);/', $value);
        return array_map([$this, 'unesc'], $parts ?: []);
    }

    /** Premier TYPE= trouvé dans les paramètres d'une propriété, en minuscules. */
    private function extractType(string $params): ?string
    {
        if (preg_match('/TYPE=([^;:]+)/i', $params, $m)) {
            $t = strtolower(trim(explode(',', $m[1])[0]));
            return trim($t, '"');
        }
        return null;
    }

    /**
     * @return array{cartes: array<int, array>, erreurs: array<int, array>}
     */
    public function parse(string $raw): array
    {
        $cartes  = [];
        $erreurs = [];
        $current = null;
        $index   = 0;

        foreach ($this->unfold($raw) as $line) {
            $upper = strtoupper($line);

            if ($upper === 'BEGIN:VCARD') {
                $index++;
                $current = [
                    'prenom' => '', 'nom' => '', 'organisation' => null, 'fonction' => null,
                    'courriels' => [], 'telephones' => [], 'adresses' => [], 'sites' => [],
                    'reseaux' => [], 'categories' => [], 'notes' => null, 'anniversaire' => null,
                ];
                continue;
            }

            if ($upper === 'END:VCARD') {
                if ($current === null) { continue; }
                $sansNom = trim($current['prenom'] . $current['nom']) === '';
                if ($sansNom && empty($current['organisation'])) {
                    $erreurs[] = ['carte' => $index, 'raison' => 'vCard sans FN/N ni ORG'];
                } else {
                    $cartes[] = $current;
                }
                $current = null;
                continue;
            }

            if ($current === null) { continue; }

            $sep = strpos($line, ':');
            if ($sep === false) { continue; }

            $head  = substr($line, 0, $sep);
            $value = substr($line, $sep + 1);
            $parts = explode(';', $head, 2);
            $prop  = strtoupper(trim($parts[0]));
            // Préfixe de groupe vCard (« item1.EMAIL ») → on garde la propriété seule.
            if (strpos($prop, '.') !== false) {
                $prop = substr($prop, strrpos($prop, '.') + 1);
            }
            $params = $parts[1] ?? '';

            switch ($prop) {
                case 'N':
                    $comp = $this->splitComponents($value);
                    $current['nom']    = $comp[0] ?? '';
                    $current['prenom'] = $comp[1] ?? '';
                    break;

                case 'FN':
                    // Ne sert que si N est absent : on scinde au premier espace.
                    if (trim($current['prenom'] . $current['nom']) === '') {
                        $fn  = $this->unesc($value);
                        $pos = strpos($fn, ' ');
                        if ($pos === false) {
                            $current['prenom'] = $fn;
                        } else {
                            $current['prenom'] = substr($fn, 0, $pos);
                            $current['nom']    = trim(substr($fn, $pos + 1));
                        }
                    }
                    break;

                case 'ORG':
                    $current['organisation'] = $this->splitComponents($value)[0] ?? null;
                    break;

                case 'TITLE':
                case 'ROLE':
                    $current['fonction'] = $this->unesc($value);
                    break;

                case 'EMAIL':
                    $t = $this->extractType($params);
                    $current['courriels'][] = [
                        'type'   => self::EMAIL_TYPES[$t] ?? 'autre',
                        'valeur' => $this->unesc($value),
                    ];
                    break;

                case 'TEL':
                    $t = $this->extractType($params);
                    $current['telephones'][] = [
                        'type'   => self::TEL_TYPES[$t] ?? 'autre',
                        'valeur' => $this->unesc($value),
                    ];
                    break;

                case 'ADR':
                    $comp = $this->splitComponents($value);
                    $current['adresses'][] = array_filter([
                        'type'        => $this->extractType($params),
                        'ligne1'      => $comp[2] ?? '',
                        'ville'       => $comp[3] ?? '',
                        'region'      => $comp[4] ?? '',
                        'code_postal' => $comp[5] ?? '',
                        'pays'        => $comp[6] ?? '',
                    ], fn($v) => $v !== null && $v !== '');
                    break;

                case 'URL':
                    $current['sites'][] = ['url' => $this->unesc($value)];
                    break;

                case 'X-SOCIALPROFILE':
                    $current['reseaux'][] = [
                        'type'   => $this->extractType($params) ?? 'autre',
                        'handle' => $this->unesc($value),
                    ];
                    break;

                case 'BDAY':
                    $v = preg_replace('/[^0-9]/', '', $value);
                    if (strlen($v) >= 8) {
                        $current['anniversaire'] = substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
                    }
                    break;

                case 'CATEGORIES':
                    foreach (preg_split('/(?<!\\\\),/', $value) ?: [] as $cat) {
                        $cat = trim($this->unesc($cat));
                        if ($cat !== '') { $current['categories'][] = $cat; }
                    }
                    break;

                case 'NOTE':
                    $current['notes'] = $this->unesc($value);
                    break;
            }
        }

        return ['cartes' => $cartes, 'erreurs' => $erreurs];
    }
}
