<?php
// In-House-Dubbing — API.
// Comptes (admin / invité), projets (un scénario découpé en séquences et répliques par projet),
// prises enregistrées dans le navigateur, conversion de voix ElevenLabs côté serveur.
// Toutes les données vivent dans data/ (interdit au web). Les secrets sont dans config.php (hors dépôt).

const SESSION_DAYS = 30;
const MAX_BYTES = 60 * 1024 * 1024;
const MAX_PROJECT_BYTES = 3 * 1024 * 1024;

$CFG = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
if (!is_array($CFG)) $CFG = [];
define('SETUP_KEY', (string)($CFG['setup_key'] ?? ''));
define('EL_API', getenv('EL_API') ?: (string)($CFG['el_api'] ?? 'https://api.elevenlabs.io'));
define('AI_API', getenv('AI_API') ?: (string)($CFG['ai_api'] ?? 'https://api.anthropic.com'));
$LEGACY = is_array($CFG['legacy'] ?? null) ? $CFG['legacy'] : null;   // reprise d'une ancienne installation mono-projet

$DATA = __DIR__ . '/data';
header('Cache-Control: no-store');

function out($a, $code = 200) {
  if (isset($a['error']) && is_string($a['error']) && ($_COOKIE['ihd_lang'] ?? '') === 'en') $a['error'] = en_error($a['error']);
  http_response_code($code);

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
/* Messages d'erreur en anglais quand l'interface est en anglais (cookie ihd_lang=en, posé par la page).
   Les messages restent écrits en français dans le code ; cette table les traduit à la sortie. */
function en_error($m) {
  static $T = [
    ["Ce n'est pas un fichier de projet JSON.", "This is not a JSON project file."],
    ["Identifiant de projet invalide (minuscules, chiffres et tirets).", "Invalid project id (lowercase letters, digits and hyphens)."],
    ["Le projet doit avoir un titre.", "The project needs a title."],
    ["Le projet doit lister ses personnages (chars).", "The project must list its characters (chars)."],
    ["Le projet doit contenir des séquences (seqs).", "The project must contain sequences (seqs)."],
    ["Origine refusée.", "Origin refused."],
    ["L'installation n'est pas configurée (config.php).", "The installation is not configured (config.php)."],
    ["Lien de création invalide.", "Invalid setup link."],
    ["Mot de passe : au moins 8 caractères.", "Password: at least 8 characters."],
    ["Nouveau mot de passe : au moins 8 caractères.", "New password: at least 8 characters."],
    ["Le compte admin existe déjà. Connectez-vous.", "The admin account already exists. Sign in."],
    ["Trop de tentatives. Réessayez dans un quart d'heure.", "Too many attempts. Try again in fifteen minutes."],
    ["Identifiant ou mot de passe incorrect.", "Wrong username or password."],
    ["Session expirée. Reconnectez-vous.", "Session expired. Sign in again."],
    ["Réservé à l'administrateur.", "Admin only."],
    ["Le fichier n'est pas arrivé ou il est trop gros.", "The file didn't arrive or is too big."],
    ["Cette clé n'a pas le format d'une clé API ElevenLabs.", "This doesn't look like an ElevenLabs API key."],
    ["Aucune clé API ElevenLabs n'est enregistrée.", "No ElevenLabs API key is saved."],
    ["Cette clé n'a pas le format d'une clé API Claude (sk-ant-…).", "This doesn't look like a Claude API key (sk-ant-…)."],
    ["Modèle inconnu.", "Unknown model."],
    ["Aucune clé API Claude n'est enregistrée (Accueil › Réglages).", "No Claude API key is saved (Home › Settings)."],
    ["Demande vide ou trop longue.", "Empty or too long request."],
    ["Cet identifiant existe déjà.", "This username already exists."],
    ["Compte introuvable.", "Account not found."],
    ["Vous ne pouvez pas supprimer votre propre compte.", "You can't delete your own account."],
    ["Mot de passe actuel incorrect.", "Wrong current password."],
    ["Projet introuvable.", "Project not found."],
    ["Ce projet ne vous est pas ouvert. Demandez l'accès à l'admin.", "This project isn't open to you. Ask the admin for access."],
    ["Réplique inconnue.", "Unknown line."],
    ["Taille de fichier inattendue.", "Unexpected file size."],
    ["Ce n'est pas un fichier WAV.", "This is not a WAV file."],
    ["Impossible d'enregistrer le fichier sur le serveur.", "Could not save the file on the server."],
    ["Requête invalide.", "Invalid request."],
    ["Prénom invalide.", "Invalid name."],
    ["Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « IA Voice selector ».", "No ElevenLabs API key is saved. The admin has to add it in “IA Voice selector”."],
    ["Prise introuvable sur le serveur.", "Take not found on the server."],
    ["Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « IA Voice selector ».", "No ElevenLabs voice is chosen for this character. Choose it in “IA Voice selector”."],
    ["ElevenLabs n'a pas renvoyé de WAV.", "ElevenLabs didn't return a WAV."],
    ["Réplique introuvable dans le scénario.", "Line not found in the script."],
    ["Les sons vocaux ne se génèrent pas : enregistrez-les.", "Vocal sounds can't be generated: record them."],
    ["Réplique invalide.", "Invalid line."],
    ["Cette réplique est vide.", "This line is empty."],
    ["Action inconnue.", "Unknown action."],
    ["Identifiant : 2 à 32 caractères, minuscules, chiffres, point, tiret ou tiret bas.", "Username: 2 to 32 characters, lowercase letters, digits, dot, hyphen or underscore."],
  ];
  static $P = [
    ['/^Personnage invalide : (.*)$/su', 'Invalid character: $1'],
    ['/^Séquence invalide ou en double : (.*)$/su', 'Invalid or duplicate sequence: $1'],
    ['/^Dossier invalide pour (.*)$/su', 'Invalid folder for $1'],
    ['/^Réplique mal formée dans (.*)$/su', 'Malformed line in $1'],
    ['/^Numéro de réplique invalide dans (\S+) : (.*)$/su', 'Invalid line number in $1: $2'],
    ['/^Personnage inconnu dans (.+?) : (.*)$/su', 'Unknown character in $1: $2'],
    ['/^Connexion à ElevenLabs impossible : (.*)$/su', 'Could not reach ElevenLabs: $1'],
    ['/^ElevenLabs refuse la clé API \(401\)\. Vérifiez-la ou ses permissions\. (.*)$/su', 'ElevenLabs refuses the API key (401). Check it or its permissions. $1'],
    ['/^ElevenLabs a répondu (\d+) : (.*)$/su', 'ElevenLabs answered $1: $2'],
    ["/^Connexion à l'API Claude impossible : (.*)$/su", 'Could not reach the Claude API: $1'],
    ["/^L'API Claude refuse la clé \(401\)\. (.*)$/su", 'The Claude API refuses the key (401). $1'],
    ["/^L'API Claude a répondu (\d+) : (.*)$/su", 'The Claude API answered $1: $2'],
    ["/^L'audio n'est pas arrivé \(erreur (.*)\)\.$/su", "The audio didn't arrive (error $1)."],
  ];
  foreach ($T as [$fr, $en]) if ($m === $fr) return $en;
  foreach ($P as [$re, $en]) if (preg_match($re, $m)) return preg_replace($re, $en, $m);
  return $m;
}
function jread($f, $def) { $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j) ? $j : $def; }
function jwrite($f, $d) { $t = $f . '.tmp'; file_put_contents($t, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); rename($t, $f); }
function locked($file, callable $fn) { $h = fopen($file, 'c'); flock($h, LOCK_EX); $r = $fn(); flock($h, LOCK_UN); fclose($h); return $r; }
function copy_tree($src, $dst, $skip = []) {
  if (!is_dir($src)) return;
  @mkdir($dst, 0755, true);
  foreach (scandir($src) as $n) {
    if ($n === '.' || $n === '..' || in_array($n, $skip, true)) continue;
    if (is_dir("$src/$n")) copy_tree("$src/$n", "$dst/$n");
    elseif (!is_file("$dst/$n")) @copy("$src/$n", "$dst/$n");
  }
}

