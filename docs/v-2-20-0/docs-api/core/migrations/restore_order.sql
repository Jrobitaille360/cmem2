-- Ordre de restauration des donnees (auth_groups)
-- Source: create_proc_reset_auth_groups.sql
-- Utilisation: coller vos INSERT/LOAD DATA dans chaque section ci-dessous.

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Plans (referenced by users, api_keys, user_plan_history)
-- INSERT INTO plans ...;

-- 2) Utilisateurs
-- INSERT INTO users ...;

-- 3) Tags (tag_owner -> users)
-- INSERT INTO tags ...;

-- 4) Groupes (owner_id -> users)
-- INSERT INTO groups ...;

-- 5) Fichiers (uploaded_by -> users)
-- INSERT INTO files ...;

-- 6) API keys (user_id -> users, plan_id -> plans)
-- INSERT INTO api_keys ...;

-- 7) Sessions (user_id -> users, api_key_id -> api_keys)
-- INSERT INTO user_sessions ...;

-- 8) Donnees utilisateur (FK -> users)
-- INSERT INTO user_app_setup ...;
-- INSERT INTO login_codes ...;
-- INSERT INTO password_resets ...;
-- INSERT INTO email_verifications ...;
-- INSERT INTO notifications ...;

-- 9) Relations de groupes (FK -> groups, users)
-- INSERT INTO group_members ...;
-- INSERT INTO group_invitations ...;

-- 10) Relations tags (FK -> groups/tags, files/tags)
-- INSERT INTO group_tag_relations ...;
-- INSERT INTO file_tag_relations ...;

-- 11) Historique et invitations de plans (FK -> users, plans)
-- INSERT INTO user_plan_history ...;
-- INSERT INTO plan_invitations ...;

-- 12) Snapshots / statistiques
-- INSERT INTO group_stats_snapshot ...;
-- INSERT INTO user_stats_snapshot ...;
-- INSERT INTO platform_stats ...;

SET FOREIGN_KEY_CHECKS = 1;

-- 13) Vues (a recreer apres restauration)
-- CREATE OR REPLACE VIEW v_active_users ...;
-- CREATE OR REPLACE VIEW group_statistics ...;
-- CREATE OR REPLACE VIEW v_group_dashboard ...;
-- CREATE OR REPLACE VIEW v_admin_dashboard ...;
-- CREATE OR REPLACE VIEW active_api_keys ...;
-- CREATE OR REPLACE VIEW api_keys_stats_by_user ...;
-- CREATE OR REPLACE VIEW active_user_sessions ...;
-- CREATE OR REPLACE VIEW user_sessions_stats ...;
