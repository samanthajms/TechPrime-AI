-- PostgreSQL. Staff chat: replies, forwards, unsend, attachments, reactions, "remove for you", pins.
-- Additive only. Used by includes/staff_chat_messages.php.
BEGIN;

ALTER TABLE public.messages
    ADD COLUMN IF NOT EXISTS reply_to_id     INTEGER NULL REFERENCES public.messages(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS is_forwarded    SMALLINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS deleted_for_all SMALLINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(80)  NULL,  -- random stored name under uploads/chat/
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(160) NULL,  -- sanitized original file name (display only)
    ADD COLUMN IF NOT EXISTS attachment_mime VARCHAR(120) NULL,  -- server-verified MIME type
    ADD COLUMN IF NOT EXISTS attachment_size INTEGER NULL;

CREATE INDEX IF NOT EXISTS messages_pair_idx ON public.messages (sender_id, receiver_id, id);

-- One reaction per person per message (changing it replaces the old one).
CREATE TABLE IF NOT EXISTS public.message_reactions (
    message_id INTEGER NOT NULL REFERENCES public.messages(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    emoji      VARCHAR(16) NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id)
);

-- "Remove for you": the message stays for the other person.
CREATE TABLE IF NOT EXISTS public.message_hidden (
    message_id INTEGER NOT NULL REFERENCES public.messages(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id)
);

-- Pins are shared by both people in the conversation.
CREATE TABLE IF NOT EXISTS public.message_pins (
    message_id INTEGER PRIMARY KEY REFERENCES public.messages(id) ON DELETE CASCADE,
    pinned_by  INTEGER NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