if (!is_dir($DATA)) @mkdir($DATA, 0755, true);
if (!is_file("$DATA/.htaccess")) @file_put_contents("$DATA/.htaccess", "Require all denied\nDeny from all\n");
@mkdir("$DATA/projects", 0755, true);

$USERS = "$DATA/users.json";
$SESS  = "$DATA/sessions.json";
$FAILS = "$DATA/loginfails.json";
$ELKEY = "$DATA/el.json";
$AIKEY = "$DATA/ai.json";
$ALOCK = "$DATA/.lock-auth";

/* ---------- reprise de l'ancienne installation (comptes + clé ElevenLabs) ---------- */
if ($LEGACY && !is_file($USERS) && is_dir($LEGACY['data'] ?? '')) {
  $old = jread($LEGACY['data'] . '/users.json', []);
  foreach ($old as $n => $u) if (($u['role'] ?? '') !== 'admin') $old[$n]['projects'] = [$LEGACY['project']];
  if ($old) jwrite($USERS, $old);
  $oc = jread($LEGACY['data'] . '/config.json', []);
  if (!empty($oc['key']) && !is_file($ELKEY)) jwrite($ELKEY, ['key' => $oc['key']]);
}

// Reprise des prises d'une ancienne installation mono-projet dans le projet du même identifiant.
function migrate_legacy_project($pid) {
  global $LEGACY, $DATA, $ELKEY;
  $dir = "$DATA/projects/$pid";
  if (!$LEGACY || ($LEGACY['project'] ?? '') !== $pid || is_file("$dir/state.json") || !is_dir($LEGACY['data'] ?? '')) return false;
  copy_tree($LEGACY['data'], $dir, ['users.json', 'sessions.json', 'loginfails.json', 'config.json', '.htaccess', '.lock', '.lock-auth',
                                    'state.json.tmp', 'config.json.tmp', 'api.php.avant-elevenlabs', 'index.html.avant-elevenlabs']);
  $oc = jread($LEGACY['data'] . '/config.json', []);
  if (!empty($oc['key']) && el_key() === '') jwrite($ELKEY, ['key' => $oc['key']]);
  unset($oc['key']);
  if ($oc) jwrite("$dir/config.json", $oc);
  return is_file("$dir/state.json");
}
// Projet déposé directement sur le serveur (data/projects/<id>/script.json) : reprise au premier passage.
if ($LEGACY && is_file("$DATA/projects/" . ($LEGACY['project'] ?? '') . '/script.json')) migrate_legacy_project($LEGACY['project']);

