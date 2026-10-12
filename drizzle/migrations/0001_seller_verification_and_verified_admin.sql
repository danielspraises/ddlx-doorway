CREATE TABLE public.seller_verifications (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 application_id uuid NOT NULL UNIQUE REFERENCES public.seller_applications(id),
 user_id uuid NOT NULL,
 status public.application_status NOT NULL DEFAULT 'draft',
 current_step integer NOT NULL DEFAULT 1 CHECK (current_step BETWEEN 1 AND 8),
 details jsonb NOT NULL DEFAULT '{}'::jsonb,
 documents jsonb NOT NULL DEFAULT '{}'::jsonb,
 admin_comment text,
 reviewed_by uuid,
 reviewed_at timestamptz,
 submitted_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now()
);
GRANT SELECT, INSERT, UPDATE ON public.seller_verifications TO authenticated;
GRANT ALL ON public.seller_verifications TO service_role;
ALTER TABLE public.seller_verifications ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Owner and staff read verification" ON public.seller_verifications FOR SELECT TO authenticated USING (user_id = auth.uid() OR public.has_role(auth.uid(), 'admin'));
CREATE POLICY "Approved applicant starts verification" ON public.seller_verifications FOR INSERT TO authenticated WITH CHECK (user_id = auth.uid() AND status = 'draft' AND admin_comment IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL AND submitted_at IS NULL AND EXISTS (SELECT 1 FROM public.seller_applications a WHERE a.id = application_id AND a.user_id = auth.uid() AND a.status = 'approved'));
CREATE POLICY "Owner edits draft or staff reviews" ON public.seller_verifications FOR UPDATE TO authenticated USING ((user_id = auth.uid() AND status IN ('draft','needs_corrections')) OR public.has_role(auth.uid(), 'admin')) WITH CHECK ((user_id = auth.uid() AND status IN ('draft','needs_corrections','submitted')) OR public.has_role(auth.uid(), 'admin'));
CREATE FUNCTION public.guard_verification_update() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
 NEW.updated_at := now();
 IF NOT public.has_role(auth.uid(), 'admin') THEN
  NEW.user_id := OLD.user_id; NEW.application_id := OLD.application_id;
  NEW.admin_comment := OLD.admin_comment; NEW.reviewed_by := OLD.reviewed_by; NEW.reviewed_at := OLD.reviewed_at; NEW.submitted_at := OLD.submitted_at;
  IF NOT EXISTS (SELECT 1 FROM public.seller_applications WHERE id = NEW.application_id AND user_id = NEW.user_id AND status = 'approved') THEN RAISE EXCEPTION 'Application approval is required'; END IF;
 END IF;
 IF NEW.status = 'submitted' AND OLD.status <> 'submitted' THEN
  IF COALESCE(NEW.details->>'declaration','') <> 'yes' OR COALESCE(NEW.details->>'social_verified','') <> 'yes' OR COALESCE(NEW.details->>'address_confirmed','') <> 'yes' OR COALESCE(NEW.details->>'business_presence','') = '' THEN RAISE EXCEPTION 'Complete verification details and declarations'; END IF;
  IF COALESCE(NEW.documents->>'identity','') = '' OR COALESCE(NEW.documents->>'portrait','') = '' OR COALESCE(NEW.documents->>'proof_address','') = '' OR COALESCE(NEW.documents->>'presence','') = '' THEN RAISE EXCEPTION 'Required verification files are missing'; END IF;
  IF EXISTS (SELECT 1 FROM public.seller_applications WHERE id = NEW.application_id AND registration->>'registered' = 'yes') AND COALESCE(NEW.documents->>'registration','') = '' THEN RAISE EXCEPTION 'Registration document is required'; END IF;
  NEW.submitted_at := now();
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verification_update_guard BEFORE UPDATE ON public.seller_verifications FOR EACH ROW EXECUTE FUNCTION public.guard_verification_update();
CREATE POLICY "Verification files read" ON storage.objects FOR SELECT TO authenticated USING (bucket_id = 'seller-verification' AND ((storage.foldername(name))[1] = auth.uid()::text OR public.has_role(auth.uid(),'admin')));
CREATE POLICY "Editable verification files upload" ON storage.objects FOR INSERT TO authenticated WITH CHECK (bucket_id = 'seller-verification' AND (storage.foldername(name))[1] = auth.uid()::text AND EXISTS (SELECT 1 FROM public.seller_verifications v WHERE v.user_id = auth.uid() AND v.id::text = (storage.foldername(name))[2] AND v.status IN ('draft','needs_corrections')));
CREATE POLICY "Editable verification files delete" ON storage.objects FOR DELETE TO authenticated USING (bucket_id = 'seller-verification' AND (storage.foldername(name))[1] = auth.uid()::text AND EXISTS (SELECT 1 FROM public.seller_verifications v WHERE v.user_id = auth.uid() AND v.id::text = (storage.foldername(name))[2] AND v.status IN ('draft','needs_corrections')));
CREATE FUNCTION public.grant_designated_verified_admin() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
 IF NEW.email_confirmed_at IS NOT NULL AND lower(NEW.email) = 'admin@ddlxapparel.com' THEN
  INSERT INTO public.user_roles(user_id, role) VALUES (NEW.id, 'admin') ON CONFLICT (user_id, role) DO NOTHING;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER designated_admin_signup AFTER INSERT ON auth.users FOR EACH ROW EXECUTE FUNCTION public.grant_designated_verified_admin();
CREATE TRIGGER designated_admin_verification AFTER UPDATE OF email_confirmed_at, email ON auth.users FOR EACH ROW EXECUTE FUNCTION public.grant_designated_verified_admin();