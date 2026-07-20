-- FK manquantes sur calendar_journals/calendar_todos (user_id, calendar_id) — phase 7a RGPD.
-- Sans ces FK, un hard delete sur users/calendars laisse des lignes orphelines
-- (confirme en dev : 6 orphelins calendar_id sur calendar_journals, 3 sur calendar_todos).
-- Nettoyage prealable des orphelins existants (no-op si aucun, deja verifie prod=0).
DELETE j FROM calendar_journals j
LEFT JOIN calendars c ON j.calendar_id = c.id
WHERE c.id IS NULL;

DELETE t FROM calendar_todos t
LEFT JOIN calendars c ON t.calendar_id = c.id
WHERE c.id IS NULL;

-- calendars.id / users.id sont INT(11) signes (convention du schema) ; calendar_journals/todos
-- avaient calendar_id/user_id en INT UNSIGNED -- errno 150 (type mismatch) sans cet alignement.
ALTER TABLE calendar_journals
  MODIFY calendar_id INT(11) NOT NULL,
  MODIFY user_id INT(11) NOT NULL;

ALTER TABLE calendar_todos
  MODIFY calendar_id INT(11) NOT NULL,
  MODIFY user_id INT(11) NOT NULL;

ALTER TABLE calendar_journals
  ADD CONSTRAINT fk_calendar_journals_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_calendar_journals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE calendar_todos
  ADD CONSTRAINT fk_calendar_todos_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_calendar_todos_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
