// In-House-Dubbing · fonction Supabase « ihd ».
// Garde les clés API (ElevenLabs, Claude) côté serveur et fait les appels qui en ont besoin.
// Déploiement : Supabase › Edge Functions › Deploy a new function › nom « ihd », coller ce fichier.
// Les variables SUPABASE_URL, SUPABASE_ANON_KEY et SUPABASE_SERVICE_ROLE_KEY sont fournies par Supabase.
import { createClient } from "https://esm.sh/@supabase/supabase-js@2.45.4";

const EL_API = "https://api.elevenlabs.io";
const AI_API = "https://api.anthropic.com";
const AI_MODELS: Record<string, string> = {
  "claude-sonnet-5-5": "Claude Sonnet 5.5",
  "claude-opus-5-5": "Claude Opus 5.5",
  "claude-haiku-5-5": "Claude Haiku 5.5",
};
const FILE_RE = /^SEQ\d{2}_[a-z0-9-]+\/SEQ\d{2}_L\d{2}_[A-Z]{2,20}_T\d{1,3}\.wav$/;
const LINE_RE = /^SEQ\d{2}_L\d{2}$/;
const PID_RE = /^[a-z0-9][a-z0-9-]{1,39}$/;

const cors = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { ...cors, "Content-Type": "application/json; charset=utf-8" } });
const fail = (error: string, status = 400) => json({ ok: false, error }, status);

const admin = createClient(Deno.env.get("SUPABASE_URL")!, Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!, { auth: { persistSession: false } });

