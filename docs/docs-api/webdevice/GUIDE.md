# Guide — Module WebDevice

Version 1.1.0 · Base URL : `/v2/devices/{android|web|windows}`

> Référence complète : [API_WEBDEVICE_ENDPOINTS.json](API_WEBDEVICE_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Enregistrement d'appareil](#enregistrement-dappareil)
- [Pseudonyme](#pseudonyme)
- [Modèle d'accès premium](#modèle-daccès-premium)
- [Erreurs](#erreurs)

---

## Vue d'ensemble

Le module WebDevice enregistre les appareils **Android** (table `android_devices`), **web et
Windows** (table `web_devices`). Multi-app via `app_id`. Introduit en v2.7.0 ; les routes
Android viennent de l'ancien module Play Store, retiré avec les abonnements.

> Les routes `/v2/devices/windows/*` sont strictement identiques aux routes
> `/v2/devices/web/*` — les deux plateformes partagent la table `web_devices`.
> Les routes `/v2/devices/android/*` ont le même contrat, sur la table `android_devices`.

Le `device_token` obtenu sert d'`Authorization: Bearer` pour les routes `/v2/puzzle/*`.

---

## Authentification

| Route | Auth |
| --- | --- |
| `POST /v2/devices/{plateforme}/register` | JWT **optionnel** — anonyme si absent, lié au compte si présent |
| Routes `/pseudonym` | JWT **requis** |

JWT obtenu via `POST /auth/login` (voir [../core/GUIDE.md](../core/GUIDE.md)).

---

## Enregistrement d'appareil

### POST /v2/devices/{android|web|windows}/register

Enregistre ou renouvelle un appareil. Upsert sur `(app_id, device_uuid)` — un nouveau
`device_token` (64 hex, 365 jours) est généré à chaque appel.

```http
POST /v2/devices/web/register
Content-Type: application/json

{
  "app_id": "puzzle",
  "device_uuid": "550e8400-e29b-41d4-a716-446655440000"
}
```

| Champ | Type | Description |
| --- | --- | --- |
| `app_id` | string, requis | Identifiant de l'application (ex. `puzzle`) |
| `device_uuid` | string, requis | UUID v4 stable généré à l'installation, jamais changé |

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { device_token: "…64 hex", expires_at: "…", pseudonym: "…"\|null } }` |
| 422 | `app_id` manquant \| `device_uuid` manquant ou format UUID v4 invalide |

> Appeler au premier démarrage et si `401` sur un endpoint protégé par `device_token`
> (renouvellement avec le même `device_uuid`). Stocker le `device_token` localement.
> Sur Android, réutiliser le **même** `device_uuid` après une réinstallation.

---

## Pseudonyme

Le pseudonyme est **unique par `app_id`** sur tout le serveur et **partagé entre plateformes**
(Android, web, Windows). JWT requis sur toutes ces routes ; `{plateforme}` vaut `android`,
`web` ou `windows`.

| Méthode | Route | Usage |
| --- | --- | --- |
| GET | `/v2/devices/{plateforme}/pseudonym?app_id={app}` | Lire le pseudonyme courant |
| POST | `/v2/devices/{plateforme}/pseudonym` | Définir ou remplacer |
| DELETE | `/v2/devices/{plateforme}/pseudonym` | Supprimer (met à NULL) |
| GET | `/v2/devices/{plateforme}/pseudonym/check/{pseudo}?app_id={app}` | Vérifier la disponibilité |

### POST /v2/devices/{plateforme}/pseudonym

```json
{
  "app_id": "puzzle",
  "pseudonym": "JoueurDuDimanche"
}
```

Contraintes : 2 à 64 caractères, lettres/chiffres/`_`/`.`/`-` uniquement.

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { pseudonym: "JoueurDuDimanche" } }` |
| 401 | JWT absent ou invalide |
| 409 | Pseudonyme déjà pris par un autre utilisateur pour ce `app_id` |
| 422 | Champ manquant, trop court/long ou caractères invalides |

### GET /v2/devices/{plateforme}/pseudonym/check/{pseudo}

Retourne `{ data: { available: true } }` si le pseudonyme est libre **ou** s'il appartient
déjà à l'utilisateur courant.

---

## Modèle d'accès premium

Plus d'abonnement (Stripe et Google Play retirés) : un appareil lié à un compte **approuvé**
a accès aux fonctions premium, sur toutes les plateformes.

| Besoin | Endpoint |
| --- | --- |
| Vérifier l'accès | `GET /v2/access/status?app_id={app}` (JWT) — voir [../access/GUIDE.md](../access/GUIDE.md) |

---

## Erreurs

| Code | Signification |
| --- | --- |
| 401 | JWT absent ou invalide |
| 409 | Pseudonyme déjà utilisé |
| 422 | Validation échouée (`app_id`, `device_uuid`, `pseudonym`) |
