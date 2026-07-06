# Index des docs JSON d'endpoints

Chaque module expose sa documentation d'API au format JSON. Ces fichiers décrivent les routes réellement implémentées dans `src/` (audit docs ↔ code : 2026-07-06).

| Fichier | Routes | Description |
| - | - | - |
| [core/API_ENDPOINTS.json](core/API_ENDPOINTS.json) | 89 | API cœur : auth JWT/OTP, users, groups, files, tags, stats, plans. Document de référence transmis aux clients. |
| [ics/API_ICS_ENDPOINTS.json](ics/API_ICS_ENDPOINTS.json) | 43 | Calendriers ICS/CalDAV : événements, occurrences, todos, journaux, partage, notifications email. |
| [items/API_ITEMS_ENDPOINTS.json](items/API_ITEMS_ENDPOINTS.json) | 13 | Gestionnaire d'items génériques — privé / public / partagé, catégories, permissions par utilisateur. |
| [quiz/API_QUIZ_ENDPOINTS_v1_0_0.json](quiz/API_QUIZ_ENDPOINTS_v1_0_0.json) | 17 | Quiz temps réel : CRUD quiz/questions, sessions animateur (JWT) et participants (participant_token). |
| [puzzle/API_PUZZLE_ENDPOINTS.json](puzzle/API_PUZZLE_ENDPOINTS.json) | 20 | Plugin puzzle : carrousel, thèmes, livraison d'images, backup en ligne, casse-têtes partagés (pick/drop). Auth device_token. |
| [puzzle/API_PUZZLE_ADMIN_MANAGER.json](puzzle/API_PUZZLE_ADMIN_MANAGER.json) | 16 | Administration puzzle pour le SPA React : CRUD images et thèmes, tri, associations, livraison images admin. JWT ADMINISTRATEUR. |
| [playstore/API_PLAYSTORE_ENDPOINTS.json](playstore/API_PLAYSTORE_ENDPOINTS.json) | 8 | Devices Android (register, pseudonyme) et abonnements Google Play (verify/status/cancel via X-Device-Token). |
| [webdevice/API_WEBDEVICE_ENDPOINTS.json](webdevice/API_WEBDEVICE_ENDPOINTS.json) | 5 (+5 alias) | Devices web et Windows (table `web_devices`) : register + pseudonyme. Routes `/v2/devices/windows/*` identiques en alias. |
| [stripe/API_STRIPE_ENDPOINTS.json](stripe/API_STRIPE_ENDPOINTS.json) | 5 (+6 legacy) | Abonnements Stripe web/Windows : checkout, portal, webhook, statut, annulation. Sections deprecated/removed pour le legacy `/subscription/*`. |
| [access/API_ACCESS_ENDPOINTS.json](access/API_ACCESS_ENDPOINTS.json) | 1 | `GET /v2/access/status` — statut d'accès consolidé (croisement Stripe + Play Store) par app_id. |
| [traque/API_TRAQUE_ENDPOINTS.json](traque/API_TRAQUE_ENDPOINTS.json) | 17 | Jeu Traque : monstres géolocalisés (OSM), combat, personnage (création, repos, level-up), bestiaire, leaderboard. |
| [pomo/API_POMO_ENDPOINTS_v1_0_0.json](pomo/API_POMO_ENDPOINTS_v1_0_0.json) | 1 | Plugin Pomodoro — phase 1A seulement : `POST /pomo/engagement` (waitlist/sondage public). Phases 1B/2/3 planifiées. |

## Conventions

- Format commun des entrées : `method`, `path`, `description`, `body`/`query_params`, `responses`.
- Routes legacy actives : section `deprecated_routes` avec `replaced_by`.
- Routes supprimées (404/410) : section `removed_routes`.
- Les routes `secret-admin` ne sont volontairement pas documentées.
- Guides narratifs par module : `docs/<module>/GUIDE.md` — disponibles pour core, ics, items, quiz, puzzle, playstore, webdevice, stripe, access, traque et pomo (audit guides ↔ JSON ↔ code : 2026-07-06).