/* ---------- comptes et sessions ---------- */
function cookie_path() { $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'); return $d === '' ? '/' : $d . '/'; }
function set_session_cookie($value, $expires) {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  setcookie('ihd', $value, ['expires' => $expires, 'path' => cookie_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}
function client_ip() { return (string)($_SERVER['REMOTE_ADDR'] ?? '?'); }
function current_user() {
  global $USERS, $SESS, $ALOCK;
  $tok = (string)($_COOKIE['ihd'] ?? '');
  if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
  $h = hash('sha256', $tok);
  return locked($ALOCK, function () use ($h, $tok, $USERS, $SESS) {
    $ss = jread($SESS, []);
    $s = $ss[$h] ?? null;
    if (!$s || $s['exp'] < time()) { if ($s) { unset($ss[$h]); jwrite($SESS, $ss); } return null; }
    $u = jread($USERS, [])[$s['user']] ?? null;
    if (!$u) { unset($ss[$h]); jwrite($SESS, $ss); return null; }
    if ($s['exp'] - time() < (SESSION_DAYS - 1) * 86400) {
      $ss[$h]['exp'] = time() + SESSION_DAYS * 86400; jwrite($SESS, $ss);
      set_session_cookie($tok, $ss[$h]['exp']);
    }
    return ['name' => $s['user'], 'role' => $u['role'], 'projects' => $u['projects'] ?? []];
  });
}
function open_session($user) {
  global $SESS, $ALOCK;
  $tok = bin2hex(random_bytes(32));
  $exp = time() + SESSION_DAYS * 86400;
  locked($ALOCK, function () use ($tok, $exp, $user, $SESS) {
    $ss = jread($SESS, []);
    foreach ($ss as $k => $v) if ($v['exp'] < time()) unset($ss[$k]);
    $ss[hash('sha256', $tok)] = ['user' => $user, 'exp' => $exp, 'ip' => client_ip()];
    jwrite($SESS, $ss);
  });
  set_session_cookie($tok, $exp);
}
function drop_sessions_of($user, $except = '') {
  global $SESS, $ALOCK;
  locked($ALOCK, function () use ($user, $SESS, $except) {
    $ss = jread($SESS, []);
    foreach ($ss as $k => $v) if ($v['user'] === $user && $k !== $except) unset($ss[$k]);
    jwrite($SESS, $ss);
  });
}
function valid_user_name($n) { return preg_match('/^[a-z0-9._-]{2,32}$/', $n) === 1; }
function check_password($p) { return mb_strlen($p) >= 8 && mb_strlen($p) <= 200; }
$USER_RE_MSG = 'Identifiant : 2 à 32 caractères, minuscules, chiffres, point, tiret ou tiret bas.';

/* ---------- projets ---------- */
$PID_RE    = '/^[a-z0-9][a-z0-9-]{1,39}$/';
$LINE_RE   = '/^SEQ\d{2}_L\d{2}$/';
$FOLDER_RE = '/^SEQ\d{2}_[a-z0-9-]+$/';
$CHAR_RE   = '/^[A-Z]{2,20}$/';
$FILE_RE   = '/^SEQ\d{2}_[a-z0-9-]+\/SEQ\d{2}_L\d{2}_[A-Z]{2,20}_T\d{1,3}(_IA)?\.wav$/';
$FORMATS   = ['wav_48000', 'wav_44100', 'wav_24000', 'wav_22050', 'wav_16000'];
$MODELS    = ['eleven_multilingual_sts_v2', 'eleven_english_sts_v2'];
$TTS       = ['eleven_multilingual_v2', 'eleven_v3', 'eleven_flash_v2_5'];

function project_list() {
  global $DATA;
  $out = [];
  foreach (glob("$DATA/projects/*/script.json") ?: [] as $f) {
    $p = jread($f, null);
    if (!$p) continue;
    $total = 0;
    foreach ($p['seqs'] ?? [] as $s) $total += count($s['lines'] ?? []);
    $st = jread(dirname($f) . '/state.json', []);
    $out[] = ['id' => $p['id'], 'num' => (int)($p['num'] ?? 0), 'title' => $p['title'], 'subtitle' => $p['subtitle'] ?? '',
              'seqs' => count($p['seqs'] ?? []), 'lines' => $total, 'done' => count($st['takes'] ?? []), 'chars' => count($p['chars'] ?? [])];
  }
  usort($out, function ($a, $b) { return $a['num'] <=> $b['num'] ?: strcmp($a['id'], $b['id']); });
  return $out;
}
function can_see($ME, $pid) { return $ME && ($ME['role'] === 'admin' || in_array($pid, $ME['projects'] ?? [], true)); }

// Vérifie le fichier d'un projet. Renvoie un message d'erreur ou null.
function validate_project($p) {
  global $PID_RE, $CHAR_RE, $FOLDER_RE;
  if (!is_array($p)) return "Ce n'est pas un fichier de projet JSON.";
  if (!preg_match($PID_RE, (string)($p['id'] ?? ''))) return "Identifiant de projet invalide (minuscules, chiffres et tirets).";
  if (trim((string)($p['title'] ?? '')) === '') return 'Le projet doit avoir un titre.';
  if (!is_array($p['chars'] ?? null) || !$p['chars']) return 'Le projet doit lister ses personnages (chars).';
  foreach ($p['chars'] as $k => $c) if (!preg_match($CHAR_RE, (string)$k) || !isset($c['n'])) return "Personnage invalide : $k";
  if (!is_array($p['seqs'] ?? null) || !$p['seqs']) return 'Le projet doit contenir des séquences (seqs).';
  $seen = [];
  foreach ($p['seqs'] as $s) {
    $id = (string)($s['id'] ?? '');
    if (!preg_match('/^SEQ\d{2}$/', $id) || isset($seen[$id])) return "Séquence invalide ou en double : $id";
    $seen[$id] = 1;
    if (!preg_match($FOLDER_RE, (string)($s['folder'] ?? '')) || substr($s['folder'], 0, 5) !== $id) return "Dossier invalide pour $id";
    $nums = [];
    foreach ($s['lines'] ?? [] as $l) {
      if (!is_array($l) || count($l) < 5) return "Réplique mal formée dans $id";
      if (!preg_match('/^\d{2}$/', (string)$l[0]) || isset($nums[$l[0]])) return "Numéro de réplique invalide dans $id : " . $l[0];
      $nums[$l[0]] = 1;
      if (!isset($p['chars'][$l[1]])) return "Personnage inconnu dans $id L" . $l[0] . ' : ' . $l[1];
    }
  }
  return null;
}

/* ---------- état et réglages d'un projet ---------- */
function read_state($file) {
  $s = jread($file, []);
  // selected : la prise retenue (favori) de chaque réplique, celle qui part au transfert sous le nom simple.
  // pinned : favori choisi à la main (★) ; une nouvelle prise ou génération ne le remplace pas.
  $s += ['name' => '', 'takes' => [], 'selected' => [], 'pinned' => [], 'updated' => 0];
  if (!is_array($s['takes'])) $s['takes'] = [];
  if (!is_array($s['selected'])) $s['selected'] = [];
  if (!is_array($s['pinned'])) $s['pinned'] = [];
  return $s;
}
function with_state($file, $lock, callable $fn) {
  return locked($lock, function () use ($file, $fn) {
    $s = read_state($file);
    $res = $fn($s);
    $s['updated'] = time();
    jwrite($file, $s);
    return [$s, $res];
  });
}
function read_pconf($file) {
  $c = jread($file, []);
  unset($c['key']);
  $c += ['model' => 'eleven_multilingual_sts_v2', 'format' => 'wav_44100', 'stability' => 0.5, 'similarity' => 0.75,
         'noise' => false, 'auto' => false, 'voices' => [], 'tts' => 'eleven_multilingual_v2'];
  if (!is_array($c['voices'])) $c['voices'] = [];
  return $c;
}
function el_key() { global $ELKEY; return (string)(jread($ELKEY, [])['key'] ?? ''); }
function public_el($c) {
  $k = el_key();
  return ['hasKey' => $k !== '', 'keyHint' => $k !== '' ? substr($k, -4) : '', 'model' => $c['model'], 'format' => $c['format'],
          'stability' => (float)$c['stability'], 'similarity' => (float)$c['similarity'], 'noise' => (bool)$c['noise'],
          'auto' => (bool)$c['auto'], 'voices' => (object)$c['voices'], 'tts' => $c['tts']];
}
function public_state($s, $c) {
  return ['ok' => true, 'name' => $s['name'], 'takes' => (object)$s['takes'], 'selected' => (object)$s['selected'], 'pinned' => (object)$s['pinned'],
          'updated' => $s['updated'], 'el' => public_el($c)];
}
function el_call($method, $path, $key, $post = null, $timeout = 150, $headers = []) {
  $ch = curl_init(EL_API . $path);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
                          CURLOPT_HTTPHEADER => array_merge(['xi-api-key: ' . $key], $headers)]);
  if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return [$code, (string)$body, $err];
}
function el_message($code, $body, $err) {
  if ($err !== '') return "Connexion à ElevenLabs impossible : $err";
  $j = json_decode($body, true);
  $m = null;
  if (is_array($j)) {
    $d = $j['detail'] ?? null;
    if (is_array($d) && isset($d['message'])) $m = $d['message'];
    elseif (is_array($d) && isset($d[0]['msg'])) $m = $d[0]['msg'];
    elseif (is_string($d)) $m = $d;
  }
  if ($m === null) $m = trim(substr(strip_tags($body), 0, 200));
  if ($code === 401) return "ElevenLabs refuse la clé API (401). Vérifiez-la ou ses permissions. $m";
  return "ElevenLabs a répondu $code : $m";
}

/* ---------- Claude (préparation des projets à partir d'un scénario) ---------- */
$AI_MODELS = ['claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-opus-5-5' => 'Claude Opus 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5'];
function ai_conf() { global $AIKEY; $c = jread($AIKEY, []); return ['key' => (string)($c['key'] ?? ''), 'model' => (string)($c['model'] ?? 'claude-sonnet-5-5')]; }
function ai_public() { global $AI_MODELS; $c = ai_conf(); return ['hasKey' => $c['key'] !== '', 'keyHint' => $c['key'] !== '' ? substr($c['key'], -4) : '', 'model' => $c['model'], 'models' => $AI_MODELS]; }
function ai_call($method, $path, $key, $body = null, $timeout = 170) {
  $ch = curl_init(AI_API . $path);
  $h = ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'];
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $h]);
  if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return [$code, (string)$resp, $err];
}
function ai_message($code, $body, $err) {
  if ($err !== '') return "Connexion à l'API Claude impossible : $err";
  $j = json_decode($body, true);
  $m = $j['error']['message'] ?? trim(substr(strip_tags($body), 0, 200));
  if ($code === 401) return "L'API Claude refuse la clé (401). $m";
  return "L'API Claude a répondu $code : $m";
}

