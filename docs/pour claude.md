---
Decription du fichier ici
---
# Consignes pour Claude AI pour memory

MODE  --allow-dangerously-skip-permissions

## Lorsque je demande de mettre à jour changelog.md

- Relire les session depuis derniers changelog
- Résumé des changements
- Au besoin :
  - Mettre à jours docs :
    - Fichiers guide
    - Fichiers entrypoint json
- Au changement de version pour x.x.x
  - Créer dossier /docs/v x.x.x
  - Créer x.x.x_CLIENT.md
  - Créer x.x.x_PRODUCTION
  - Placer les fichier sql de migration vers la version dans le /docs/v x.x.x
  - Mettre à jour docs\build_cmem2_DB.sql
  - Revoir la stratégie de cron backups
- git add .
- git commit avec résumer du changelog
- git push

## Lors de la création ou de la mise à jour de fichier *.md

- Respecter toutes les règles DavidAnson.vscode-markdownlint
- Pour MD060 : le règle est "un et un seul espace avant et après | sauf en début et en fin de ligne. Exemple :

| Un court titre | x |
| - | - |
| Texte encore plus long | xxxxx |
