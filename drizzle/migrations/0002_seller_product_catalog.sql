CREATE TABLE public.seller_products (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 user_id uuid NOT NULL,
 name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 200),
 description text NOT NULL DEFAULT '',
 sku text NOT NULL CHECK (length(trim(sku)) BETWEEN 1 AND 100),
 category text NOT NULL DEFAULT 'Clothing',
 price numeric(12,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
 currency text NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN','USD','GBP','EUR')),
 stock integer NOT NULL DEFAULT 0 CHECK (stock >= 0),
 low_stock_threshold integer NOT NULL DEFAULT 5 CHECK (low_stock_threshold >= 0),
 status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','ready','archived')),
 images text[] NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (user_id, sku)
);
GRANT SELECT, INSERT, UPDATE, DELETE ON public.seller_products TO authenticated;
GRANT ALL ON public.seller_products TO service_role;
ALTER TABLE public.seller_products ENABLE ROW LEVEL SECURITY;
CREATE FUNCTION public.is_verified_seller() RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
 SELECT EXISTS (SELECT 1 FROM public.seller_verifications v JOIN public.seller_applications a ON a.id = v.application_id WHERE v.user_id = auth.uid() AND a.user_id = auth.uid() AND v.status = 'approved' AND a.status = 'approved');
$$;
REVOKE ALL ON FUNCTION public.is_verified_seller() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.is_verified_seller() TO authenticated;
CREATE POLICY "Seller reads own catalog" ON public.seller_products FOR SELECT TO authenticated USING (user_id = auth.uid());
CREATE POLICY "Verified seller creates products" ON public.seller_products FOR INSERT TO authenticated WITH CHECK (user_id = auth.uid() AND public.is_verified_seller());
CREATE POLICY "Verified seller updates products" ON public.seller_products FOR UPDATE TO authenticated USING (user_id = auth.uid() AND public.is_verified_seller()) WITH CHECK (user_id = auth.uid() AND public.is_verified_seller());
CREATE POLICY "Verified seller deletes products" ON public.seller_products FOR DELETE TO authenticated USING (user_id = auth.uid() AND public.is_verified_seller());
CREATE FUNCTION public.guard_seller_product() RETURNS trigger LANGUAGE plpgsql SET search_path = public AS $$
BEGIN
 IF TG_OP = 'UPDATE' THEN NEW.user_id := OLD.user_id; END IF;
 NEW.updated_at := now();
 IF cardinality(NEW.images) > 6 THEN RAISE EXCEPTION 'Maximum six images'; END IF;
 IF EXISTS (SELECT 1 FROM unnest(NEW.images) image WHERE image NOT LIKE NEW.user_id::text || '/%') THEN RAISE EXCEPTION 'Invalid product image owner'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER seller_product_guard BEFORE INSERT OR UPDATE ON public.seller_products FOR EACH ROW EXECUTE FUNCTION public.guard_seller_product();
CREATE POLICY "Seller reads product images" ON storage.objects FOR SELECT TO authenticated USING (bucket_id = 'seller-products' AND (storage.foldername(name))[1] = auth.uid()::text);
CREATE POLICY "Verified seller uploads product images" ON storage.objects FOR INSERT TO authenticated WITH CHECK (bucket_id = 'seller-products' AND (storage.foldername(name))[1] = auth.uid()::text AND public.is_verified_seller() AND lower(storage.extension(name)) IN ('jpg','jpeg','png','webp'));
CREATE POLICY "Verified seller deletes product images" ON storage.objects FOR DELETE TO authenticated USING (bucket_id = 'seller-products' AND (storage.foldername(name))[1] = auth.uid()::text AND public.is_verified_seller());