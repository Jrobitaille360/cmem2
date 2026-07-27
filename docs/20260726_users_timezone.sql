-- Migration pendante : fuseau horaire de l'usager (`users.timezone`).
-- Plan docs/PLAN_timezone-usager.md — volet B de la directive 20260726_161400.
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Colonne NULLABLE, sans valeur par défaut : NULL signifie « jamais renseigné » et laisse
-- le repli en place (fuseau du premier calendrier de l'usager, sinon America/Montreal).
-- Une valeur par défaut posée sur toutes les lignes existantes ferait basculer en heure de
-- Montréal les usagers qui ont aujourd'hui un calendrier dans un autre fuseau.
--
-- Le fuseau sert au cron push : échéances sans heure (00:00 local) et plage « ne pas
-- déranger » (quiet_from / quiet_to de notification_prefs).

ALTER TABLE users
    ADD COLUMN timezone VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Identifiant IANA (ex. Europe/Paris) posé par le client ; NULL = repli calendrier'
        AFTER location;
