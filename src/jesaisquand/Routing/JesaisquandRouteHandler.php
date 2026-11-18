<?php

namespace Jesaisquand\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Jesaisquand\Controllers\JesaisquandController;
use Jesaisquand\Services\PartieService;

/**
 * JesaisquandRouteHandler — routes /jesaisquand/*
 *
 * Auth conditionnelle (requiresAuth = false, chaque branche gère son niveau) :
 *  - GET  /jesaisquand/paquets                           → public
 *  - GET  /jesaisquand/paquets/{slug}                    → public (JWT admin pour un paquet privé)
 *  - POST /jesaisquand/parties                           → invité (JWT facultatif → user_id)
 *  - POST /jesaisquand/parties/{code}/rejoindre          → invité (JWT facultatif → user_id)
 *  - POST /jesaisquand/parties/{code}/demarrer           → jeton joueur (créateur)
 *  - GET  /jesaisquand/parties/{code}[?depuis=v]         → jeton joueur
 *  - POST /jesaisquand/parties/{code}/coups              → jeton joueur
 *  - POST /jesaisquand/admin/paquets                     → JWT ADMINISTRATEUR / SUPERADMINISTRATEUR
 *  - GET  /jesaisquand/admin/cartes-sans-credit          → JWT ADMINISTRATEUR / SUPERADMINISTRATEUR
 *  - PATCH /jesaisquand/paquets/{slug}/cartes/{carte_id} → JWT ADMINISTRATEUR / SUPERADMINISTRATEUR
 *
 * Le jeton joueur et le JWT passent tous deux par « Authorization: Bearer ». Un JWT contient
 * des points, un jeton joueur (64 hex) non : le JWT facultatif n'est lu que s'il en a l'air.
 */
class JesaisquandRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['jesaisquand'];
    }

    protected function handleRoute(array $request): void
    {
        $method = $request['method'] ?? 'GET';
        $seg    = array_map('rawurldecode', $request['segments'] ?? []);
        $s1     = $seg[1] ?? '';
        $s2     = $seg[2] ?? '';
        $s3     = $seg[3] ?? '';
        $s4     = $seg[4] ?? '';
        $c      = new JesaisquandController();

        $c->run(function () use ($c, $method, $s1, $s2, $s3, $s4) {
            // /jesaisquand/paquets[/{slug}[/cartes/{carte_id}]]
            if ($s1 === 'paquets') {
                if ($method === 'GET' && $s2 === '') {
                    $c->listePaquets();
                } elseif ($method === 'GET' && $s2 !== '' && $s3 === '') {
                    $c->paquet($s2, $this->jwtFacultatif());
                } elseif ($method === 'PATCH' && $s2 !== '' && $s3 === 'cartes' && $s4 !== '') {
                    $c->majCarte($s2, $s4, $this->requireAdmin());
                } else {
                    $this->introuvable();
                }
                return;
            }

            // /jesaisquand/parties[/{code}[/action]]
            if ($s1 === 'parties') {
                if ($s2 === '') {
                    $method === 'POST' ? $c->creerPartie($this->jwtFacultatif()) : $this->introuvable();
                    return;
                }
                if ($method === 'POST' && $s3 === 'rejoindre') {
                    $c->rejoindre($s2, $this->jwtFacultatif());
                    return;
                }
                $action = match (true) {
                    $method === 'GET'  && $s3 === ''         => 'etat',
                    $method === 'POST' && $s3 === 'demarrer'  => 'demarrer',
                    $method === 'POST' && $s3 === 'coups'     => 'jouer',
                    default                                   => null,
                };
                if ($action === null) {
                    $this->introuvable();
                    return;
                }
                $jeton = $this->bearer();
                if ($jeton === null) {
                    Response::error('Jeton joueur requis (Authorization: Bearer <jeton>)', null, 401);
                    return;
                }
                [$partie, $joueur] = (new PartieService())->authentifier($s2, $jeton);
                $c->$action($partie, $joueur);
                return;
            }

            // /jesaisquand/admin/*
            if ($s1 === 'admin') {
                if ($method === 'POST' && $s2 === 'paquets' && $s3 === '') {
                    $c->importerPaquet($this->requireAdmin());
                } elseif ($method === 'GET' && $s2 === 'cartes-sans-credit') {
                    $this->requireAdmin();
                    $c->cartesSansCredit();
                } else {
                    $this->introuvable();
                }
                return;
            }

            $this->introuvable();
        });
    }

    private function introuvable(): void
    {
        Response::error('Endpoint non trouvé', null, 404);
    }

    private function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($h, 'Bearer ')) {
            return null;
        }
        $t = trim(substr($h, 7));
        return $t === '' ? null : $t;
    }

    /** JWT lu seulement s'il est présent et en a la forme ; invalide → 401 (envoyé par le middleware). */
    private function jwtFacultatif(): ?array
    {
        $t = $this->bearer();
        if ($t === null || substr_count($t, '.') !== 2) {
            return null;
        }
        $user = $this->authService?->authenticate();
        if (!$user) {
            Response::error('Authentification requise', null, 401);
        }
        return $user;
    }

    private function requireAdmin(): array
    {
        $user = $this->bearer() !== null ? $this->authService?->authenticate() : null;
        if (!$user) {
            Response::error('Authentification requise', null, 401);
        }
        if (!JesaisquandController::estAdmin($user)) {
            Response::error('Réservé aux administrateurs', null, 403);
        }
        return $user;
    }
}
