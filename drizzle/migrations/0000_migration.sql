CREATE TYPE public.app_role AS ENUM ('admin', 'user');
CREATE TYPE public.application_status AS ENUM ('draft','submitted','needs_corrections','approved','rejected');

CREATE TABLE public.user_roles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
  role public.app_role NOT NULL,
  UNIQUE (user_id, role)
);
GRANT SELECT ON public.user_roles TO authenticated;
GRANT ALL ON public.user_roles TO service_role;
ALTER TABLE public.user_roles ENABLE ROW LEVEL SECURITY;

CREATE OR REPLACE FUNCTION public.has_role(_user_id uuid, _role public.app_role)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (SELECT 1 FROM public.user_roles WHERE user_id = _user_id AND role = _role)
$$;

CREATE POLICY "Users see own roles" ON public.user_roles FOR SELECT TO authenticated USING (auth.uid() = user_id);

CREATE TABLE public.seller_applications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL UNIQUE,
  status public.application_status NOT NULL DEFAULT 'draft',
  current_step int NOT NULL DEFAULT 1,
  personal jsonb NOT NULL DEFAULT '{}'::jsonb,
  business jsonb NOT NULL DEFAULT '{}'::jsonb,
  brand jsonb NOT NULL DEFAULT '{}'::jsonb,
  registration jsonb NOT NULL DEFAULT '{}'::jsonb,
  declaration jsonb NOT NULL DEFAULT '{}'::jsonb,
  applicant_email text,
  admin_comment text,
  reviewed_by uuid,
  reviewed_at timestamptz,
  submitted_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX seller_applications_email_unique ON public.seller_applications (lower(applicant_email)) WHERE applicant_email IS NOT NULL AND status <> 'rejected';
GRANT SELECT, INSERT, UPDATE ON public.seller_applications TO authenticated;
GRANT ALL ON public.seller_applications TO service_role;
ALTER TABLE public.seller_applications ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Applicant reads own" ON public.seller_applications FOR SELECT TO authenticated USING (auth.uid() = user_id OR public.has_role(auth.uid(),'admin'));
CREATE POLICY "Applicant creates own" ON public.seller_applications FOR INSERT TO authenticated WITH CHECK (auth.uid() = user_id AND status = 'draft');
CREATE POLICY "Applicant updates editable" ON public.seller_applications FOR UPDATE TO authenticated
  USING ((auth.uid() = user_id AND status IN ('draft','needs_corrections')) OR public.has_role(auth.uid(),'admin'))
  WITH CHECK ((auth.uid() = user_id AND status IN ('draft','needs_corrections','submitted')) OR public.has_role(auth.uid(),'admin'));

-- Guard: applicants cannot change review fields or set approved/rejected
CREATE OR REPLACE FUNCTION public.guard_application_update()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
  NEW.updated_at := now();
  IF NOT public.has_role(auth.uid(),'admin') THEN
    NEW.admin_comment := OLD.admin_comment;
    NEW.reviewed_by := OLD.reviewed_by;
    NEW.reviewed_at := OLD.reviewed_at;
    NEW.user_id := OLD.user_id;
    IF NEW.status = 'submitted' AND OLD.status <> 'submitted' THEN
      NEW.submitted_at := now();
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER seller_applications_guard BEFORE UPDATE ON public.seller_applications
FOR EACH ROW EXECUTE FUNCTION public.guard_application_update();

CREATE OR REPLACE FUNCTION public.application_email_taken(_email text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (SELECT 1 FROM public.seller_applications
    WHERE lower(applicant_email) = lower(_email) AND status <> 'rejected' AND user_id <> auth.uid())
$$;
GRANT EXECUTE ON FUNCTION public.application_email_taken(text) TO authenticated;