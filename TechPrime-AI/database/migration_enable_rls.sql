-- PostgreSQL (Supabase). Applied 2026-10-09.
-- Turns on Row Level Security for every public table that Supabase exposes through its
-- REST API (PostgREST). No policies are added on purpose: with RLS on and no policy, the
-- public `anon` / `authenticated` roles can read or change nothing.
-- The PHP app is unaffected: it connects as `postgres`, which owns these tables and has
-- BYPASSRLS. (pos_sales, pos_sale_items, stock_movements and products_category_backup
-- already had RLS on; Supabase lists them as "RLS Enabled No Policy" — that is intended.)

ALTER TABLE public.wishlist            ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.cart                ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.logs                ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.order_items         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.locked_accounts     ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.reviews             ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.primo_conversations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.primo_messages      ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.saved_builds        ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.products            ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.shop_brands         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.shipments           ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.site_settings       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.orders              ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.users               ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.messages            ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.message_reactions   ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.message_hidden      ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.message_pins        ENABLE ROW LEVEL SECURITY;

-- Advisor warning "Function Search Path Mutable": pin the trigger function's search_path.
-- Its body only calls now() (pg_catalog, always searched), so an empty path is safe.
ALTER FUNCTION public.update_modified_column() SET search_path = '';
