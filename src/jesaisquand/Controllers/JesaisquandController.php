<?php

namespace Jesaisquand\Controllers;

use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;
use Jesaisquand\Models\Paquet;
use Jesaisquand\Services\CarteFormat;
use Jesaisquand\Services\JsqException;
use Jesaisquand\Services\PartieService;

/**
 * Contrôleur du plugin jesaisquand — paquets (#524), parties (#524), édition admin (#526).
 * L'authentification (JWT, jeton joueur, rôle) est résolue par JesaisquandRouteHandler.
 */
class JesaisquandController
{
    /** Hôtes autorisés pour image_url (#526). Correspondance exacte, https seulement. */
    public const HOTES_IMAGES = ['upload.wikimedia.org', 'images-assets.nasa.gov'];

    private Paquet $paquets;
    private PartieService $parties;

    public function __construct()
    {
        $this->paquets = new Paquet();
        $this->parties = new PartieService($this->paquets);
    }

    /** Exécute $fn en traduisant les JsqException en réponses d'erreur. */
    public function run(callable $fn): void
    {
        try {
            $fn();
        } catch (JsqException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->http());
        } catch (\Throwable $e) {
            LogService::error('jesaisquand : erreur serveur', ['error' => $e->getMessage(), 'line' => $e->getLine()]);
            Response::error('Erreur serveur', null, 500);
        }
    }

    // ------------------------------------------------------------------
    // Paquets
    // ------------------------------------------------------------------

    public function listePaquets(): void
    {
        Response::success('Paquets publics', ['paquets' => $this->paquets->listPublics()]);
    }

    public function paquet(string $slug, ?array $user): void
    {
        $p = $this->paquets->findBySlug($slug);
        if (!$p || ((int) $p['public'] !== 1 && !self::estAdmin($user))) {
            throw new JsqException('Paquet introuvable', 404);
        }
        Response::success('Paquet', [
            'id'          => $p['slug'],
            'slug'        => $p['slug'],
            'titre'       => $p['titre'],
            'description' => $p['description'],
            'source'      => $p['source'],
            'public'      => (bool) $p['public'],
            'cartes'      => array_map([CarteFormat::class, 'revelee'], $this->paquets->cartesDuPaquet((int) $p['id'])),
        ]);
    }

    // ------------------------------------------------------------------
    // Parties
    // ------------------------------------------------------------------

    public function creerPartie(?array $user): void
    {
        $in     = Response::getRequestParams();
        $pseudo = self::pseudo($in['pseudo'] ?? null);
        $slug   = is_string($in['paquet'] ?? null) ? trim($in['paquet']) : '';
        $taille = $in['taille_main'] ?? PartieService::TAILLE_MAIN_DEF;
        $errors = [];
        if ($slug === '') $errors['paquet'] = 'Slug du paquet requis';
        if ($pseudo === null) $errors['pseudo'] = 'Pseudo requis (1 à 40 caractères)';
        if (filter_var($taille, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PartieService::TAILLE_MAIN_MAX]]) === false) {
            $errors['taille_main'] = 'Entier entre 1 et ' . PartieService::TAILLE_MAIN_MAX;
        }
        if ($errors) {
            throw new JsqException('Données invalides', 422, $errors);
        }
        Response::success('Salon créé', $this->parties->creer($slug, $pseudo, (int) $taille, $user['user_id'] ?? null), 201);
    }

    public function rejoindre(string $code, ?array $user): void
    {
        $pseudo = self::pseudo(Response::getRequestParams()['pseudo'] ?? null);
        if ($pseudo === null) {
            throw new JsqException('Données invalides', 422, ['pseudo' => 'Pseudo requis (1 à 40 caractères)']);
        }
        Response::success('Salon rejoint', $this->parties->rejoindre($code, $pseudo, $user['user_id'] ?? null), 201);
    }

    public function demarrer(array $partie, array $joueur): void
    {
        Response::success('Partie démarrée', $this->parties->demarrer($partie, $joueur));
    }

    public function etat(array $partie, array $joueur): void
    {
        $depuis = $_GET['depuis'] ?? null;
        if ($depuis !== null && ctype_digit((string) $depuis) && (int) $depuis === (int) $partie['version']) {
            http_response_code(304);
            exit;
        }
        Response::success('État de la partie', $this->parties->etat($partie, $joueur));
    }

    public function jouer(array $partie, array $joueur): void
    {
        $in      = Response::getRequestParams();
        $carteId = is_string($in['carte_id'] ?? null) ? trim($in['carte_id']) : '';
        $index   = filter_var($in['index'] ?? null, FILTER_VALIDATE_INT);
        $errors  = [];
        if ($carteId === '') $errors['carte_id'] = 'carte_id requis';
        if ($index === false) $errors['index'] = 'index entier requis';
        if ($errors) {
            throw new JsqException('Données invalides', 422, $errors);
        }
        Response::success('Coup joué', $this->parties->jouer($partie, $joueur, $carteId, $index));
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    /** POST /jesaisquand/admin/paquets — upsert d'un paquet au format JSON de jesaisquand. */
    public function importerPaquet(array $user): void
    {
        $in     = Response::getRequestParams();
        $errors = [];

        $slug = is_string($in['id'] ?? null) ? trim($in['id']) : (is_string($in['slug'] ?? null) ? trim($in['slug']) : '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) $errors['id'] = 'Slug requis : minuscules, chiffres et tirets (64 max)';
        $titre = self::texte($in['titre'] ?? null, 255);
        if ($titre === null) $errors['titre'] = 'Titre requis (255 max)';
        $cartesIn = $in['cartes'] ?? null;
        if (!is_array($cartesIn) || !$cartesIn || !array_is_list($cartesIn)) {
            $errors['cartes'] = 'Liste de cartes non vide requise';
            $cartesIn = [];
        } elseif (count($cartesIn) > 2000) {
            $errors['cartes'] = '2000 cartes maximum';
        }

        $cartes = [];
        $vus    = [];
        foreach ($cartesIn as $i => $c) {
            $e = self::validerCarteImport($c);
            if (is_string($e)) {
                $errors["cartes.{$i}"] = $e;
                continue;
            }
            if (isset($vus[$e['uid']])) {
                $errors["cartes.{$i}"] = "id en double : {$e['uid']}";
                continue;
            }
            $vus[$e['uid']] = true;
            $cartes[] = $e;
        }
        if ($errors) {
            throw new JsqException('Données invalides', 422, $errors);
        }

        $res = $this->paquets->importer([
            'slug'        => $slug,
            'titre'       => $titre,
            'description' => self::texte($in['description'] ?? null, 65000),
            'source'      => self::texte($in['source'] ?? null, 255),
            'public'      => array_key_exists('public', $in) ? (filter_var($in['public'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1,
            'cartes'      => $cartes,
        ], (int) $user['user_id']);

        LogService::info('jesaisquand : paquet importé', ['slug' => $slug, 'user_id' => $user['user_id']] + $res);
        Response::success('Paquet importé', ['slug' => $slug] + $res, $res['paquet_cree'] ? 201 : 200);
    }

    /** PATCH /jesaisquand/paquets/{slug}/cartes/{carte_id} — illustration + crédit (#526). */
    public function majCarte(string $slug, string $uid, array $user): void
    {
        $p = $this->paquets->findBySlug($slug);
        $carte = $p ? $this->paquets->findCarte((int) $p['id'], $uid) : null;
        if (!$carte) {
            throw new JsqException('Carte introuvable', 404);
        }

        $in     = Response::getRequestParams();
        $errors = [];

        $credit = self::texte($in['credit'] ?? null, 500);
        if ($credit === null) $errors['credit'] = 'Crédit obligatoire (500 caractères max)';

        $imageUrl = null;
        $fileId   = null;
        $hasUrl   = isset($in['image_url']) && $in['image_url'] !== '';
        $hasFile  = isset($in['image_file_id']) && $in['image_file_id'] !== '';
        if ($hasUrl === $hasFile) {
            $errors['image'] = 'Fournir image_url OU image_file_id';
        } elseif ($hasUrl) {
            $imageUrl = self::urlHttps($in['image_url'], self::HOTES_IMAGES);
            if ($imageUrl === null) {
                $errors['image_url'] = 'URL https sur ' . implode(' ou ', self::HOTES_IMAGES) . ' requise';
            }
        } else {
            $fileId = filter_var($in['image_file_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($fileId === false || !$this->paquets->fichierImagePublic($fileId)) {
                $errors['image_file_id'] = 'Fichier image cmem2 grand-public introuvable';
                $fileId = null;
            }
        }

        $source = null;
        if (isset($in['image_source']) && $in['image_source'] !== '') {
            $source = self::urlHttps($in['image_source']);
            if ($source === null) $errors['image_source'] = 'URL https requise (500 caractères max)';
        }
        if ($errors) {
            throw new JsqException('Données invalides', 422, $errors);
        }

        $maj = $this->paquets->majIllustration($carte, [
            'image_url'     => $imageUrl,
            'image_file_id' => $fileId,
            'image_source'  => $source,
            'credit'        => $credit,
        ], (int) $user['user_id']);

        Response::success('Carte mise à jour', ['paquet' => $slug, 'carte' => CarteFormat::revelee($maj)]);
    }

    /** GET /jesaisquand/admin/cartes-sans-credit?paquet={slug} (#526). */
    public function cartesSansCredit(): void
    {
        $slug     = isset($_GET['paquet']) && $_GET['paquet'] !== '' ? (string) $_GET['paquet'] : null;
        $paquetId = null;
        if ($slug !== null) {
            $p = $this->paquets->findBySlug($slug);
            if (!$p) {
                throw new JsqException('Paquet introuvable', 404);
            }
            $paquetId = (int) $p['id'];
        }
        $cartes = array_map(fn($r) => [
            'id'          => $r['uid'],
            'paquet'      => $r['paquet_slug'],
            'titre'       => $r['titre'],
            'image'       => CarteFormat::image($r),
            'imageSource' => $r['image_source'],
        ], $this->paquets->cartesSansCredit($paquetId));
        Response::success('Cartes illustrées sans crédit', ['cartes' => $cartes, 'total' => count($cartes)]);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public static function estAdmin(?array $user): bool
    {
        return in_array($user['role'] ?? null, ['ADMINISTRATEUR', 'SUPERADMINISTRATEUR'], true);
    }

    private static function pseudo($v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');
        return ($v === '' || mb_strlen($v) > 40) ? null : $v;
    }

    private static function texte($v, int $max): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        return ($v === '' || mb_strlen($v) > $max) ? null : $v;
    }

    /** URL https valide (500 max), optionnellement restreinte à des hôtes exacts. */
    public static function urlHttps($v, ?array $hotes = null): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        if ($v === '' || strlen($v) > 500 || filter_var($v, FILTER_VALIDATE_URL) === false) return null;
        $p = parse_url($v);
        if (strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) return null;
        if ($hotes !== null && !in_array(strtolower($p['host']), $hotes, true)) return null;
        return $v;
    }

    /** @return array|string carte normalisée, ou message d'erreur */
    private static function validerCarteImport($c)
    {
        if (!is_array($c)) return 'Carte invalide';
        $uid = is_string($c['id'] ?? null) ? trim($c['id']) : '';
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $uid)) return 'id requis (lettres, chiffres, . _ -, 100 max)';
        $titre = self::texte($c['titre'] ?? null, 255);
        if ($titre === null) return "titre requis ({$uid})";
        $d = $c['date'] ?? null;
        if (!is_array($d) || filter_var($d['annee'] ?? null, FILTER_VALIDATE_INT) === false) return "date.annee entière requise ({$uid})";
        $mois = $d['mois'] ?? null;
        $jour = $d['jour'] ?? null;
        if ($mois !== null && filter_var($mois, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) === false) return "date.mois 1-12 ({$uid})";
        if ($jour !== null && ($mois === null || filter_var($jour, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]) === false)) return "date.jour 1-31 avec mois ({$uid})";
        $image = $c['image'] ?? null;
        if ($image !== null && (!is_string($image) || strlen($image) > 500)) return "image : chaîne de 500 caractères max ({$uid})";

        return [
            'uid'          => $uid,
            'titre'        => $titre,
            'annee'        => (int) $d['annee'],
            'mois'         => $mois !== null ? (int) $mois : null,
            'jour'         => $jour !== null ? (int) $jour : null,
            'date_texte'   => self::texte($c['dateTexte'] ?? null, 100),
            'periode'      => self::texte($c['periode'] ?? null, 100),
            'approx'       => !empty($c['approx']) ? 1 : 0,
            'essentiel'    => !empty($c['essentiel']) ? 1 : 0,
            'categorie'    => self::texte($c['categorie'] ?? null, 100),
            'description'  => self::texte($c['description'] ?? null, 65000),
            'image_url'    => is_string($image) && trim($image) !== '' ? trim($image) : null,
            'image_source' => self::texte($c['imageSource'] ?? null, 500),
            'credit'       => self::texte($c['credit'] ?? null, 500),
        ];
    }
}