/* ---------- protection des requêtes d'écriture ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])) {
  $oh = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
  if ($oh && strcasecmp($oh, preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')) !== 0) out(['ok' => false, 'error' => 'Origine refusée.'], 403);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$ME = current_user();

/* ---------- actions sans session ---------- */
switch ($action) {
case 'me':
  out(['ok' => true, 'needsSetup' => count(jread($USERS, [])) === 0, 'setupReady' => SETUP_KEY !== '', 'user' => $ME]);

case 'setup':
  if (SETUP_KEY === '') out(['ok' => false, 'error' => "L'installation n'est pas configurée (config.php)."], 500);
  if (!hash_equals(SETUP_KEY, (string)($_POST['setup'] ?? ''))) out(['ok' => false, 'error' => 'Lien de création invalide.'], 403);
  $name = strtolower(trim((string)($_POST['user'] ?? '')));
  $pass = (string)($_POST['pass'] ?? '');
  if (!valid_user_name($name)) out(['ok' => false, 'error' => $USER_RE_MSG], 400);
  if (!check_password($pass)) out(['ok' => false, 'error' => 'Mot de passe : au moins 8 caractères.'], 400);
  $created = locked($ALOCK, function () use ($USERS, $name, $pass) {
    $users = jread($USERS, []);
    if (count($users) > 0) return false;
    $users[$name] = ['hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'admin', 'created' => time(), 'projects' => []];
    jwrite($USERS, $users);
    return true;
  });
  if (!$created) out(['ok' => false, 'error' => 'Le compte admin existe déjà. Connectez-vous.'], 409);
  open_session($name);
  out(['ok' => true, 'user' => ['name' => $name, 'role' => 'admin', 'projects' => []]]);

case 'login':
  $ip = client_ip();
  $recent = array_filter(jread($FAILS, [])[$ip] ?? [], function ($t) { return $t > time() - 900; });
  if (count($recent) >= 8) out(['ok' => false, 'error' => "Trop de tentatives. Réessayez dans un quart d'heure."], 429);
  $name = strtolower(trim((string)($_POST['user'] ?? '')));
  $pass = (string)($_POST['pass'] ?? '');
  $u = jread($USERS, [])[$name] ?? null;
  if (!$u || !password_verify($pass, $u['hash'])) {
    usleep(700000);
    locked($ALOCK, function () use ($FAILS, $ip) { $f = jread($FAILS, []); $f[$ip][] = time(); $f[$ip] = array_slice($f[$ip], -20); jwrite($FAILS, $f); });
    out(['ok' => false, 'error' => 'Identifiant ou mot de passe incorrect.'], 401);
  }
  if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) {
    locked($ALOCK, function () use ($USERS, $name, $pass) { $us = jread($USERS, []); $us[$name]['hash'] = password_hash($pass, PASSWORD_DEFAULT); jwrite($USERS, $us); });
  }
  open_session($name);
  out(['ok' => true, 'user' => ['name' => $name, 'role' => $u['role'], 'projects' => $u['projects'] ?? []]]);

case 'logout':
  $tok = (string)($_COOKIE['ihd'] ?? '');
  if (preg_match('/^[a-f0-9]{64}$/', $tok)) locked($ALOCK, function () use ($SESS, $tok) { $ss = jread($SESS, []); unset($ss[hash('sha256', $tok)]); jwrite($SESS, $ss); });
  set_session_cookie('', time() - 3600);
  out(['ok' => true]);
}

if (!$ME) out(['ok' => false, 'error' => 'Session expirée. Reconnectez-vous.', 'login' => true], 401);
$ADMIN_ONLY = ['elkey', 'elsettings', 'users', 'useradd', 'userdel', 'userreset', 'userprojects', 'projectimport', 'aiconfig', 'aikey', 'aimodel', 'claude'];
if (in_array($action, $ADMIN_ONLY, true) && $ME['role'] !== 'admin') out(['ok' => false, 'error' => "Réservé à l'administrateur."], 403);

