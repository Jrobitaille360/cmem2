<?php

namespace Contacts\Services;

/**
 * Parseur CSV d'import contacts.
 *
 * En-tête obligatoire. Colonnes reconnues (insensibles à la casse/accents des libellés courants) :
 *   prenom, nom, organisation, fonction, courriel, telephone, categories,
 *   ville, adresse, code_postal, pays, notes, anniversaire, site
 * Séparateur détecté parmi ',' ';' et tabulation. Les colonnes inconnues sont ignorées.
 * Une ligne sans prenom/nom/organisation exploitables remonte en erreur, sans interrompre l'import.
 */
class CsvParser
{
    private const ALIASES = [
        'prenom' => 'prenom', 'prénom' => 'prenom', 'first_name' => 'prenom', 'firstname' => 'prenom',
        'nom' => 'nom', 'last_name' => 'nom', 'lastname' => 'nom',
        'organisation' => 'organisation', 'organization' => 'organisation',
        'entreprise' => 'organisation', 'company' => 'organisation',
        'fonction' => 'fonction', 'titre' => 'fonction', 'title' => 'fonction',
        'courriel' => 'courriel', 'email' => 'courriel', 'e-mail' => 'courriel',
        'telephone' => 'telephone', 'téléphone' => 'telephone', 'phone' => 'telephone', 'tel' => 'telephone',
        'categories' => 'categories', 'catégories' => 'categories', 'etiquettes' => 'categories',
        'adresse' => 'adresse', 'ligne1' => 'adresse', 'address' => 'adresse',
        'ville' => 'ville', 'city' => 'ville',
        'code_postal' => 'code_postal', 'codepostal' => 'code_postal', 'zip' => 'code_postal',
        'pays' => 'pays', 'country' => 'pays',
        'notes' => 'notes', 'note' => 'notes',
        'anniversaire' => 'anniversaire', 'bday' => 'anniversaire', 'birthday' => 'anniversaire',
        'site' => 'site', 'url' => 'site', 'website' => 'site',
    ];

    private function detectSeparator(string $headerLine): string
    {
        $counts = [
            ','  => substr_count($headerLine, ','),
            ';'  => substr_count($headerLine, ';'),
            "\t" => substr_count($headerLine, "\t"),
        ];
        arsort($counts);
        return array_key_first($counts);
    }

    /**
     * @return array{lignes: array<int, array>, erreurs: array<int, array>}
     */
    public function parse(string $raw): array
    {
        $raw   = str_replace(["\r\n", "\r"], "\n", trim($raw));
        $rows  = explode("\n", $raw);
        if (count($rows) < 2) {
            return ['lignes' => [], 'erreurs' => [['ligne' => 1, 'raison' => 'CSV sans données']]];
        }

        $sep     = $this->detectSeparator($rows[0]);
        $headers = array_map(
            fn($h) => self::ALIASES[strtolower(trim($h, " \t\"'"))] ?? null,
            str_getcsv($rows[0], $sep)
        );

        $lignes  = [];
        $erreurs = [];

        for ($i = 1; $i < count($rows); $i++) {
            if (trim($rows[$i]) === '') { continue; }

            $cells = str_getcsv($rows[$i], $sep);
            $vals  = [];
            foreach ($headers as $idx => $key) {
                if ($key === null) { continue; }
                $vals[$key] = trim((string) ($cells[$idx] ?? ''));
            }

            $prenom = $vals['prenom'] ?? '';
            $nom    = $vals['nom'] ?? '';
            $org    = $vals['organisation'] ?? '';
            if ($prenom === '' && $nom === '' && $org === '') {
                // Numéro de ligne du fichier (1 = en-tête).
                $erreurs[] = ['ligne' => $i + 1, 'raison' => 'ni prenom, ni nom, ni organisation'];
                continue;
            }

            $contact = [
                'prenom'       => $prenom,
                'nom'          => $nom,
                'organisation' => $org !== '' ? $org : null,
                'fonction'     => ($vals['fonction'] ?? '') !== '' ? $vals['fonction'] : null,
                'notes'        => ($vals['notes'] ?? '') !== '' ? $vals['notes'] : null,
                'anniversaire' => ($vals['anniversaire'] ?? '') !== '' ? $vals['anniversaire'] : null,
                'courriels'    => [],
                'telephones'   => [],
                'adresses'     => [],
                'sites'        => [],
                'reseaux'      => [],
                'categories'   => [],
            ];

            if (($vals['courriel'] ?? '') !== '') {
                $contact['courriels'][] = ['type' => 'autre', 'valeur' => $vals['courriel']];
            }
            if (($vals['telephone'] ?? '') !== '') {
                $contact['telephones'][] = ['type' => 'autre', 'valeur' => $vals['telephone']];
            }
            if (($vals['site'] ?? '') !== '') {
                $contact['sites'][] = ['url' => $vals['site']];
            }

            $adresse = array_filter([
                'ligne1'      => $vals['adresse'] ?? '',
                'ville'       => $vals['ville'] ?? '',
                'code_postal' => $vals['code_postal'] ?? '',
                'pays'        => $vals['pays'] ?? '',
            ], fn($v) => $v !== '');
            if (!empty($adresse)) {
                $contact['adresses'][] = $adresse;
            }

            if (($vals['categories'] ?? '') !== '') {
                foreach (preg_split('/[;,|]/', $vals['categories']) ?: [] as $cat) {
                    $cat = trim($cat);
                    if ($cat !== '') { $contact['categories'][] = $cat; }
                }
            }

            $lignes[] = $contact;
        }

        return ['lignes' => $lignes, 'erreurs' => $erreurs];
    }
}
