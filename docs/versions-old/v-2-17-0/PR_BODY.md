# Release v2.17.0

## Summary

- Suivi du temps par tâche — sessions start/stop (table `time_sessions`), un seul minuteur actif
  à la fois par usager (contrainte posée en base, colonne générée + index `UNIQUE`), `note`
  chiffrable de bout en bout comme `title`/`description` des VTODO. Aucun gating `tenant_modules`.
  Directive `20260814_143000_cmem_web_vers_cmem2_API__time-tracking-sessions` (D3).
- `GET /freebusy?members=&start=&end=&app_id=cmemweb` — free/busy multi-membres d'un groupe.
- `POST /projets/projects/{id}/import.json` (dry-run) — `aMettreAJour[]` expose un diff champ par
  champ par tâche.

Détails complets : `CHANGELOG.md` ([2.17.0]).

Note : le commit de release (`2c0855d`) a été poussé directement sur `main` avant l'ouverture de
cette PR — ce document formalise rétroactivement la release, comme pour v2.16.1
(`docs/v-2-16-1/PR_BODY.md`). Cette PR ne contient donc que ce fichier ; le code est déjà sur
`main`.

## Test plan

- [x] `private/tests/test_time_sessions.php` — 52/52 (dev-cmem2)
- [x] Suite complète — 3053/3061 (dev-cmem2), 8 échecs préexistants sans lien (limite
      `If-Unmodified-Since` à la seconde, test Stripe idempotence marqué attendu en échec)
- [x] Déployé et vérifié sur dev-cmem2 et prod (cmem2.journauxdebord.com)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
