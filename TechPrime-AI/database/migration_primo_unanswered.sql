-- Primo: messages the intent model could not understand (PostgreSQL / Supabase).
-- Run by hand in the Supabase SQL editor after the group approves. Safe to run twice.
--
-- What is stored, and what is not (Data Privacy Act of 2012, RA 10173: collect only what is needed):
--   * the message text, with e-mail addresses and phone/long numbers replaced before saving
--   * the model's best guess and its confidence, and why it was logged
--   * NO user id, name, session, IP address or page; rows cannot be linked back to a shopper
--   * rows older than 90 days are deleted automatically (backend/api/primo_chat.php)
--
-- Used to find real questions Primo misses; reviewed rows become training examples
-- (scripts/primo_export_unanswered.php -> ml/primo/add_reviewed.py -> train_model.py).

CREATE TABLE IF NOT EXISTS primo_unanswered (
    id           SERIAL PRIMARY KEY,
    message      VARCHAR(400) NOT NULL,          -- same limit as the chat box
    raw_intent   VARCHAR(40)  NOT NULL,          -- model's best guess before the threshold
    confidence   REAL         NOT NULL CHECK (confidence >= 0 AND confidence <= 1),
    reason       VARCHAR(20)  NOT NULL CHECK (reason IN ('off_topic', 'low_confidence')),
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP   -- UTC, like the other tables
);

CREATE INDEX IF NOT EXISTS idx_primo_unanswered_created ON primo_unanswered (created_at);
