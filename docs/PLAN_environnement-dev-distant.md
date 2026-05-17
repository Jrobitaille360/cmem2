# Environnement de développement distant — cmem2_API

## Pourquoi un serveur dev distant

Certaines intégrations exigent une URL publique HTTPS inaccessible depuis `localhost` :

- **Stripe webhooks** : Stripe livre les événements sur un endpoint public.
- **Play Store Sandbox** : l'appli Android doit joindre une URL accessible.
- **Tout tiers à callback** : même contrainte.

Le serveur dev est l'environnement de développement actif de cmem2_API.

---

## Infrastructure

| Élément | Valeur |
| - | - |
| Domaine | `dev-cmem2.journauxdebord.com` |
| Base de données | `lmdkhdg5_dev_cmem2` |
| DB user / password | Identiques à la production (dans `.env` uniquement — jamais versionné) |
| Serveur | Même hébergeur que production, compte séparé |
| `.env` | Clés Sandbox uniquement (Stripe test mode, Google Play Sandbox) |

Le serveur de production (`cmem2.journauxdebord.com`, DB `cmem2`) n'est jamais
touché hors release officielle.

---

## Source de vérité

**Git est la source de vérité — pas le filesystem du serveur.**

Règle absolue : tout changement transite par git avant d'atterrir sur le serveur dev.
Aucune édition directe sur le serveur sans commit préalable.

```
[local]  →  git push  →  [dev server] git pull
```

---

## Workflow quotidien

```bash
# Local : éditer, tester unitairement, committer
git add .
git commit -m "..."
git push origin <branche>

# Serveur dev (SSH)
git pull origin <branche>
```

---

## VSCode

| Besoin | Approche |
| - | - |
| Édition code | Local, push git, pull sur serveur |
| Inspection live serveur | VSCode Remote-SSH dans fenêtre séparée |
| Docs | Locales, dans le repo, git comme pont |

---

## Backup automatique (cron serveur dev)

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M)
mysqldump lmdkhdg5_dev_cmem2 | gzip > /backup/dev_cmem2_${DATE}.sql.gz
find /backup -name "dev_cmem2_*.sql.gz" -mtime +14 -delete
```

Code sauvegardé par git — aucun backup filesystem nécessaire.

---

## Sécurité

- `.env` du serveur dev contient uniquement des clés **Sandbox / test** — jamais prod
- Accès SSH par clé uniquement
- DB dev isolée de prod — aucun `GRANT` croisé
- Aucune donnée utilisateur réelle sur dev

---

## Quand utiliser `localhost`

`localhost` reste valide pour :

- Développement de logique pure sans appel tiers (modèles, services)
- Tests unitaires PHP sans webhook ni SDK mobile
- Migrations SQL avant déploiement sur dev

Dès qu'un tiers est impliqué (Stripe, Google Play, appli mobile) → serveur dev.