/* ---------- actions globales ---------- */
switch ($action) {
case 'projects':
  $list = array_values(array_filter(project_list(), function ($p) use ($ME) { return can_see($ME, $p['id']); }));
  out(['ok' => true, 'projects' => $list]);

case 'projectimport':
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > MAX_PROJECT_BYTES) out(['ok' => false, 'error' => "Le fichier n'est pas arrivé ou il est trop gros."], 400);
  $p = json_decode((string)file_get_contents($f['tmp_name']), true);
  $err = validate_project($p);
  if ($err) out(['ok' => false, 'error' => $err], 400);
  $dir = "$DATA/projects/" . $p['id'];
  $existed = is_file("$dir/script.json");
  @mkdir($dir, 0755, true);
  if (empty($p['num'])) { $p['num'] = $existed ? (int)(jread("$dir/script.json", [])['num'] ?? 0) : count(project_list()) + 1; }
  jwrite("$dir/script.json", $p);
  $migrated = migrate_legacy_project($p['id']);
  out(['ok' => true, 'id' => $p['id'], 'updated' => $existed, 'migrated' => $migrated]);

case 'elkey':
  $key = trim((string)($_POST['key'] ?? ''));
  if ($key === '') { jwrite($ELKEY, ['key' => '']); out(['ok' => true, 'hasKey' => false]); }
  if (!preg_match('/^[A-Za-z0-9_\-]{20,120}$/', $key)) out(['ok' => false, 'error' => "Cette clé n'a pas le format d'une clé API ElevenLabs."], 400);
  [$code, $body, $err] = el_call('GET', '/v1/voices', $key, null, 30);
  if ($code !== 200) out(['ok' => false, 'error' => el_message($code, $body, $err)], 400);
  jwrite($ELKEY, ['key' => $key]);
  out(['ok' => true, 'hasKey' => true]);

case 'elvoices':
  $key = el_key();
  if ($key === '') out(['ok' => false, 'error' => "Aucune clé API ElevenLabs n'est enregistrée."], 400);
  [$code, $body, $err] = el_call('GET', '/v1/voices', $key, null, 30);
  if ($code !== 200) out(['ok' => false, 'error' => el_message($code, $body, $err)], 502);
  $list = [];
  foreach (json_decode($body, true)['voices'] ?? [] as $v)
    $list[] = ['id' => $v['voice_id'] ?? '', 'name' => $v['name'] ?? '?', 'cat' => $v['category'] ?? '', 'preview' => $v['preview_url'] ?? ''];
  usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  out(['ok' => true, 'voices' => $list]);

case 'aiconfig':
  out(['ok' => true, 'ai' => ai_public()]);

case 'aikey':
  $key = trim((string)($_POST['key'] ?? ''));
  $c = ai_conf();
  if ($key === '') { jwrite($AIKEY, ['key' => '', 'model' => $c['model']]); out(['ok' => true, 'ai' => ai_public()]); }
  if (!preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $key)) out(['ok' => false, 'error' => "Cette clé n'a pas le format d'une clé API Claude (sk-ant-…)."], 400);
  [$code, $body, $err] = ai_call('GET', '/v1/models', $key, null, 30);
  if ($code !== 200) out(['ok' => false, 'error' => ai_message($code, $body, $err)], 400);
  jwrite($AIKEY, ['key' => $key, 'model' => $c['model']]);
  out(['ok' => true, 'ai' => ai_public()]);

case 'aimodel':
  $m = (string)($_POST['model'] ?? '');
  if (!isset($AI_MODELS[$m])) out(['ok' => false, 'error' => 'Modèle inconnu.'], 400);
  $c = ai_conf();
  jwrite($AIKEY, ['key' => $c['key'], 'model' => $m]);
  out(['ok' => true, 'ai' => ai_public()]);

case 'claude':
  // Relais vers l'API Claude : la clé reste sur le serveur. Réservé à l'admin.
  @set_time_limit(200);
  $c = ai_conf();
  if ($c['key'] === '') out(['ok' => false, 'error' => "Aucune clé API Claude n'est enregistrée (Accueil › Réglages)."], 400);
  $system = (string)($_POST['system'] ?? '');
  $prompt = (string)($_POST['prompt'] ?? '');
  $max = max(256, min(16000, (int)($_POST['max'] ?? 4000)));
  if ($prompt === '' || strlen($prompt) > 400000) out(['ok' => false, 'error' => 'Demande vide ou trop longue.'], 400);
  [$code, $body, $err] = ai_call('POST', '/v1/messages', $c['key'], ['model' => $c['model'], 'max_tokens' => $max, 'system' => $system,
                                 'messages' => [['role' => 'user', 'content' => $prompt]]]);
  if ($code !== 200) out(['ok' => false, 'error' => ai_message($code, $body, $err)], 502);
  $j = json_decode($body, true);
  $text = '';
  foreach ($j['content'] ?? [] as $part) if (($part['type'] ?? '') === 'text') $text .= $part['text'];
  out(['ok' => true, 'text' => $text, 'stop' => $j['stop_reason'] ?? '', 'usage' => $j['usage'] ?? null]);

case 'users':
  $list = [];
  foreach (jread($USERS, []) as $n => $u) $list[] = ['name' => $n, 'role' => $u['role'], 'created' => $u['created'] ?? 0, 'projects' => $u['projects'] ?? []];
  out(['ok' => true, 'users' => $list, 'allProjects' => project_list()]);

