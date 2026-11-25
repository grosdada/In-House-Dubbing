-- In-House-Dubbing · mode Supabase
-- À coller une fois dans Supabase › SQL Editor › New query, puis « Run ».
-- Crée les tables, les règles d'accès (RLS) et le stockage privé des prises.

-- ---------- Comptes ----------
create table if not exists public.profiles (
  id uuid primary key references auth.users on delete cascade,
  email text,
  name text,
  role text not null default 'pending' check (role in ('admin', 'guest', 'pending')),
  projects text[] not null default '{}',
  created_at timestamptz not null default now()
);

-- Le tout premier compte créé devient admin ; les suivants attendent la validation de l'admin.
create or replace function public.ihd_new_user() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  insert into public.profiles (id, email, name, role)
  values (new.id, new.email,
          coalesce(new.raw_user_meta_data->>'name', split_part(new.email, '@', 1)),
          case when exists (select 1 from public.profiles) then 'pending' else 'admin' end);
  return new;
end $$;
drop trigger if exists ihd_on_auth_user_created on auth.users;
create trigger ihd_on_auth_user_created after insert on auth.users
  for each row execute function public.ihd_new_user();

create or replace function public.ihd_is_admin() returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from public.profiles where id = auth.uid() and role = 'admin')
$$;
create or replace function public.ihd_can_see(p text) returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from public.profiles
                 where id = auth.uid() and (role = 'admin' or (role = 'guest' and p = any(projects))))
$$;

-- ---------- Projets et prises ----------
create table if not exists public.projects (
  id text primary key check (id ~ '^[a-z0-9][a-z0-9-]{1,39}$'),
  num int,
  data jsonb not null,
  created_at timestamptz not null default now()
);
create table if not exists public.takes (
  id bigserial primary key,
  project text not null references public.projects on delete cascade,
  line text not null check (line ~ '^SEQ[0-9]{2}_L[0-9]{2}$'),
  t int not null,
  f text not null,
  s int,
  dur real,
  by_name text,
  cv jsonb,
  created_at timestamptz not null default now(),
  unique (project, f)
);
create index if not exists takes_project_idx on public.takes (project);
-- Prises générées par synthèse vocale (bouton Generate) : voix, modèle, format.
alter table public.takes add column if not exists gen jsonb;
create table if not exists public.selected (
  project text not null references public.projects on delete cascade,
  line text not null,
  f text not null,
  primary key (project, line)
);
-- Favori choisi à la main (★) : une nouvelle prise ne le remplace pas.
alter table public.selected add column if not exists pinned boolean not null default false;
create table if not exists public.project_meta (       -- prénom réglable ({NAME})
  project text primary key references public.projects on delete cascade,
  name text
);
-- Gains d'écoute et de transfert (dB) par fichier ou « @PERSO ».
alter table public.project_meta add column if not exists gains jsonb not null default '{}';
-- Gains d'écoute et de transfert (dB) par fichier ou « @PERSO ».
alter table public.project_meta add column if not exists gains jsonb not null default '{}';
create table if not exists public.project_voices (     -- voix ElevenLabs par personnage
  project text not null references public.projects on delete cascade,
  ch text not null,
  voice_id text not null,
  voice_name text,
  primary key (project, ch)
);
create table if not exists public.project_settings (   -- réglages de conversion (admin)
  project text primary key references public.projects on delete cascade,
  settings jsonb not null default '{}'
);
-- Clés API : aucune règle d'accès, donc invisible depuis les navigateurs.
-- Seule la fonction « ihd » (clé de service) peut les lire et les écrire.
create table if not exists public.secrets (id text primary key, value text);

-- ---------- Règles d'accès ----------
alter table public.profiles enable row level security;
alter table public.projects enable row level security;
alter table public.takes enable row level security;
alter table public.selected enable row level security;
alter table public.project_meta enable row level security;
alter table public.project_voices enable row level security;
alter table public.project_settings enable row level security;
alter table public.secrets enable row level security;

drop policy if exists ihd_profiles_read on public.profiles;
create policy ihd_profiles_read on public.profiles for select using (id = auth.uid() or public.ihd_is_admin());
drop policy if exists ihd_profiles_admin on public.profiles;
create policy ihd_profiles_admin on public.profiles for update using (public.ihd_is_admin()) with check (public.ihd_is_admin());

drop policy if exists ihd_projects_read on public.projects;
create policy ihd_projects_read on public.projects for select using (public.ihd_can_see(id));
drop policy if exists ihd_projects_admin on public.projects;
create policy ihd_projects_admin on public.projects for all using (public.ihd_is_admin()) with check (public.ihd_is_admin());

drop policy if exists ihd_takes_all on public.takes;
create policy ihd_takes_all on public.takes for all using (public.ihd_can_see(project)) with check (public.ihd_can_see(project));
drop policy if exists ihd_selected_all on public.selected;
create policy ihd_selected_all on public.selected for all using (public.ihd_can_see(project)) with check (public.ihd_can_see(project));
drop policy if exists ihd_meta_all on public.project_meta;
create policy ihd_meta_all on public.project_meta for all using (public.ihd_can_see(project)) with check (public.ihd_can_see(project));
drop policy if exists ihd_voices_all on public.project_voices;
create policy ihd_voices_all on public.project_voices for all using (public.ihd_can_see(project)) with check (public.ihd_can_see(project));
drop policy if exists ihd_settings_read on public.project_settings;
create policy ihd_settings_read on public.project_settings for select using (public.ihd_can_see(project));
drop policy if exists ihd_settings_admin on public.project_settings;
create policy ihd_settings_admin on public.project_settings for all using (public.ihd_is_admin()) with check (public.ihd_is_admin());

-- ---------- Stockage des prises (privé) ----------
-- Chemin d'un fichier : <projet>/<dossier de séquence>/<fichier>.wav
insert into storage.buckets (id, name, public) values ('takes', 'takes', false) on conflict (id) do nothing;
drop policy if exists ihd_takes_files on storage.objects;
create policy ihd_takes_files on storage.objects for all
  using (bucket_id = 'takes' and public.ihd_can_see((storage.foldername(name))[1]))
  with check (bucket_id = 'takes' and public.ihd_can_see((storage.foldername(name))[1]));
