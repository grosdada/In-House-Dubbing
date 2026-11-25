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
// Messages en anglais quand la page est en anglais (champ « lang » envoyé par la page).
let EN = false;
const EN_T: Record<string, string> = {
  "Action inconnue.": "Unknown action.",
  "Aucune clé API Claude n'est enregistrée (Accueil › Réglages).": "No Claude API key is saved (Home › Settings).",
  "Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « IA Voice selector ».": "No ElevenLabs API key is saved. The admin has to add it in “IA Voice selector”.",
  "Aucune clé API ElevenLabs n'est enregistrée.": "No ElevenLabs API key is saved.",
  "Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « IA Voice selector ».": "No ElevenLabs voice is chosen for this character. Choose it in “IA Voice selector”.",
  "Ce projet ne vous est pas ouvert.": "This project isn't open to you.",
  "Cette clé n'a pas le format d'une clé API Claude (sk-ant-…).": "This doesn't look like a Claude API key (sk-ant-…).",
  "Cette clé n'a pas le format d'une clé API ElevenLabs.": "This doesn't look like an ElevenLabs API key.",
  "Cette réplique est vide.": "This line is empty.",
  "Compte en attente de validation par l'admin.": "Account waiting for the admin's approval.",
  "ElevenLabs n'a pas renvoyé de WAV.": "ElevenLabs didn't return a WAV.",
  "Fichier de la prise introuvable dans le stockage.": "Take file not found in storage.",
  "Les sons vocaux ne se génèrent pas : enregistrez-les.": "Vocal sounds can't be generated: record them.",
  "Modèle inconnu.": "Unknown model.",
  "Méthode non permise.": "Method not allowed.",
  "Prise introuvable.": "Take not found.",
  "Requête illisible.": "Unreadable request.",
  "Requête invalide.": "Invalid request.",
  "Réplique introuvable dans le scénario.": "Line not found in the script.",
  "Réplique invalide.": "Invalid line.",
  "Réservé à l'administrateur.": "Admin only.",
  "Session expirée. Reconnectez-vous.": "Session expired. Sign in again.",
  "Vous ne pouvez pas supprimer votre propre compte.": "You can't delete your own account.",
};
const EN_P: [RegExp, string][] = [
  [/^Impossible d'enregistrer la génération : (.*)$/s, "Could not save the generation: $1"],
  [/^Impossible d'enregistrer la version IA : (.*)$/s, "Could not save the AI version: $1"],
  [/^ElevenLabs refuse la clé API \(401\)\. (.*)$/s, "ElevenLabs refuses the API key (401). $1"],
  [/^ElevenLabs a répondu (\d+) : (.*)$/s, "ElevenLabs answered $1: $2"],
  [/^L'API Claude refuse la clé \(401\)\. (.*)$/s, "The Claude API refuses the key (401). $1"],
  [/^L'API Claude a répondu (\d+) : (.*)$/s, "The Claude API answered $1: $2"],
];
const enError = (m: string) => { if (EN_T[m]) return EN_T[m]; for (const [re, en] of EN_P) if (re.test(m)) return m.replace(re, en); return m; };
const fail = (error: string, status = 400) => json({ ok: false, error: EN ? enError(error) : error }, status);

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

  let body: Record<string, string> = {};
  try { body = await req.json(); } catch (_) { return fail("Requête illisible."); }
  EN = body.lang === "en";

  // Qui appelle ?
  const auth = req.headers.get("Authorization") || "";
  const { data: u } = await admin.auth.getUser(auth.replace(/^Bearer\s+/i, ""));
  if (!u?.user) return fail("Session expirée. Reconnectez-vous.", 401);
  const { data: me } = await admin.from("profiles").select("*").eq("id", u.user.id).maybeSingle();
  if (!me || me.role === "pending") return fail("Compte en attente de validation par l'admin.", 403);
  const isAdmin = me.role === "admin";
  const canSee = (p: string) => isAdmin || (me.projects || []).includes(p);

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
      if (!key) return fail("Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « IA Voice selector ».");
      const ch = (file.match(/_L\d{2}_([A-Z]+)_T/) || [])[1];
      const { data: voice } = await admin.from("project_voices").select("*").eq("project", project).eq("ch", ch).maybeSingle();
      if (!voice) return fail("Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « IA Voice selector ».");
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
      const { data: sel } = await admin.from("selected").select("f,pinned").eq("project", project).eq("line", line).maybeSingle();
      if (sel?.f === file && !sel?.pinned) await admin.from("selected").upsert({ project, line, f: cvf, pinned: false });
      return json({ ok: true, cv });
    }

    case "generate": {
      // Réplique lue par ElevenLabs (text-to-speech) avec la voix IA du personnage ; le texte vient du scénario.
      const { project, line } = body;
      if (!PID_RE.test(project || "") || !LINE_RE.test(line || "")) return fail("Requête invalide.");
      if (!canSee(project)) return fail("Ce projet ne vous est pas ouvert.", 403);
      const key = await secret("el_key");
      if (!key) return fail("Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « IA Voice selector ».");
      const { data: pr } = await admin.from("projects").select("data").eq("id", project).maybeSingle();
      const seq = (pr?.data?.seqs || []).find((q: { id: string }) => q.id === line.slice(0, 5));
      const L = (seq?.lines || []).find((x: unknown[]) => String(x[0]).padStart(2, "0") === line.slice(7));
      if (!seq || !L) return fail("Réplique introuvable dans le scénario.", 404);
      const [, ch, kind, raw] = L as string[];
      if (kind === "v") return fail("Les sons vocaux ne se génèrent pas : enregistrez-les.");
      const { data: voice } = await admin.from("project_voices").select("*").eq("project", project).eq("ch", ch).maybeSingle();
      if (!voice) return fail("Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « IA Voice selector ».");
      const { data: st } = await admin.from("project_settings").select("settings").eq("project", project).maybeSingle();
      const c = { format: "wav_44100", stability: 0.5, similarity: 0.75, tts: "eleven_multilingual_v2", ...(st?.settings || {}) };
      const { data: meta } = await admin.from("project_meta").select("name").eq("project", project).maybeSingle();
      const text = String(raw).split("{NAME}").join(meta?.name || pr?.data?.nameVar?.def || "").trim();
      if (!text) return fail("Cette réplique est vide.");
      const model = ["eleven_multilingual_v2", "eleven_v3", "eleven_flash_v2_5"].includes(c.tts) ? c.tts : "eleven_multilingual_v2";
      let stab = +c.stability;
      if (model === "eleven_v3") stab = stab < 0.25 ? 0 : stab > 0.75 ? 1 : 0.5;
      const payload = JSON.stringify({ text, model_id: model, voice_settings: { stability: stab, similarity_boost: +c.similarity } });
      let format = "", code = 0, buf = new Uint8Array();
      for (const f of [...new Set([c.format, "wav_24000", "pcm_24000"])]) {
        format = f;
        const r = await fetch(`${EL_API}/v1/text-to-speech/${encodeURIComponent(voice.voice_id)}?output_format=${f}`,
          { method: "POST", headers: { "xi-api-key": key, "Content-Type": "application/json", "Accept": "audio/*" }, body: payload });
        code = r.status; buf = new Uint8Array(await r.arrayBuffer());
        if (!(code >= 400 && code < 500 && code !== 401 && /tier|subscription|output_format|plan|format/i.test(new TextDecoder().decode(buf)))) break;
      }
      if (code !== 200) return fail(elMessage(code, new TextDecoder().decode(buf)), 502);
      if (format === "wav_24000" && c.format !== format) await admin.from("project_settings").upsert({ project, settings: { ...(st?.settings || {}), format } });
      if (format === "pcm_24000") {   // PCM brut 16 bits mono : on ajoute un en-tête WAV
        const h = new DataView(new ArrayBuffer(44)), n = buf.length, w = (o: number, s: string) => [...s].forEach((x, i) => h.setUint8(o + i, x.charCodeAt(0)));
        w(0, "RIFF"); h.setUint32(4, 36 + n, true); w(8, "WAVEfmt "); h.setUint32(16, 16, true); h.setUint16(20, 1, true); h.setUint16(22, 1, true);
        h.setUint32(24, 24000, true); h.setUint32(28, 48000, true); h.setUint16(32, 2, true); h.setUint16(34, 16, true); w(36, "data"); h.setUint32(40, n, true);
        const out = new Uint8Array(44 + n); out.set(new Uint8Array(h.buffer)); out.set(buf, 44); buf = out;
      }
      if (new TextDecoder().decode(buf.slice(0, 4)) !== "RIFF") return fail("ElevenLabs n'a pas renvoyé de WAV.", 502);
      const br = new DataView(buf.buffer, buf.byteOffset).getUint32(28, true) || 48000;
      const dur = Math.round(Math.max(0, buf.length - 44) / br * 100) / 100;
      const { data: last } = await admin.from("takes").select("t").eq("project", project).eq("line", line).order("t", { ascending: false }).limit(1);
      const t = ((last?.[0]?.t as number) || 0) + 1;
      const f = `${seq.folder}/${line}_${ch}_T${t}_IA.wav`;
      if (!FILE_RE.test(f.replace(/_IA\.wav$/, ".wav"))) return fail("Réplique invalide.");
      const up = await admin.storage.from("takes").upload(`${project}/${f}`, buf, { contentType: "audio/wav", upsert: true });
      if (up.error) return fail("Impossible d'enregistrer la génération : " + up.error.message, 500);
      const gen = { v: voice.voice_name, vid: voice.voice_id, model, fmt: format };
      const ins = await admin.from("takes").insert({ project, line, t, f, s: buf.length, dur, by_name: me.name, gen });
      if (ins.error) return fail(ins.error.message, 500);
      const { data: pin } = await admin.from("selected").select("pinned").eq("project", project).eq("line", line).maybeSingle();
      if (!pin?.pinned) await admin.from("selected").upsert({ project, line, f, pinned: false });
      return json({ ok: true, take: { f, t, s: buf.length, dur, by: me.name, gen } });
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