case 'useradd':
  $name = strtolower(trim((string)($_POST['user'] ?? '')));
  $pass = (string)($_POST['pass'] ?? '');
  $role = ($_POST['role'] ?? 'guest') === 'admin' ? 'admin' : 'guest';
  $projs = array_values(array_filter(explode(',', (string)($_POST['projects'] ?? '')), function ($x) use ($PID_RE) { return preg_match($PID_RE, $x); }));
  if (!valid_user_name($name)) out(['ok' => false, 'error' => $USER_RE_MSG], 400);
  if (!check_password($pass)) out(['ok' => false, 'error' => 'Mot de passe : au moins 8 caractères.'], 400);
  $ok = locked($ALOCK, function () use ($USERS, $name, $pass, $role, $projs) {
    $users = jread($USERS, []);
    if (isset($users[$name])) return false;
    $users[$name] = ['hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => $role, 'created' => time(), 'projects' => $projs];
    jwrite($USERS, $users);
    return true;
  });
  if (!$ok) out(['ok' => false, 'error' => 'Cet identifiant existe déjà.'], 409);
  out(['ok' => true]);

case 'userprojects':
  $name = (string)($_POST['user'] ?? '');
  $projs = array_values(array_filter(explode(',', (string)($_POST['projects'] ?? '')), function ($x) use ($PID_RE) { return preg_match($PID_RE, $x); }));
  $ok = locked($ALOCK, function () use ($USERS, $name, $projs) { $us = jread($USERS, []); if (!isset($us[$name])) return false; $us[$name]['projects'] = $projs; jwrite($USERS, $us); return true; });
  if (!$ok) out(['ok' => false, 'error' => 'Compte introuvable.'], 404);
  out(['ok' => true]);

case 'userdel':
  $name = (string)($_POST['user'] ?? '');
  if ($name === $ME['name']) out(['ok' => false, 'error' => 'Vous ne pouvez pas supprimer votre propre compte.'], 400);
  locked($ALOCK, function () use ($USERS, $name) { $users = jread($USERS, []); unset($users[$name]); jwrite($USERS, $users); });
  drop_sessions_of($name);
  out(['ok' => true]);

case 'userreset':
  $name = (string)($_POST['user'] ?? '');
  $pass = (string)($_POST['pass'] ?? '');
  if (!check_password($pass)) out(['ok' => false, 'error' => 'Mot de passe : au moins 8 caractères.'], 400);
  $ok = locked($ALOCK, function () use ($USERS, $name, $pass) { $us = jread($USERS, []); if (!isset($us[$name])) return false; $us[$name]['hash'] = password_hash($pass, PASSWORD_DEFAULT); jwrite($USERS, $us); return true; });
  if (!$ok) out(['ok' => false, 'error' => 'Compte introuvable.'], 404);
  if ($name !== $ME['name']) drop_sessions_of($name);
  out(['ok' => true]);

case 'mypass':
  $old = (string)($_POST['old'] ?? '');
  $new = (string)($_POST['new'] ?? '');
  if (!password_verify($old, jread($USERS, [])[$ME['name']]['hash'] ?? '')) out(['ok' => false, 'error' => 'Mot de passe actuel incorrect.'], 400);
  if (!check_password($new)) out(['ok' => false, 'error' => 'Nouveau mot de passe : au moins 8 caractères.'], 400);
  locked($ALOCK, function () use ($USERS, $ME, $new) { $us = jread($USERS, []); $us[$ME['name']]['hash'] = password_hash($new, PASSWORD_DEFAULT); jwrite($USERS, $us); });
  drop_sessions_of($ME['name'], hash('sha256', (string)($_COOKIE['ihd'] ?? '')));
  out(['ok' => true]);
}

/* ---------- actions d'un projet (paramètre p) ---------- */
$PID = (string)($_GET['p'] ?? $_POST['p'] ?? '');
if (!preg_match($PID_RE, $PID) || !is_file("$DATA/projects/$PID/script.json")) out(['ok' => false, 'error' => 'Projet introuvable.'], 404);
if (!can_see($ME, $PID)) out(['ok' => false, 'error' => "Ce projet ne vous est pas ouvert. Demandez l'accès à l'admin."], 403);
$PD     = "$DATA/projects/$PID";
$STATE  = "$PD/state.json";
$PCONF  = "$PD/config.json";
$LOCK   = "$PD/.lock";

switch ($action) {

case 'script':
  out(['ok' => true, 'project' => jread("$PD/script.json", [])]);

case 'state':
  $s = read_state($STATE);
  if ($s['name'] === '') $s['name'] = (string)(jread("$PD/script.json", [])['nameVar']['def'] ?? '');
  out(public_state($s, read_pconf($PCONF)));

case 'upload':
  $line = (string)($_POST['line'] ?? '');
  $folder = (string)($_POST['folder'] ?? '');
  $char = (string)($_POST['char'] ?? '');
  $dur = round((float)($_POST['dur'] ?? 0), 2);
  if (!preg_match($LINE_RE, $line) || !preg_match($FOLDER_RE, $folder) || !preg_match($CHAR_RE, $char)
      || substr($folder, 0, 5) !== substr($line, 0, 5)) out(['ok' => false, 'error' => 'Réplique inconnue.'], 400);
  $f = $_FILES['audio'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => "L'audio n'est pas arrivé (erreur " . ($f['error'] ?? '?') . ').'], 400);
  if ($f['size'] < 44 || $f['size'] > MAX_BYTES) out(['ok' => false, 'error' => 'Taille de fichier inattendue.'], 400);
  $head = file_get_contents($f['tmp_name'], false, null, 0, 12);
  if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') out(['ok' => false, 'error' => "Ce n'est pas un fichier WAV."], 400);
  @mkdir("$PD/$folder", 0755, true);
  [$s, $take] = with_state($STATE, $LOCK, function (&$s) use ($line, $folder, $char, $f, $dur, $PD, $ME) {
    $list = $s['takes'][$line] ?? [];
    $n = 1;
    foreach ($list as $t) $n = max($n, $t['t'] + 1);
    $rel = "$folder/{$line}_{$char}_T$n.wav";
    if (!move_uploaded_file($f['tmp_name'], "$PD/$rel")) return null;
    $take = ['f' => $rel, 't' => $n, 's' => filesize("$PD/$rel"), 'd' => time(), 'dur' => $dur, 'by' => $ME['name']];
    $list[] = $take;
    $s['takes'][$line] = $list;
    if (empty($s['pinned'][$line])) $s['selected'][$line] = $rel;
    return $take;
  });
  if (!$take) out(['ok' => false, 'error' => "Impossible d'enregistrer le fichier sur le serveur."], 500);
  out(['ok' => true, 'take' => $take] + public_state($s, read_pconf($PCONF)));

case 'select':
  $line = (string)($_POST['line'] ?? '');
  $file = (string)($_POST['file'] ?? '');
  if (!preg_match($LINE_RE, $line) || !preg_match($FILE_RE, $file)) out(['ok' => false, 'error' => 'Requête invalide.'], 400);
  [$s] = with_state($STATE, $LOCK, function (&$s) use ($line, $file) {
    foreach ($s['takes'][$line] ?? [] as $t)
      if ($t['f'] === $file || (($t['cv']['f'] ?? '') === $file)) { $s['selected'][$line] = $file; $s['pinned'][$line] = true; }
  });
  out(public_state($s, read_pconf($PCONF)));

