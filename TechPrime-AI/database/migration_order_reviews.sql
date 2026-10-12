-- PostgreSQL. Client order reviews and custodian replies.
-- Additive only. Applied by includes/order_reviews.php if the tables are missing.
BEGIN;

CREATE TABLE IF NOT EXISTS public.order_reviews (
    id SERIAL PRIMARY KEY,
    order_id INTEGER NOT NULL UNIQUE REFERENCES public.orders(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NOT NULL DEFAULT '',
    tags TEXT NOT NULL DEFAULT '[]',
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reply_text TEXT NOT NULL DEFAULT '',
    reply_tags TEXT NOT NULL DEFAULT '[]',
    reply_user_id INTEGER NULL REFERENCES public.users(id) ON DELETE SET NULL,
    replied_at TIMESTAMP WITHOUT TIME ZONE NULL
);

CREATE INDEX IF NOT EXISTS order_reviews_user_idx ON public.order_reviews (user_id, id DESC);

CREATE TABLE IF NOT EXISTS public.order_review_media (
    id SERIAL PRIMARY KEY,
    review_id INTEGER NOT NULL REFERENCES public.order_reviews(id) ON DELETE CASCADE,
    stored_name VARCHAR(80) NOT NULL,
    media_kind VARCHAR(10) NOT NULL CHECK (media_kind IN ('image', 'video')),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS order_review_media_review_idx ON public.order_review_media (review_id, id);

COMMIT;
