-- Additive, non-destructive migration.
--
-- Adds a `status` column to `scores` so a criteria a judge explicitly
-- marked "Abai" (skip) can be told apart from a criteria nobody has
-- touched yet — today both look identical (no row in `scores` at all).
--
-- Safety notes:
--   - ADD COLUMN ... DEFAULT 'scored' backfills every EXISTING row with
--     'scored' automatically. That is correct and intentional: every row
--     that already exists in `scores` today IS a real submitted mark (the
--     app never wrote an empty/placeholder row before this change), so
--     'scored' accurately describes 100% of current data.
--   - No existing `mark`, `student_id`, `criteria_id`, or `group_id`
--     value is read, rewritten, or touched by this statement.
--   - Nothing is deleted. Nothing is renamed. This only adds a column.
--   - Rollback, if ever needed, is equally additive-safe:
--       ALTER TABLE `scores` DROP COLUMN `status`;
--
-- This does NOT retroactively distinguish old "judge forgot" gaps from old
-- "judge intentionally skipped" gaps — that distinction was never captured
-- before now, so it can't be reconstructed. It only prevents the ambiguity
-- from accumulating further, starting from the first judge submission
-- after this ships.

ALTER TABLE `scores`
  ADD COLUMN `status` ENUM('scored','abaikan') NOT NULL DEFAULT 'scored' AFTER `mark`;