case 'delete':
  $line = (string)($_POST['line'] ?? '');
  $file = (string)($_POST['file'] ?? '');
  if (!preg_match($LINE_RE, $line) || !preg_match($FILE_RE, $file)) out(['ok' => false, 'error' => 'Requête invalide.'], 400);
  [$s] = with_state($STATE, $LOCK, function (&$s) use ($line, $file, $PD) {
    $trash = function ($rel) use ($PD) {
      @mkdir("$PD/_corbeille", 0755, true);
      if (is_file("$PD/$rel")) @rename("$PD/$rel", "$PD/_corbeille/" . time() . '_' . basename($rel));
    };
    $keep = [];
    foreach ($s['takes'][$line] ?? [] as $t) {
      if ($t['f'] === $file) { $trash($t['f']); if (!empty($t['cv'])) $trash($t['cv']['f']); continue; }
      if (($t['cv']['f'] ?? '') === $file) { $trash($file); unset($t['cv']); }
      $keep[] = $t;
    }
    if ($keep) $s['takes'][$line] = $keep; else unset($s['takes'][$line]);
    $sel = $s['selected'][$line] ?? '';
    $still = false;
    foreach ($keep as $t) if ($t['f'] === $sel || (($t['cv']['f'] ?? '') === $sel)) $still = true;
    if (!$still) { unset($s['pinned'][$line]); if ($keep) $s['selected'][$line] = end($keep)['f']; else unset($s['selected'][$line]); }
  });
  out(public_state($s, read_pconf($PCONF)));

case 'name':
  $name = trim((string)($_POST['name'] ?? ''));
  $name = preg_replace('/[^\p{L} \'\-]/u', '', $name);
  if ($name === '' || mb_strlen($name) > 24) out(['ok' => false, 'error' => 'Prénom invalide.'], 400);
  [$s] = with_state($STATE, $LOCK, function (&$s) use ($name) { $s['name'] = $name; });
  out(public_state($s, read_pconf($PCONF)));

case 'audio':
  $file = (string)($_GET['f'] ?? '');
  if (!preg_match($FILE_RE, $file) || !is_file("$PD/$file")) { http_response_code(404); exit; }
  $path = "$PD/$file";
  $size = filesize($path);
  $start = 0; $end = $size - 1;
  header('Content-Type: audio/wav');
  header('Accept-Ranges: bytes');
  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); $end = $size - 1; }
    if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
  }
  header('Content-Length: ' . ($end - $start + 1));
  $fp = fopen($path, 'rb');
  fseek($fp, $start);
  $left = $end - $start + 1;
  while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(65536, $left)); echo $chunk; $left -= strlen($chunk); }
  fclose($fp);
  exit;

case 'elsettings':
  $c = read_pconf($PCONF);
  if (isset($_POST['model']) && in_array($_POST['model'], $MODELS, true)) $c['model'] = $_POST['model'];
  if (isset($_POST['format']) && in_array($_POST['format'], $FORMATS, true)) $c['format'] = $_POST['format'];
  if (isset($_POST['stability'])) $c['stability'] = max(0, min(1, round((float)$_POST['stability'], 2)));
  if (isset($_POST['similarity'])) $c['similarity'] = max(0, min(1, round((float)$_POST['similarity'], 2)));
  if (isset($_POST['noise'])) $c['noise'] = $_POST['noise'] === '1';
  if (isset($_POST['auto'])) $c['auto'] = $_POST['auto'] === '1';
  if (isset($_POST['tts']) && in_array($_POST['tts'], $TTS, true)) $c['tts'] = $_POST['tts'];
  locked($LOCK, function () use ($PCONF, $c) { jwrite($PCONF, $c); });
  out(['ok' => true, 'el' => public_el($c)]);

case 'elmap':
  $char = (string)($_POST['char'] ?? '');
  $id = (string)($_POST['id'] ?? '');
  $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
  if (!preg_match($CHAR_RE, $char) || ($id !== '' && !preg_match('/^[A-Za-z0-9]{8,40}$/', $id))) out(['ok' => false, 'error' => 'Requête invalide.'], 400);
  $c = locked($LOCK, function () use ($PCONF, $char, $id, $name) {
    $c = read_pconf($PCONF);
    if ($id === '') unset($c['voices'][$char]); else $c['voices'][$char] = ['id' => $id, 'name' => $name];
    jwrite($PCONF, $c);
    return $c;
  });
  out(['ok' => true, 'el' => public_el($c)]);

case 'convert':
  @set_time_limit(200);
  $line = (string)($_POST['line'] ?? '');
  $file = (string)($_POST['file'] ?? '');
  if (!preg_match($LINE_RE, $line) || !preg_match($FILE_RE, $file) || substr($file, -7) === '_IA.wav') out(['ok' => false, 'error' => 'Requête invalide.'], 400);
  $key = el_key();
  if ($key === '') out(['ok' => false, 'error' => "Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « IA Voice selector »."], 400);
  $c = read_pconf($PCONF);
  $found = null;
  foreach (read_state($STATE)['takes'][$line] ?? [] as $t) if ($t['f'] === $file) $found = $t;
  if (!$found || !is_file("$PD/$file")) out(['ok' => false, 'error' => 'Prise introuvable sur le serveur.'], 404);
  preg_match('/_L\d{2}_([A-Z]+)_T/', $file, $m);
  $voice = $c['voices'][$m[1] ?? ''] ?? null;
  if (!$voice) out(['ok' => false, 'error' => "Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « IA Voice selector »."], 400);
  $settings = json_encode(['stability' => (float)$c['stability'], 'similarity_boost' => (float)$c['similarity']]);
  $send = function ($format, $path) use ($c, $PD, $file, $settings, $voice, $key) {
    $post = ['audio' => new CURLFile("$PD/$file", 'audio/wav', basename($file)), 'model_id' => $c['model'],
             'voice_settings' => $settings, 'remove_background_noise' => $c['noise'] ? 'true' : 'false'];
    return el_call('POST', $path . rawurlencode($voice['id']) . '?output_format=' . $format, $key, $post, 180);
  };
  $format = $c['format'];
  [$code, $body, $err] = $send($format, '/v1/voice-changer/');
  if ($code === 404) [$code, $body, $err] = $send($format, '/v1/speech-to-speech/');
  // Format réservé à une offre supérieure : on redescend en 24 kHz et on le retient.
  if ($code >= 400 && $code < 500 && $code !== 401 && $format !== 'wav_24000' && preg_match('/tier|subscription|output_format|plan/i', $body)) {
    $format = 'wav_24000';
    [$code, $body, $err] = $send($format, '/v1/voice-changer/');
    if ($code === 200) locked($LOCK, function () use ($PCONF, $format) { $cc = read_pconf($PCONF); $cc['format'] = $format; jwrite($PCONF, $cc); });
  }
  if ($code !== 200) out(['ok' => false, 'error' => el_message($code, $body, $err)], 502);
  if (substr($body, 0, 4) !== 'RIFF') out(['ok' => false, 'error' => "ElevenLabs n'a pas renvoyé de WAV."], 502);
  $rel = substr($file, 0, -4) . '_IA.wav';
  file_put_contents("$PD/$rel", $body);
  $cv = ['f' => $rel, 's' => filesize("$PD/$rel"), 'd' => time(), 'v' => $voice['name'], 'vid' => $voice['id'], 'fmt' => $format];
  [$s] = with_state($STATE, $LOCK, function (&$s) use ($line, $file, $cv) {
    foreach ($s['takes'][$line] ?? [] as $i => $t) {
      if ($t['f'] !== $file) continue;
      $s['takes'][$line][$i]['cv'] = $cv;
      if (($s['selected'][$line] ?? '') === $file && empty($s['pinned'][$line])) $s['selected'][$line] = $cv['f'];
    }
  });
  out(['ok' => true, 'cv' => $cv] + public_state($s, read_pconf($PCONF)));

