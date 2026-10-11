-- PostgreSQL. Unread tracking for staff chat (includes/staff_chat_messages.php feature-detects this column).
-- Existing messages are marked read (added with DEFAULT 1); new messages default to unread.
BEGIN;
ALTER TABLE public.messages ADD COLUMN IF NOT EXISTS is_read SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE public.messages ALTER COLUMN is_read SET DEFAULT 0;
COMMIT;
