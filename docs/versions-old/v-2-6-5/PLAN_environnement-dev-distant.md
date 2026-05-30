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
| Serveur | Même hébergeur et même compte SSH (`lmdkhdg5`) que production — répertoire séparé |
| `.env` | Clés Sandbox uniquement (Stripe test mode, Google Play Sandbox) — source : `private/.env.dev.online` |

Le serveur de production (`cmem2.journauxdebord.com`, DB `cmem2`) n'est jamais
touché hors release officielle.

---

## Source de vérité

**Git est la source de vérité — pas le filesystem du serveur.**

Règle absolue : tout changement transite par git avant d'atterrir sur le serveur dev.
Aucune édition directe sur le serveur sans commit préalable.

```
[local]  →  git push  →  .\private\deploy.ps1 -Target dev.online  →  [serveur dev via SCP]
```

---

## Workflow quotidien

```powershell
# Local : éditer, tester unitairement, committer
git add .
git commit -m "..."
git push origin <branche>

# Déployer sur le serveur dev (SCP + composer)
.\private\deploy.ps1 -Target dev.online

# Déployer en production (confirmation requise)
.\private\deploy.ps1 -Target prod
```

Le script `deploy.ps1` :

1. Prépare le dossier distant (`mkdir -p`)
2. Upload le `.env` correspondant (`private/.env.<target>`)
3. Transfère les fichiers sources via SCP (`index.php`, `src/`, `composer.*`, etc.)
4. Exécute `composer install --no-dev --optimize-autoloader` via SSH
5. Injecte `APP_COMMIT` et `APP_DEPLOYED_AT` dans le `.env` distant

---

## VSCode

| Besoin | Approche |
| - | - |
| Édition code | Local, commit + push, puis `deploy.ps1` |
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