case 'generate':
  // Génère une réplique en synthèse vocale (ElevenLabs text-to-speech) avec la voix IA du personnage.
  // Le texte vient du scénario sur le serveur, jamais du navigateur.
  @set_time_limit(200);
  $line = (string)($_POST['line'] ?? '');
  if (!preg_match($LINE_RE, $line)) out(['ok' => false, 'error' => 'Requête invalide.'], 400);
  $key = el_key();
  if ($key === '') out(['ok' => false, 'error' => "Aucune clé API ElevenLabs n'est enregistrée. L'admin doit l'ajouter dans « IA Voice selector »."], 400);
  $proj = jread("$PD/script.json", []);
  $seq = null; $L = null;
  foreach ($proj['seqs'] ?? [] as $q) if (($q['id'] ?? '') === substr($line, 0, 5)) $seq = $q;
  foreach ($seq['lines'] ?? [] as $x) if (str_pad((string)$x[0], 2, '0', STR_PAD_LEFT) === substr($line, 7)) $L = $x;
  if (!$seq || !$L) out(['ok' => false, 'error' => 'Réplique introuvable dans le scénario.'], 404);
  [, $char, $kind, $text] = $L;
  if ($kind === 'v') out(['ok' => false, 'error' => 'Les sons vocaux ne se génèrent pas : enregistrez-les.'], 400);
  $folder = (string)$seq['folder'];
  if (!preg_match($CHAR_RE, (string)$char) || !preg_match($FOLDER_RE, $folder)) out(['ok' => false, 'error' => 'Réplique invalide.'], 400);
  $c = read_pconf($PCONF);
  $voice = $c['voices'][$char] ?? null;
  if (!$voice) out(['ok' => false, 'error' => "Aucune voix ElevenLabs n'est choisie pour ce personnage. Choisissez-la dans « IA Voice selector »."], 400);
  $st = read_state($STATE);
  $nm = $st['name'] !== '' ? $st['name'] : (string)($proj['nameVar']['def'] ?? '');
  $text = trim(str_replace('{NAME}', $nm, (string)$text));
  if ($text === '') out(['ok' => false, 'error' => 'Cette réplique est vide.'], 400);
  $model = in_array($c['tts'], $TTS, true) ? $c['tts'] : 'eleven_multilingual_v2';
  $stab = (float)$c['stability'];
  if ($model === 'eleven_v3') $stab = $stab < 0.25 ? 0.0 : ($stab > 0.75 ? 1.0 : 0.5);  // v3 n'accepte que 0, 0,5 ou 1
  $payload = json_encode(['text' => $text, 'model_id' => $model,
                          'voice_settings' => ['stability' => $stab, 'similarity_boost' => (float)$c['similarity']]]);
  $tries = array_values(array_unique([$c['format'], 'wav_24000', 'pcm_24000']));
  foreach ($tries as $format) {
    [$code, $body, $err] = el_call('POST', '/v1/text-to-speech/' . rawurlencode($voice['id']) . '?output_format=' . $format, $key,
                                   $payload, 180, ['Content-Type: application/json', 'Accept: audio/*']);
    if (!($code >= 400 && $code < 500 && $code !== 401 && preg_match('/tier|subscription|output_format|plan|format/i', $body))) break;
  }
  if ($code !== 200) out(['ok' => false, 'error' => el_message($code, $body, $err)], 502);
  // Format réservé à une offre supérieure : on retient le 24 kHz pour les fois suivantes.
  if ($format === 'wav_24000' && $c['format'] !== $format) locked($LOCK, function () use ($PCONF, $format) { $cc = read_pconf($PCONF); $cc['format'] = $format; jwrite($PCONF, $cc); });
  if ($format === 'pcm_24000') {   // PCM brut 16 bits mono : on lui ajoute un en-tête WAV
    $n = strlen($body);
    $body = 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', $n) . $body;
  }
  if (substr($body, 0, 4) !== 'RIFF') out(['ok' => false, 'error' => "ElevenLabs n'a pas renvoyé de WAV."], 502);
  $br = unpack('V', substr($body, 28, 4))[1] ?: 48000;
  $dur = round(max(0, strlen($body) - 44) / $br, 2);
  @mkdir("$PD/$folder", 0755, true);
  [$s, $take] = with_state($STATE, $LOCK, function (&$s) use ($line, $folder, $char, $body, $dur, $PD, $ME, $voice, $model, $format) {
    $list = $s['takes'][$line] ?? [];
    $n = 1;
    foreach ($list as $t) $n = max($n, $t['t'] + 1);
    $rel = "$folder/{$line}_{$char}_T{$n}_IA.wav";
    if (file_put_contents("$PD/$rel", $body) === false) return null;
    $take = ['f' => $rel, 't' => $n, 's' => strlen($body), 'd' => time(), 'dur' => $dur, 'by' => $ME['name'],
             'gen' => ['v' => $voice['name'], 'vid' => $voice['id'], 'model' => $model, 'fmt' => $format]];
    $list[] = $take;
    $s['takes'][$line] = $list;
    if (empty($s['pinned'][$line])) $s['selected'][$line] = $rel;
    return $take;
  });
  if (!$take) out(['ok' => false, 'error' => "Impossible d'enregistrer le fichier sur le serveur."], 500);
  out(['ok' => true, 'take' => $take] + public_state($s, read_pconf($PCONF)));

default:
  out(['ok' => false, 'error' => 'Action inconnue.'], 400);
}