async function secret(id: string): Promise<string> {
  const { data } = await admin.from("secrets").select("value").eq("id", id).maybeSingle();
  return (data?.value as string) || "";
}
async function setSecret(id: string, value: string) {
  await admin.from("secrets").upsert({ id, value });
}
function elMessage(code: number, body: string): string {
  let m = body.slice(0, 200);
  try {
    const d = JSON.parse(body).detail;
    m = typeof d === "string" ? d : d?.message || d?.[0]?.msg || m;
  } catch (_) { /* corps non JSON */ }
  return code === 401 ? `ElevenLabs refuse la clé API (401). ${m}` : `ElevenLabs a répondu ${code} : ${m}`;
}
function aiMessage(code: number, body: string): string {
  let m = body.slice(0, 200);
  try { m = JSON.parse(body).error?.message || m; } catch (_) { /* corps non JSON */ }
  return code === 401 ? `L'API Claude refuse la clé (401). ${m}` : `L'API Claude a répondu ${code} : ${m}`;
}
async function status() {
  const el = await secret("el_key"), ai = await secret("ai_key");
  return { elHasKey: !!el, elHint: el ? el.slice(-4) : "", aiHasKey: !!ai, aiHint: ai ? ai.slice(-4) : "",
           aiModel: (await secret("ai_model")) || "claude-sonnet-5-5", aiModels: AI_MODELS };
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: cors });
  if (req.method !== "POST") return fail("Méthode non permise.", 405);

  // Qui appelle ?
  const auth = req.headers.get("Authorization") || "";
  const { data: u } = await admin.auth.getUser(auth.replace(/^Bearer\s+/i, ""));
  if (!u?.user) return fail("Session expirée. Reconnectez-vous.", 401);
  const { data: me } = await admin.from("profiles").select("*").eq("id", u.user.id).maybeSingle();
  if (!me || me.role === "pending") return fail("Compte en attente de validation par l'admin.", 403);
  const isAdmin = me.role === "admin";
  const canSee = (p: string) => isAdmin || (me.projects || []).includes(p);

  let body: Record<string, string> = {};
  try { body = await req.json(); } catch (_) { return fail("Requête illisible."); }
  const action = body.action || "";
  const adminOnly = ["elkey", "aikey", "aimodel", "claude", "userdel"];
  if (adminOnly.includes(action) && !isAdmin) return fail("Réservé à l'administrateur.", 403);

  switch (action) {
    case "status":
      return json({ ok: true, ...(await status()) });

    case "elkey": {
      const key = (body.key || "").trim();
      if (key && !/^[A-Za-z0-9_\-]{20,120}$/.test(key)) return fail("Cette clé n'a pas le format d'une clé API ElevenLabs.");
      if (key) {
        const r = await fetch(EL_API + "/v1/voices", { headers: { "xi-api-key": key } });
        if (!r.ok) return fail(elMessage(r.status, await r.text()));
      }
      await setSecret("el_key", key);
      return json({ ok: true, ...(await status()) });
    }

    case "aikey": {
      const key = (body.key || "").trim();
      if (key && !/^[A-Za-z0-9_\-]{20,200}$/.test(key)) return fail("Cette clé n'a pas le format d'une clé API Claude (sk-ant-…).");
      if (key) {
        const r = await fetch(AI_API + "/v1/models", { headers: { "x-api-key": key, "anthropic-version": "2023-06-01" } });
        if (!r.ok) return fail(aiMessage(r.status, await r.text()));
      }
      await setSecret("ai_key", key);
      return json({ ok: true, ...(await status()) });
    }

    case "aimodel": {
      if (!AI_MODELS[body.model]) return fail("Modèle inconnu.");
      await setSecret("ai_model", body.model);
      return json({ ok: true, ...(await status()) });
    }

    case "claude": {
      const key = await secret("ai_key");
      if (!key) return fail("Aucune clé API Claude n'est enregistrée (Accueil › Réglages).");
      const max = Math.max(256, Math.min(16000, +body.max || 4000));
      const r = await fetch(AI_API + "/v1/messages", {
        method: "POST",
        headers: { "x-api-key": key, "anthropic-version": "2023-06-01", "content-type": "application/json" },
        body: JSON.stringify({ model: (await secret("ai_model")) || "claude-sonnet-5-5", max_tokens: max, system: body.system || "",
                               messages: [{ role: "user", content: body.prompt || "" }] }),
      });
      const txt = await r.text();
      if (!r.ok) return fail(aiMessage(r.status, txt), 502);
      const j = JSON.parse(txt);
      const text = (j.content || []).filter((p: { type: string }) => p.type === "text").map((p: { text: string }) => p.text).join("");
      return json({ ok: true, text, stop: j.stop_reason, usage: j.usage });
    }

    case "elvoices": {
      const key = await secret("el_key");
      if (!key) return fail("Aucune clé API ElevenLabs n'est enregistrée.");
      const r = await fetch(EL_API + "/v1/voices", { headers: { "xi-api-key": key } });
      const txt = await r.text();
      if (!r.ok) return fail(elMessage(r.status, txt), 502);
      const voices = (JSON.parse(txt).voices || []).map((v: Record<string, string>) => ({ id: v.voice_id, name: v.name, cat: v.category || "", preview: v.preview_url || "" }))
        .sort((a: { name: string }, b: { name: string }) => a.name.localeCompare(b.name));
      return json({ ok: true, voices });
    }

    case "convert": {
      const { project, line, file } = body;
      if (!PID_RE.test(project || "") || !LINE_RE.test(line || "") || !FILE_RE.test(file || "")) return fail("Requête invalide.");
      if (!canSee(project)) return fail("Ce projet ne vous est pas ouvert.", 403);
      const key = await secret("el_key");
      if (!key) return fail("Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « Les voix ».");
      const ch = (file.match(/_L\d{2}_([A-Z]+)_T/) || [])[1];
      const { data: voice } = await admin.from("project_voices").select("*").eq("project", project).eq("ch", ch).maybeSingle();
      if (!voice) return fail("Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « Les voix ».");
      const { data: st } = await admin.from("project_settings").select("settings").eq("project", project).maybeSingle();
      const c = { model: "eleven_multilingual_sts_v2", format: "wav_44100", stability: 0.5, similarity: 0.75, noise: false, ...(st?.settings || {}) };
      const { data: take } = await admin.from("takes").select("*").eq("project", project).eq("f", file).maybeSingle();
      if (!take) return fail("Prise introuvable.", 404);
      const dl = await admin.storage.from("takes").download(`${project}/${file}`);
      if (dl.error || !dl.data) return fail("Fichier de la prise introuvable dans le stockage.", 404);
      const audio = dl.data;
      const send = async (format: string, path: string) => {
        const fd = new FormData();
        fd.append("audio", new File([audio], file.split("/")[1], { type: "audio/wav" }));
        fd.append("model_id", c.model);
        fd.append("voice_settings", JSON.stringify({ stability: +c.stability, similarity_boost: +c.similarity }));
        fd.append("remove_background_noise", c.noise ? "true" : "false");
        const r = await fetch(`${EL_API}${path}${encodeURIComponent(voice.voice_id)}?output_format=${format}`, { method: "POST", headers: { "xi-api-key": key }, body: fd });
        return { code: r.status, buf: new Uint8Array(await r.arrayBuffer()) };
      };
      let format = c.format;
      let r = await send(format, "/v1/voice-changer/");
      if (r.code === 404) r = await send(format, "/v1/speech-to-speech/");
      const txt = () => new TextDecoder().decode(r.buf);
      if (r.code >= 400 && r.code < 500 && r.code !== 401 && format !== "wav_24000" && /tier|subscription|output_format|plan/i.test(txt())) {
        format = "wav_24000";
        r = await send(format, "/v1/voice-changer/");
        if (r.code === 200) await admin.from("project_settings").upsert({ project, settings: { ...(st?.settings || {}), format } });
      }
      if (r.code !== 200) return fail(elMessage(r.code, txt()), 502);
      if (new TextDecoder().decode(r.buf.slice(0, 4)) !== "RIFF") return fail("ElevenLabs n'a pas renvoyé de WAV.", 502);
      const cvf = file.replace(/\.wav$/, "_IA.wav");
      const up = await admin.storage.from("takes").upload(`${project}/${cvf}`, r.buf, { contentType: "audio/wav", upsert: true });
      if (up.error) return fail("Impossible d'enregistrer la version IA : " + up.error.message, 500);
      const cv = { f: cvf, s: r.buf.length, d: Math.floor(Date.now() / 1000), v: voice.voice_name, vid: voice.voice_id, fmt: format };
      await admin.from("takes").update({ cv }).eq("project", project).eq("f", file);
      const { data: sel } = await admin.from("selected").select("f").eq("project", project).eq("line", line).maybeSingle();
      if (sel?.f === file) await admin.from("selected").upsert({ project, line, f: cvf });
      return json({ ok: true, cv });
    }

    case "userdel": {
      if (body.user === u.user.id) return fail("Vous ne pouvez pas supprimer votre propre compte.");
      const { error } = await admin.auth.admin.deleteUser(body.user);
      if (error) return fail(error.message);
      return json({ ok: true });
    }
  }
  return fail("Action inconnue.");
});
