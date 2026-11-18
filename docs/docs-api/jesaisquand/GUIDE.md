# Guide — Plugin jesaisquand

Version 1.0.0 · Base URL : `/jesaisquand`

> Référence complète : [API_JESAISQUAND_ENDPOINTS.json](API_JESAISQUAND_ENDPOINTS.json)

## Vue d'ensemble

« Je sais quand » est un jeu de frise chronologique (PWA `c:/code/jesaisquand`). Le plugin
fournit :

- les **paquets** de cartes, avec leurs dates, pour le mode solo ;
- les **parties multijoueur au tour par tour** : le serveur fait autorité et ne révèle la date
  d'une carte qu'au moment où elle est posée ;
- l'**administration** des paquets : import JSON, illustrations et crédits (journalisés).

## Authentification

| Contexte | Mécanisme | Header |
| - | - | - |
| Paquets publics, créer ou rejoindre un salon | Aucune (invité) | — |
| Rattacher un joueur à son compte (facultatif) | JWT | `Authorization: Bearer <jwt>` |
| Démarrer, état, coups | Jeton joueur | `Authorization: Bearer <jeton>` |
| Import, édition des cartes | JWT `ADMINISTRATEUR` / `SUPERADMINISTRATEUR` | `Authorization: Bearer <jwt>` |

Le jeton joueur (64 caractères hexadécimaux) n'est renvoyé qu'une fois, à la création ou à
l'arrivée. Le serveur n'en garde que l'empreinte SHA-256 : le client doit le conserver
(stockage local) pour reprendre la partie.

## Flux multijoueur

1. Le créateur appelle `POST /jesaisquand/parties` avec `{paquet, pseudo, taille_main?}` et
   reçoit `{code, jeton}`. Il partage le code de 6 caractères.
2. Chaque invité appelle `POST /jesaisquand/parties/{code}/rejoindre` avec `{pseudo}`
   (6 joueurs maximum).
3. Le créateur appelle `POST /jesaisquand/parties/{code}/demarrer` (2 joueurs minimum).
4. Chaque client interroge `GET /jesaisquand/parties/{code}?depuis={version}` toutes les
   1 à 2 s. La réponse est `304` tant que rien ne change.
5. Le joueur dont c'est le tour (`tour_joueur_id`) appelle
   `POST /jesaisquand/parties/{code}/coups` avec `{carte_id, index}`.
6. Quand `statut` vaut `terminee`, les gagnants ont `gagnant: true`.

```txt
Frise : [1609, 1687, 1969]   Carte posée : 1687 à l'index 1 → correct (égalité acceptée)
Frise : [1609, 1687, 1969]   Carte posée : 1990 à l'index 1 → erreur, index_final = 3
```

## Règles

- Comparaison à la **précision commune** : année, puis mois, puis jour si les deux cartes les
  ont. `1969` et `1969-07-20` sont égales.
- Une pose à l'index `i` est valide si `gauche <= carte <= droite` (égalités acceptées).
- Erreur : la carte est placée après les égalités existantes (`index_final`) et le joueur
  pioche une carte de pénalité, si la pioche n'est pas vide.
- Fin : le premier joueur à main vide gagne. Le tour de table est complété, et tous les joueurs
  qui vident leur main dans ce tour sont gagnants ex æquo.
- Pas de délai par tour. Une partie sans activité depuis 24 h passe à `expiree` (les actions
  renvoient `410`). Les parties inactives depuis 7 jours sont purgées.

## Ce qui n'est jamais exposé

Dans `moi.main` et `penalite_carte`, une carte n'a que `id`, `titre`, `categorie` et
l'illustration (`image`, `credit`, `imageSource`). Les champs `date`, `dateTexte`, `periode`,
`approx`, `essentiel` et `description` n'apparaissent qu'une fois la carte sur la frise.
Les mains adverses ne sont données que par leur taille.

> `GET /jesaisquand/paquets/{slug}` renvoie toutes les dates du paquet pour le mode solo.
> Un client multijoueur malveillant peut donc retrouver les dates de sa main hors partie : le
> serveur garantit l'arbitrage des coups, pas l'ignorance du joueur.

## Administration

### Importer un paquet

`POST /jesaisquand/admin/paquets` reçoit un fichier `src/data/*.json` de jesaisquand tel quel
(`id`, `titre`, `description`, `source`, `cartes[]`), plus `public` (défaut `true`). Les
cartes sont rapprochées par `id` : un nouvel import met à jour les cartes existantes et ajoute
les nouvelles, sans en supprimer.

### Illustration et crédit d'une carte

```txt
PATCH /jesaisquand/paquets/histoire-quebec/cartes/histoire-quebec-012
{"image_url":"https://upload.wikimedia.org/wikipedia/commons/a/ab/X.jpg",
 "credit":"Auteur — CC BY-SA 4.0, Wikimedia Commons"}
→ 200 carte mise à jour
```

- L'image est soit `image_url` (https, hôte exact `upload.wikimedia.org` ou
  `images-assets.nasa.gov`), soit `image_file_id` (fichier cmem2 image `grand-public`).
- Le `credit` est obligatoire.
- Chaque modification ajoute une ligne à `jsq_cartes_journal` : auteur, date, ancienne et
  nouvelle valeur.

`GET /jesaisquand/admin/cartes-sans-credit?paquet={slug}` liste les cartes illustrées sans
crédit.

## Erreurs

| Code | Cas |
| - | - |
| 401 | Jeton joueur ou JWT absent ; JWT invalide |
| 403 | Jeton d'une autre partie ; pas votre tour ; démarrage par un non-créateur ; rôle insuffisant |
| 404 | Paquet, carte ou partie introuvable |
| 409 | Partie déjà démarrée, non démarrée ou terminée ; salon complet |
| 410 | Partie expirée |
| 422 | Données invalides ; carte absente de la main ; index hors frise ; paquet trop petit |

## Tables

`jsq_paquets`, `jsq_cartes`, `jsq_cartes_journal`, `jsq_parties`, `jsq_joueurs`, `jsq_coups`
(migration `docs/20261118_jesaisquand.sql`).

## Hors périmètre v1

Classement et statistiques par compte (les coups sont journalisés dans `jsq_coups` pour les
calculer plus tard), délai par tour, départage par manche supplémentaire.
