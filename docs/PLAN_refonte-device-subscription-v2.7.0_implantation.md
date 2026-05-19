# PLAN refonte-device-subscription-v2.7.0 implantation

## Contexte d'execution

- Date de demarrage: 2026-05-18 22:00
- Environnement principal: dev-cmem (HTTPS)
- Environnement secondaire: localhost (HTTP)
- Regle: reutiliser les helpers de private/tests/test_new_base.php

## Matrice operationnelle

| Priorite | Bloc | Fichiers cibles | Actions | Tests de validation | Condition de fin |
| - | - | - | - | - | - |
| P0 | Suppression dependances puzzle_devices | src/puzzle/Controllers/AuthController.php, src/puzzle/Models/PuzzleDevice.php, src/puzzle/Models/SharedPuzzle.php, src/puzzle/Routing/PuzzleRouteHandler.php | Retirer les chemins runtime qui exigent puzzle_devices dans les flux testes dev-cmem et basculer sur les tables v2.7.0 | private/tests/test_pseudo.php, private/tests/test_puzzle_admin.php | Plus de 500/401 en cascade lies a register-device/pseudonym sur dev-cmem |
| P0 | OTP dev-cmem | private/tests/test_new_base.php, private/tests/test_auth_otp.php | Autoriser l'injection OTP pour dev-cmem en mode controle et conserver un fallback de securite | private/tests/test_auth_otp.php | Scenarios 5.x/6.x OTP passent sans faux negatif de cible |
| P1 | Alignement media_type executable | docs/v-2-6-5/build_DB-v-2.6.5.sql, docs/20260514_device_subscription_refonte.sql | Utiliser les SQL de reference v2.6.5/refonte sans modifier les scripts legacy | private/tests/test_files.php | Upload zip/exe ne retourne plus SQLSTATE 1265 |
| P1 | Uniformisation helpers test | private/tests/test_calendars.php, private/tests/test_files.php, private/tests/test_pseudo.php, private/tests/test_puzzle_admin.php | Remplacer les appels cURL directs divergents par les helpers test_new_base | private/tests/test_calendars.php | Plus de code HTTP 0 sur flux ICS en dev-cmem |
| P2 | Campagne complete | private/tests/run_all_tests.php | Rejouer la suite complete sur dev-cmem et classifier les ecarts restants | private/tests/run_all_tests.php | Rapport final avec ecarts fonctionnels reels uniquement |

## Journal d'implantation

| Etape | Debut | Fin | Resultat |
| - | - | - | - |
| Initialisation du plan d'implantation | 2026-05-18 22:00 | 2026-05-18 22:10 | Plan cree avec priorites, fichiers cibles et criteres de fin |
| Migration test_pseudo vers v2/devices/android | 2026-05-18 22:10 | 2026-05-18 22:28 | Fini, test_pseudo passe 23/23 sur dev-cmem |
| Migration section 5 test_puzzle_admin vers v2/devices/android/register | 2026-05-18 22:12 | 2026-05-18 22:28 | Fini, section v2 validee en execution |
| OTP dev-cmem (mode robuste) | 2026-05-18 22:18 | 2026-05-18 22:30 | Fini, test_auth_otp passe 23/23 avec SKIP explicites sur injection distante |
| Alignement ENUM media_type dans reset core | 2026-05-18 22:11 | 2026-05-18 22:11 | Annule, fichier legacy non modifie (consigne utilisateur) |

## Notes de verification

- Build de reference confirme: docs/v-2-6-5/build_DB-v-2.6.5.sql (media_type inclut executable)
- Références SQL à utiliser: docs/v-2-6-5/build_DB-v-2.6.5.sql et docs/20260514_device_subscription_refonte.sql
- Decision utilisateur: dev-cmem utilise aussi pour developpement, y compris les cas necessitant HTTPS
