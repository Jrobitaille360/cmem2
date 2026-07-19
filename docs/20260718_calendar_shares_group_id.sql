ALTER TABLE calendar_shares
  ADD COLUMN shared_with_group_id INT NULL AFTER shared_with_email,
  ADD CONSTRAINT fk_calendar_shares_group
    FOREIGN KEY (shared_with_group_id) REFERENCES `groups`(id) ON DELETE CASCADE;
