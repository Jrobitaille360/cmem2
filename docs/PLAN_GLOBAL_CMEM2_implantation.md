# Audit de la gestion actuelle des JWT — cmem2_API

## 1. Blacklist des JWT

- **Présent** : Table dédiée, invalidation immédiate à la déconnexion ou changement critique.
- **À améliorer** : Purge automatique des tokens expirés (cron/script à prévoir), monitoring de la blacklist (alerte si anomalie).

## 2. Refresh Token & Device Token

- **Présent** : Rotation du device token lors du refresh, empêche le vol de session persistante.
- **À renforcer** : Support du refresh token rotatif (chaînage, invalidation automatique des anciens refresh), journalisation des tentatives d’utilisation de refresh invalides.

## 3. Algorithme & Stockage de la clé secrète

- **Présent** : HS256 avec clé forte, stockée dans .env.
- **À surveiller** : Rotation annuelle de la clé recommandée, vérification régulière de la robustesse, jamais de commit de la clé dans le code source.

## 4. Sécurité des flux JWT

- **Présent** : Vérification stricte de la signature et du scope à chaque requête, expiration courte (15j max, configurable).
- **À compléter** : Monitoring du taux d’échec d’authentification, alertes sur tentatives d’accès avec JWT invalide/expiré.

## 5. Documentation & Tests

- **Documentation partielle** des flux d’authentification et des cas d’erreur.
- **À compléter** : Doc exhaustive des flux, cas d’erreur, endpoints de gestion de session, tests automatisés de sécurité (fuzzing, injection, replay).

## 6. Points de vigilance

- S’assurer que la blacklist est bien consultée à chaque requête authentifiée.
- Vérifier que la clé secrète n’est jamais exposée (logs, erreurs, etc.).
- Prévoir un endpoint pour lister/révoquer toutes les sessions d’un utilisateur.

## 7. Recommandations immédiates

- Mettre en place la purge automatique de la blacklist.
- Ajouter la journalisation des accès refusés (JWT invalide/expiré).
- Documenter et tester tous les flux critiques.
- Préparer la rotation de la clé secrète.

---

*Ce document sera complété avec les étapes d’implantation détaillées après validation de l’audit.*
