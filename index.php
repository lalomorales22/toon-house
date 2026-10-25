<?php
/*
 * VOICE SWAP — single-file local voice conversion studio (macOS / Linux)
 *
 *   Start:   php index.php            (launches server on http://127.0.0.1:8777 and opens browser)
 *   Install: click "Install engine" in the page, or:  php index.php setup
 *
 * Pipeline:  your voice clip (10–30s) + target song/clip
 *   1. ffmpeg normalises inputs, trims silence from your clip
 *   2. Demucs (htdemucs) splits the song into vocals + instrumental
 *   3. Seed-VC (zero-shot, no training) re-sings the vocals in your voice
 *   4. ffmpeg mixes your new vocals back over the instrumental → MP3
 *
 * Toon Lab (?p=toon): build a cartoon character, drop in audio, and it lip-syncs + moves to it.
 *   Precise mode: optional Demucs voice isolation → wav2vec2 phoneme recognition (+ Whisper words) → mouth shapes
 *   Instant mode: in-browser formant analysis (also drives the live-mic puppet)
 *   Recording: canvas + audio → MediaRecorder → ffmpeg → MP4 in renders/
 *   Art styles: classic, anime, comic, sketch, clay, ASCII 3D, matrix, pixel, neon, noir, blueprint (one character, drawn 11 ways)
 *
 * Text → your voice: Kokoro TTS reads the text, then Seed-VC (speech model) turns it into your voice.
 *
 * Agents / CLI (see skills/toon-studio/SKILL.md):
 *   php index.php options              every character option, preset, voice and font as JSON
 *   php index.php make spec.json       script → voice → lip-sync → headless-Chrome render → MP4 (--wait to block)
 *   php index.php make --prompt "…"    let the local LLM ("Director") write the spec first
 *   php index.php person spec.json     build a new realistic 3D person in Blender (MPFB) for the Realistic 3D style
 *
 * Everything lives next to this file: engine/ (venv, Seed-VC, models), jobs/, voices/, toons/, characters/, renders/, projects/.
 */

const PORT = 8777;
// PHP 8.5 on macOS treats ANY SIGALRM as "time's up", even with no time limit: the request dies and 2 s later the server
// worker is killed ("Maximum execution time of 0+2 seconds exceeded (terminated)"), until no workers are left and the app
// freezes. We never use PHP's time limit, so stray alarms are ignored.
if (function_exists('pcntl_signal') && defined('SIGALRM') && (int)ini_get('max_execution_time') === 0) pcntl_signal(SIGALRM, SIG_IGN);
const SEEDVC_REPO = 'https://github.com/Plachtaa/seed-vc.git';
const SEEDVC_COMMIT = '51383efd921027683c89e5348211d93ff12ac2a8';

define('BASE', __DIR__);
define('ENGINE', BASE . '/engine');
define('JOBS', BASE . '/jobs');
define('VOICES', BASE . '/voices');
define('TOONS', BASE . '/toons');
define('CHARS', BASE . '/characters');
define('RENDERS', BASE . '/renders');
define('PROJECTS', BASE . '/projects');
define('AVATARS', BASE . '/avatars');
define('SCENES', BASE . '/scenes');     // background pictures (uploaded or generated)
define('MUSIC', BASE . '/music');       // background music (uploaded or generated)
define('GAMES', BASE . '/games');       // Game Creator: one folder per saved game (game.json, index.html, thumb.jpg)
define('BUDDIES', BASE . '/buddies');   // voxel buddies (recipe json + thumbnail)
define('SETTINGS', BASE . '/settings.json');
define('SYS_PATH', '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin');

// ───────────────────────────── Toon Lab model (shared by the page, the API and agents) ─────────────────────────────
const TOON_PAL = [
    'skin'  => ['#ffe0c4', '#f2c79a', '#d9a066', '#b07142', '#8a5530', '#5c3a21', '#9be15d', '#6ec6ff', '#c49bff', '#ff8fab', '#ffd23f', '#e0e0e0'],
    'hair'  => ['#111111', '#2d1c12', '#5b3a1e', '#a0522d', '#e8c170', '#f4f1e1', '#9a9a9a', '#e63946', '#3a86ff', '#8338ec', '#06d6a0', '#ff7eb6'],
    'eye'   => ['#5a3b1f', '#1d1d1d', '#2e86de', '#27ae60', '#8e44ad', '#c0392b', '#00e5ff', '#ff2a6d'],
    'cloth' => ['#e63946', '#ffd23f', '#3a86ff', '#06d6a0', '#8338ec', '#ff7eb6', '#f77f00', '#111111', '#ffffff', '#7a7a7a', '#2b2d42', '#6b4226'],
    'lips'  => ['auto', '#c0392b', '#e75480', '#7b2d26', '#222222'],
];
// [key, label, choices | palette name | [min,max,step], multi?]  — a single-element row is a section heading
const TOON_SCHEMA = [
    ['Style'],
    ['style', 'Art style', [['classic', 'Classic'], ['anime', 'Anime'], ['comic', 'Comic'], ['sketch', 'Sketch'], ['clay', 'Clay'], ['ascii', 'ASCII 3D'], ['matrix', 'Matrix'],
        ['pixel', 'Pixel'], ['neon', 'Neon'], ['noir', 'Noir'], ['blueprint', 'Blueprint'], ['real', 'Realistic 3D']]],
    ['avatar', '3D person (Realistic 3D style)', []],   // choices come from avatars/*.glb, see toon_schema()
    ['Head'],
    ['head', 'Shape', [['round', 'Round'], ['oval', 'Oval'], ['square', 'Square'], ['egg', 'Egg']]],
    ['skin', 'Skin', 'skin'],
    ['headSize', 'Head size', [0.8, 1.4, 0.05]],
    ['eyes', 'Eyes', [['round', 'Big'], ['wide', 'Wide'], ['sparkle', 'Sparkle'], ['dot', 'Dots'], ['sleepy', 'Sleepy'], ['angry', 'Angry'], ['robot', 'Robot']]],
    ['eyeColor', 'Eye color', 'eye'],
    ['brows', 'Brows', [['thick', 'Thick'], ['thin', 'Thin'], ['uni', 'Unibrow'], ['none', 'None']]],
    ['nose', 'Nose', [['button', 'Button'], ['round', 'Round'], ['long', 'Long'], ['none', 'None']]],
    ['ears', 'Ears', [['round', 'Round'], ['pointy', 'Pointy'], ['cat', 'Cat'], ['none', 'None']]],
    ['mood', 'Mood', [['happy', 'Happy'], ['neutral', 'Chill'], ['grumpy', 'Grumpy']]],
    ['lips', 'Lips', 'lips'],
    ['Hair & hats'],
    ['hair', 'Hair', [['none', 'Bald'], ['short', 'Short'], ['buzz', 'Buzz'], ['spiky', 'Spiky'], ['flat', 'Flat top'], ['swoop', 'Swoop'], ['afro', 'Afro'], ['long', 'Long'],
        ['ponytail', 'Ponytail'], ['pigtails', 'Pigtails'], ['bun', 'Bun'], ['dreads', 'Dreads'], ['mohawk', 'Mohawk']]],
    ['hairColor', 'Hair color', 'hair'],
    ['hat', 'Hat', [['none', 'None'], ['cap', 'Cap'], ['backcap', 'Cap backwards'], ['beanie', 'Beanie'], ['hood', 'Hood up'], ['tophat', 'Top hat'], ['cowboy', 'Cowboy'], ['crown', 'Crown'], ['wizard', 'Wizard']]],
    ['hatColor', 'Accent (hat, tie, headphones, mug)', 'cloth'],
    ['facial', 'Face fuzz', [['none', 'None'], ['stubble', 'Stubble'], ['mustache', 'Mustache'], ['goatee', 'Goatee'], ['beard', 'Beard']]],
    ['Body'],
    ['body', 'Build', [['classic', 'Classic'], ['chunky', 'Chunky'], ['slim', 'Slim'], ['bean', 'Bean']]],
    ['bodyWidth', 'Width', [0.8, 1.3, 0.05]],
    ['top', 'Top', [['tee', 'Tee'], ['hoodie', 'Hoodie'], ['jacket', 'Jacket'], ['suit', 'Suit'], ['tank', 'Tank'], ['jersey', 'Jersey'], ['labcoat', 'Lab coat'], ['overalls', 'Overalls'], ['robe', 'Robe'], ['dress', 'Dress']]],
    ['topColor', 'Top color', 'cloth'],
    ['pants', 'Pants', 'cloth'],
    ['shoes', 'Shoes', 'cloth'],
    ['Extras'],
    ['acc', 'Accessories', [['glasses', 'Glasses'], ['shades', 'Shades'], ['visor', 'Cyber visor'], ['eyepatch', 'Eye patch'], ['chain', 'Gold chain'], ['tie', 'Tie'], ['bowtie', 'Bow tie'],
        ['scarf', 'Scarf'], ['headphones', 'Headphones'], ['earring', 'Earring'], ['gloves', 'Gloves'], ['blush', 'Blush'], ['freckles', 'Freckles']], true],
    ['prop', 'Holding / desk', [['none', 'Nothing'], ['mic', 'Mic'], ['coffee', 'Coffee'], ['phone', 'Phone'], ['guitar', 'Guitar'], ['gamepad', 'Gamepad'],
        ['computer', 'Computer desk'], ['laptop', 'Laptop'], ['newsdesk', 'News desk']]],
    ['bg', 'Background', [['stage', 'Stage'], ['sky', 'Sky'], ['studio', 'Studio'], ['hacker', 'Hacker den'], ['city', 'City night'], ['news', 'Newsroom'], ['matrix', 'Matrix rain'],
        ['space', 'Space'], ['synthwave', 'Synthwave'], ['color', 'Solid'], ['green', 'Green screen'], ['image', '🖼 Picture']]],
    ['bgColor', 'Studio / solid / desk color', 'cloth'],
];
const TOON_DEFAULT = ['name' => '', 'style' => 'classic', 'avatar' => 'mpfb', 'head' => 'round', 'skin' => '#f2c79a', 'headSize' => 1, 'eyes' => 'round', 'eyeColor' => '#5a3b1f', 'brows' => 'thick',
    'nose' => 'button', 'ears' => 'round', 'mood' => 'happy', 'lips' => 'auto', 'hair' => 'spiky', 'hairColor' => '#2d1c12', 'hat' => 'none', 'hatColor' => '#e63946',
    'facial' => 'none', 'body' => 'classic', 'bodyWidth' => 1, 'top' => 'hoodie', 'topColor' => '#ffd23f', 'pants' => '#2b2d42', 'shoes' => '#111111',
    'acc' => ['chain'], 'prop' => 'none', 'bg' => 'stage', 'bgColor' => '#3a86ff', 'bgImage' => ''];
// starting points for people and agents; any field can be overridden
const TOON_PRESETS = [
    'hacker'    => ['name' => 'Zero Cool', 'style' => 'ascii', 'head' => 'oval', 'skin' => '#d9a066', 'eyes' => 'sleepy', 'brows' => 'thin', 'mood' => 'neutral', 'hair' => 'short',
        'hairColor' => '#111111', 'hat' => 'hood', 'facial' => 'stubble', 'top' => 'hoodie', 'topColor' => '#111111', 'acc' => ['glasses'], 'prop' => 'computer', 'bg' => 'hacker'],
    'anchor'    => ['name' => 'Ron Newsly', 'head' => 'square', 'hair' => 'swoop', 'hairColor' => '#5b3a1e', 'top' => 'suit', 'topColor' => '#2b2d42', 'acc' => ['tie'],
        'prop' => 'newsdesk', 'bg' => 'news', 'bgColor' => '#3a86ff'],
    'rapper'    => ['name' => 'MC Toon', 'hair' => 'short', 'hat' => 'backcap', 'hatColor' => '#e63946', 'top' => 'jersey', 'topColor' => '#111111', 'acc' => ['chain', 'shades', 'earring'],
        'prop' => 'mic', 'bg' => 'stage'],
    'anime'     => ['name' => 'Kai', 'style' => 'anime', 'head' => 'egg', 'eyes' => 'sparkle', 'eyeColor' => '#2e86de', 'nose' => 'none', 'brows' => 'thin', 'hair' => 'swoop',
        'hairColor' => '#3a86ff', 'top' => 'jacket', 'topColor' => '#e63946', 'acc' => [], 'bg' => 'city'],
    'wizard'    => ['name' => 'Gandoof', 'hair' => 'long', 'hairColor' => '#f4f1e1', 'hat' => 'wizard', 'hatColor' => '#8338ec', 'facial' => 'beard', 'top' => 'robe', 'topColor' => '#8338ec',
        'acc' => [], 'bg' => 'space'],
    'streamer'  => ['name' => 'xXGamerXx', 'style' => 'neon', 'hair' => 'spiky', 'hairColor' => '#06d6a0', 'top' => 'hoodie', 'topColor' => '#ff7eb6', 'hatColor' => '#3a86ff',
        'acc' => ['headphones'], 'prop' => 'gamepad', 'bg' => 'synthwave'],
    'robot'     => ['name' => 'B0B', 'skin' => '#e0e0e0', 'eyes' => 'robot', 'eyeColor' => '#00e5ff', 'brows' => 'none', 'nose' => 'none', 'ears' => 'none', 'hair' => 'none',
        'lips' => '#222222', 'top' => 'overalls', 'topColor' => '#7a7a7a', 'pants' => '#3a86ff', 'acc' => [], 'bg' => 'space'],
    'cowboy'    => ['name' => 'Tex', 'hat' => 'cowboy', 'hatColor' => '#6b4226', 'facial' => 'mustache', 'hair' => 'short', 'top' => 'jacket', 'topColor' => '#6b4226', 'acc' => ['scarf'],
        'prop' => 'guitar', 'bg' => 'sky'],
    'scientist' => ['name' => 'Dr. Volt', 'hair' => 'afro', 'hairColor' => '#f4f1e1', 'top' => 'labcoat', 'topColor' => '#3a86ff', 'acc' => ['glasses', 'bowtie'], 'prop' => 'coffee',
        'bg' => 'studio', 'bgColor' => '#06d6a0'],
];
const TOON_FONTS = [['Archivo Black', 400], ['Bangers', 400], ['Luckiest Guy', 400], ['Permanent Marker', 400], ['Bebas Neue', 400], ['Anton', 400],
    ['Fredoka', 600], ['Comic Neue', 700], ['Press Start 2P', 400], ['Pacifico', 400], ['Creepster', 400], ['Roboto', 900]];
// Kokoro TTS voices (a = American, b = British; f/m). When "your voice" is on, this only sets the delivery — Seed-VC swaps the timbre.
const TTS_VOICES = [['am_michael', 'Michael (US man)'], ['am_adam', 'Adam (US man, deep)'], ['am_eric', 'Eric (US man)'], ['am_liam', 'Liam (US man)'], ['am_puck', 'Puck (US man, playful)'],
    ['am_fenrir', 'Fenrir (US man, gruff)'], ['am_onyx', 'Onyx (US man, low)'], ['af_heart', 'Heart (US woman)'], ['af_bella', 'Bella (US woman)'], ['af_nicole', 'Nicole (US woman, soft)'],
    ['af_sarah', 'Sarah (US woman)'], ['af_sky', 'Sky (US woman)'], ['bm_george', 'George (UK man)'], ['bm_fable', 'Fable (UK man)'], ['bm_lewis', 'Lewis (UK man)'],
    ['bf_emma', 'Emma (UK woman)'], ['bf_isabella', 'Isabella (UK woman)']];
const SUB_STYLES = ['off', 'karaoke', 'subtitle', 'pop'];
const CAP_KINDS = ['title' => '#ffd23f', 'lower' => '#e63946', 'bubble' => '#111111'];

// ───────────────────────────── CLI ─────────────────────────────

// ───────────────────────────── helpers ─────────────────────────────
function find_bin(string $name): ?string {
    foreach (explode(':', SYS_PATH) as $d) if (is_executable("$d/$name")) return "$d/$name";
    return null;
}
function find_python(): ?string {
    $cands = ['python3.11', 'python3.10', 'python3.12', 'python3'];
    $dirs = array_merge(explode(':', SYS_PATH), [getenv('HOME') . '/.local/bin']);
    foreach ($cands as $c) foreach ($dirs as $d) {
        $p = "$d/$c";
        if (!is_executable($p)) continue;
        $v = trim((string)shell_exec(escapeshellarg($p) . ' -c "import sys;print(sys.version_info[1])" 2>/dev/null'));
        if (in_array($v, ['10', '11', '12'], true)) return $p;
    }
    return null;
}
function engine_ready(): bool {
    return is_file(ENGINE . '/.ready') && is_executable(ENGINE . '/venv/bin/python') && is_file(ENGINE . '/seed-vc/inference.py');
}
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function valid_id(string $id): bool { return (bool)preg_match('/^[a-z0-9_-]{6,64}$/', $id); }
function slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-') ?: 'voice'; }
function pid_alive(int $pid): bool { return $pid > 0 && posix_kill($pid, 0); }
function read_json(string $f): array { return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }

function job_info(string $id): ?array {
    $dir = JOBS . "/$id";
    if (!is_dir($dir)) return null;
    $job = read_json("$dir/job.json");
    $st = read_json("$dir/status.json") + ['state' => 'queued', 'stage' => 'Waiting…', 'progress' => 0];
    $pid = (int)@file_get_contents("$dir/pid");
    if (in_array($st['state'], ['queued', 'running'], true) && !pid_alive($pid) && filemtime($dir) < time() - 5) {
        $st['state'] = 'error';
        $st['error'] = $st['error'] ?? 'Worker stopped unexpectedly — see log.';
    }
    $log = @file_get_contents("$dir/log.txt") ?: '';
    $log = preg_replace('/[^\n]*\r/', '', substr($log, -6000));
    return ['id' => $id, 'job' => $job, 'status' => $st, 'log' => implode("\n", array_slice(explode("\n", $log), -14))];
}

function write_engine_files(): void {
    @mkdir(ENGINE, 0755, true);
    $install = str_replace(['__REPO__', '__COMMIT__'], [SEEDVC_REPO, SEEDVC_COMMIT], INSTALL_SH);
    foreach (['worker.py' => WORKER_PY, 'install.sh' => $install, 'gen.py' => GEN_PY] as $f => $src) {
        $p = ENGINE . "/$f";
        if (!is_file($p) || md5_file($p) !== md5($src)) file_put_contents($p, $src);
    }
}

function spawn_worker(string $dir, string $flag = ''): void {
    // subshell + exec: the PID we record is the python process itself, and no fd keeps this request open
    $cmd = '(cd ' . escapeshellarg(ENGINE) . ' && exec nohup env PATH=' . escapeshellarg(SYS_PATH)
        . ' ./venv/bin/python worker.py ' . ($flag ? "$flag " : '') . escapeshellarg($dir)
        . ') < /dev/null > ' . escapeshellarg("$dir/log.txt") . ' 2>&1 & echo $!';
    file_put_contents("$dir/pid", trim((string)shell_exec($cmd)));
}

function serve_file(string $f, string $type): void {
    // Range support: Safari won't play <audio>/<video> without it
    $size = filesize($f);
    $start = 0; $end = $size - 1;
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
        if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        elseif ($m[2] !== '') $start = max(0, $size - (int)$m[2]);
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header("Content-Type: $type");
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . addslashes(basename($f)) . '"');
    $fh = fopen($f, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); $left -= strlen($chunk); echo $chunk; }
    exit;
}
function new_id(): string { return date('ymd-His') . '-' . bin2hex(random_bytes(3)); }

// ── text → speech ──
function speakable(string $t): string {
    // markdown / plain text → something a voice can read out loud
    $t = str_replace("\r", '', $t);
    $t = preg_replace('/```.*?```/s', ' ', $t);
    $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $t);
    $t = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $t);
    $t = preg_replace_callback('/^\s{0,3}#{1,6}\s+(.+?)\s*#*$/m', fn($m) => $m[1] . (preg_match('/[.!?:]$/', $m[1]) ? '' : '.'), $t);
    $t = preg_replace('/^\s{0,3}(>|[-*+]|\d+[.)])\s+/m', '', $t);
    $t = preg_replace('/^\s*([-*_]\s*){3,}$/m', '', $t);
    $t = preg_replace('/<[^>]+>/', '', $t);
    $t = str_replace(['**', '__', '~~', '`'], '', $t);
    $t = preg_replace('/(?<!\w)\*(\S[^*]*?)\*(?!\w)/', '$1', $t);
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim(mb_substr($t, 0, 20000));
}
function voice_file(string $name): ?string {
    // a saved voice by file name or display name (case-insensitive)
    if ($name === '') return null;
    foreach (glob(VOICES . '/*') as $f)
        if (strcasecmp(basename($f), $name) === 0 || strcasecmp(pathinfo($f, PATHINFO_FILENAME), $name) === 0 || slug(pathinfo($f, PATHINFO_FILENAME)) === slug($name)) return $f;
    return null;
}
function tts_voice(string $v): string { return in_array($v, array_column(TTS_VOICES, 0), true) ? $v : 'am_michael'; }

// ── toon specs (from the page, the Director or an agent) → always validated here ──
function clampf($v, float $lo, float $hi, float $def): float { return is_numeric($v) ? max($lo, min($hi, (float)$v)) : $def; }
function hexcol($v, string $def): string { return is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $def; }
// Realistic 3D people: any rigged GLB with a Mixamo-style skeleton and ARKit + Oculus viseme blend shapes (TalkingHead format).
// avatars/<name>.json may give a label and license. The two defaults come from the TalkingHead project.
const AVATAR_SRC = 'https://raw.githubusercontent.com/met4citizen/TalkingHead/main/avatars/';
// who plays each preset in the Realistic 3D style (people made with the Blender builder; falls back to Maya when missing)
const PRESET_AVATAR = ['hacker' => 'marcus', 'anchor' => 'walter', 'rapper' => 'theo', 'anime' => 'brunette', 'wizard' => 'walter', 'streamer' => 'ken',
    'robot' => 'mpfb', 'cowboy' => 'marcus', 'scientist' => 'rosa'];
function toon_presets(): array {
    $have = array_column(toon_avatars(), 0);
    $out = TOON_PRESETS;
    foreach ($out as $k => &$p) if (in_array(PRESET_AVATAR[$k] ?? '', $have, true)) $p['avatar'] = PRESET_AVATAR[$k];
    return $out;
}
const AVATAR_DEFAULTS = [
    'mpfb'     => ['label' => 'Maya', 'license' => 'CC0, made with Blender + MPFB', 'mouth' => 0.7, 'tongue' => 0.6],
    'brunette' => ['label' => 'Brooke', 'license' => 'CC BY-NC 4.0, personal / non-commercial use'],
];
function avatar_meta(string $k): array {   // label, license, mouth (viseme strength), tongue (tongue shape strength)
    $m = is_file(AVATARS . "/$k.json") ? json_decode((string)file_get_contents(AVATARS . "/$k.json"), true) : null;
    return (is_array($m) ? $m : []) + (AVATAR_DEFAULTS[$k] ?? []) + ['label' => ucwords(str_replace(['-', '_'], ' ', $k)), 'mouth' => 1, 'tongue' => 1];
}
function toon_avatars(bool $meta = false): array {
    $out = [];
    foreach (glob(AVATARS . '/*.glb') ?: [] as $f) {
        $k = basename($f, '.glb');
        if (!preg_match('/^[\w.-]+$/', $k)) continue;
        $m = avatar_meta($k);
        $out[] = $meta ? ['id' => $k] + $m : [$k, (string)$m['label']];
    }
    $id = fn($r) => $r['id'] ?? $r[0];
    $rank = fn($r) => ($i = array_search($id($r), array_keys(AVATAR_DEFAULTS), true)) === false ? 99 : $i;   // Maya, Brooke, then everyone made here A–Z
    usort($out, fn($a, $b) => ($rank($a) <=> $rank($b)) ?: strcmp($id($a), $id($b)));
    return $out;
}
// ── 3D people builder: Blender + MPFB (MakeHuman, CC0 assets) make a new rigged person with lip-sync face shapes ──
define('PERSON_DATA', ENGINE . '/mpfb');   // asset-pack zips + TalkingHead rig files (downloaded once)
const TH_BLENDER = 'https://raw.githubusercontent.com/met4citizen/TalkingHead/main/blender/MPFB/';
const PERSON_DL = [
    'makehuman_system_assets' => ['makehuman_system_assets.zip', 'https://files2.makehumancommunity.org/asset_packs/makehuman_system_assets/makehuman_system_assets_cc0.zip', '280 MB'],
    'visemes02'               => ['visemes02.zip', 'https://files2.makehumancommunity.org/functional/visemes02.zip', ''],
    'faceunits01'             => ['faceunits01.zip', 'https://files2.makehumancommunity.org/functional/faceunits01.zip', ''],
    'talkinghead'             => ['talkinghead.mpfbskel', TH_BLENDER . 'talkinghead.mpfbskel', '', 'talkinghead.mhw'],
    'talkinghead-addon.py'    => ['talkinghead-addon.py', TH_BLENDER . 'talkinghead-addon.py', ''],
    'talkinghead-targets.zip' => ['talkinghead-targets.zip', TH_BLENDER . 'talkinghead-targets.zip', ''],
];
const PERSON_OPTS = [
    ['body', 'Body', [['man', 'Man'], ['woman', 'Woman'], ['neutral', 'In between']]],
    ['age', 'Age', [18, 90, 1]],
    ['build', 'Build', [['slim', 'Slim'], ['average', 'Average'], ['athletic', 'Athletic'], ['heavy', 'Heavy']]],
    ['height', 'Height', [['short', 'Short'], ['average', 'Average'], ['tall', 'Tall']]],
    ['ethnicity', 'Ethnicity (face shape)', [['african', 'African'], ['asian', 'Asian'], ['caucasian', 'Caucasian'], ['mixed', 'Mixed']]],
    ['tone', 'Skin tone', [['auto', 'Match ethnicity'], ['light', 'Light'], ['medium', 'Medium'], ['tan', 'Tan'], ['brown', 'Brown'], ['dark', 'Dark']]],
    ['hair', 'Hair', [['short01', 'Short 1'], ['short02', 'Short 2'], ['short03', 'Short 3'], ['short04', 'Short 4'], ['afro01', 'Afro'], ['bob01', 'Bob 1'],
        ['bob02', 'Bob 2'], ['braid01', 'Braid'], ['ponytail01', 'Ponytail'], ['long01', 'Long'], ['none', 'Bald']]],
    ['hairColor', 'Hair color', [['#1b1410', 'Black'], ['#3b2416', 'Dark brown'], ['#6a4426', 'Brown'], ['#a8763e', 'Light brown'], ['#d9b26b', 'Blonde'],
        ['#8f3a1c', 'Red'], ['#9a9a9a', 'Grey'], ['#e8e6e0', 'White']]],
    ['eyebrows', 'Eyebrows', [['eyebrow001', '1'], ['eyebrow002', '2'], ['eyebrow003', '3'], ['eyebrow004', '4'], ['eyebrow005', '5'], ['eyebrow006', '6'],
        ['eyebrow007', '7'], ['eyebrow008', '8'], ['eyebrow009', '9'], ['eyebrow010', '10'], ['eyebrow011', '11'], ['eyebrow012', '12']]],
    ['eyes', 'Eyes', [['brown', 'Brown'], ['brownlight', 'Light brown'], ['blue', 'Blue'], ['lightblue', 'Light blue'], ['deepblue', 'Deep blue'],
        ['bluegreen', 'Blue-green'], ['green', 'Green'], ['grey', 'Grey'], ['ice', 'Ice']]],
    ['outfit', 'Outfit', [['male_casualsuit04', 'Blue tee + jeans'], ['male_casualsuit06', 'White tee + jeans'], ['male_casualsuit02', 'Long-sleeve tee + jeans'],
        ['male_casualsuit01', 'Navy shirt + dark jeans'], ['male_casualsuit03', 'Striped shirt + jeans'], ['male_casualsuit05', 'Jacket + jeans'],
        ['male_elegantsuit01', 'Black suit + tie'], ['male_worksuit01', 'Overalls'], ['female_casualsuit01', 'Fitted tee + jeans'],
        ['female_casualsuit02', 'Tee + denim shorts'], ['female_elegantsuit01', 'Blouse + skirt'], ['female_sportsuit01', 'Crop top + leggings']]],
    ['shoes', 'Shoes', [['shoes01', '1'], ['shoes02', '2'], ['shoes03', '3'], ['shoes04', '4'], ['shoes05', '5'], ['shoes06', '6'], ['none', 'Barefoot']]],
];
const PERSON_DEFAULT = ['name' => '', 'body' => 'man', 'age' => 28, 'build' => 'average', 'height' => 'average', 'ethnicity' => 'caucasian',
    'tone' => 'auto', 'hair' => 'short02', 'hairColor' => '#3b2416', 'eyebrows' => 'eyebrow001', 'eyes' => 'brown', 'outfit' => 'male_casualsuit04', 'shoes' => 'shoes01'];
// MakeHuman's weight/muscle macros alone barely change the silhouette (a few mm), so each build also dials in body-part shapes
const PERSON_BUILD_SHAPES = [
    'slim' => ['measure-waist-circ-decr' => 0.6, 'stomach-pregnant-decr' => 0.4, 'torso-scale-horiz-decr' => 0.25, 'hip-scale-horiz-decr' => 0.2,
        '*-upperarm-fat-decr' => 0.7, '*-lowerarm-fat-decr' => 0.5, '*-upperleg-fat-decr' => 0.6, '*-lowerleg-fat-decr' => 0.4, 'measure-neck-circ-decr' => 0.4],
    'average' => [],
    'athletic' => ['torso-vshape-incr' => 0.7, 'torso-muscle-pectoral-incr' => 0.6, 'torso-muscle-dorsi-incr' => 0.6, 'measure-shoulder-dist-incr' => 0.4,
        'measure-waist-circ-decr' => 0.3, 'stomach-tone-incr' => 0.6, '*-upperarm-muscle-incr' => 0.8, '*-upperarm-shoulder-muscle-incr' => 0.7,
        '*-lowerarm-muscle-incr' => 0.6, '*-upperleg-muscle-incr' => 0.6, '*-lowerleg-muscle-incr' => 0.5, 'measure-neck-circ-incr' => 0.4],
    'heavy' => ['stomach-pregnant-incr' => 0.75, 'measure-waist-circ-incr' => 1.0, 'torso-scale-depth-incr' => 0.6, 'torso-scale-horiz-incr' => 0.4,
        'measure-bust-circ-incr' => 0.5, 'hip-scale-horiz-incr' => 0.4, 'hip-scale-depth-incr' => 0.4, 'buttocks-volume-incr' => 0.6,
        '*-upperarm-fat-incr' => 1.0, '*-lowerarm-fat-incr' => 0.6, '*-upperleg-fat-incr' => 0.9, '*-lowerleg-fat-incr' => 0.5,
        'neck-double-incr' => 0.8, 'measure-neck-circ-incr' => 0.7],
];
// skin tone → which MakeHuman skin texture, and an sRGB multiplier on it (null = the texture as it is)
const PERSON_TONES = ['light' => ['caucasian', null], 'medium' => ['asian', [0.95, 0.88, 0.80]], 'tan' => ['asian', [0.84, 0.72, 0.62]],
    'brown' => ['asian', [0.66, 0.53, 0.44]], 'dark' => ['african', null]];
function find_blender(): ?string {
    $home = getenv('HOME') ?: '';
    foreach (['/Applications', "$home/Applications"] as $d) if (is_executable("$d/Blender.app/Contents/MacOS/Blender")) return "$d/Blender.app/Contents/MacOS/Blender";
    return find_bin('blender');
}
function person_normalize($in): array {   // the builder's choices, cleaned up
    $in = is_array($in) ? $in : [];
    $out = PERSON_DEFAULT;
    if (($in['body'] ?? '') === 'woman' && !isset($in['outfit'])) $out = ['hair' => 'ponytail01', 'outfit' => 'female_casualsuit01', 'eyebrows' => 'eyebrow005'] + $out;
    $out['name'] = mb_substr(trim((string)($in['name'] ?? '')), 0, 40);
    foreach (PERSON_OPTS as [$k, , $spec]) {
        if (!array_key_exists($k, $in)) continue;
        if (is_numeric($spec[0])) $out[$k] = (int)round(clampf($in[$k], $spec[0], $spec[1], $out[$k]));
        elseif (in_array($in[$k], array_column($spec, 0), true)) $out[$k] = $in[$k];
    }
    if (preg_match('/^#[0-9a-f]{6}$/i', (string)($in['hairColor'] ?? ''))) $out['hairColor'] = strtolower($in['hairColor']);   // any color, not just the chips
    return $out;
}
function person_blender_spec(array $p): array {   // builder choices → MakeHuman macro sliders and asset names
    $y = $p['age'];
    $age = $y <= 25 ? 0.1875 + ($y - 11) / 14 * 0.3125 : 0.5 + ($y - 25) / 65 * 0.5;   // MakeHuman: 0.1875 = 11 yrs, 0.5 = 25, 1 = 90
    [$weight, $muscle] = ['slim' => [0.15, 0.4], 'average' => [0.5, 0.5], 'athletic' => [0.45, 1.0], 'heavy' => [1.0, 0.5]][$p['build']];
    $targets = [];
    foreach (PERSON_BUILD_SHAPES[$p['build']] as $t => $v) foreach (str_starts_with($t, '*-') ? ['l' . substr($t, 1), 'r' . substr($t, 1)] : [$t] as $n) $targets[$n] = $v;
    $race = ['african' => ['african' => 0.9, 'asian' => 0.05, 'caucasian' => 0.05], 'asian' => ['african' => 0.05, 'asian' => 0.9, 'caucasian' => 0.05],
        'caucasian' => ['african' => 0.05, 'asian' => 0.05, 'caucasian' => 0.9], 'mixed' => ['african' => 0.4, 'asian' => 0.2, 'caucasian' => 0.4]][$p['ethnicity']];
    $sex = $p['body'] === 'woman' ? 'female' : 'male';
    $tone = $p['tone'] !== 'auto' ? $p['tone'] : ['african' => 'dark', 'asian' => 'medium', 'caucasian' => 'light', 'mixed' => 'tan'][$p['ethnicity']];
    [$tex, $tint] = PERSON_TONES[$tone];
    if ($p['tone'] === 'auto' && $p['ethnicity'] === 'asian') $tint = null;   // MakeHuman's own Asian skin
    $skin = ($y < 36 ? 'young' : ($y < 55 ? 'middleage' : 'old')) . "_{$tex}_$sex";
    return ['name' => $p['name'] ?: 'Person', 'gender' => ['man' => 1, 'woman' => 0, 'neutral' => 0.5][$p['body']], 'age' => round($age, 3),
        'weight' => $weight, 'muscle' => $muscle, 'height' => ['short' => 0.3, 'average' => 0.5, 'tall' => 0.78][$p['height']], 'race' => $race,
        'skin' => $skin, 'skin_tint' => $tint, 'targets' => (object)$targets, 'hair' => $p['hair'] === 'none' ? '' : $p['hair'], 'eyebrows' => $p['eyebrows'], 'eyes' => $p['eyes'],
        'clothes' => array_values(array_filter([$p['outfit'], $p['shoes'] === 'none' ? '' : $p['shoes']])), 'data' => PERSON_DATA];
}
function person_id(string $name): string {
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'person';
    if (isset(AVATAR_DEFAULTS[$base])) $base .= '-2';
    $id = $base;
    for ($i = 2; is_file(AVATARS . "/$id.glb"); $i++) $id = "$base-$i";
    return $id;
}
// ── scenes, music and news for Toon Lab (engine/gen.py does the heavy lifting) ──
const SCENE_STYLE = ['classic' => 'cartoon background art, bold flat colors', 'anime' => 'anime background painting, soft light', 'comic' => 'comic book background art, ink lines',
    'sketch' => 'pencil sketch background', 'clay' => 'claymation diorama background', 'pixel' => 'pixel art background, 16-bit game', 'neon' => 'neon-lit synthwave background',
    'noir' => 'black and white film noir background', 'blueprint' => 'technical blueprint style background', 'real' => 'photorealistic, cinematic photo',
    'ascii' => 'moody cinematic background', 'matrix' => 'dark green cyberpunk background'];
function gen_py(array $args): array {   // run engine/gen.py, return its JSON line
    write_engine_files();
    $cmd = 'cd ' . escapeshellarg(ENGINE) . ' && env -u HF_HOME PATH=' . escapeshellarg(SYS_PATH) . ' ./venv/bin/python gen.py ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    foreach (array_reverse(explode("\n", trim($out))) as $l) if (str_starts_with($l, '{') && is_array($j = json_decode($l, true))) return $j;
    return ['error' => 'The generator failed: ' . mb_substr(trim($out), -400)];
}
function media_list(string $dir, string $re): array {
    $out = [];
    foreach (glob("$dir/*") ?: [] as $f) {
        if (!preg_match($re, $f)) continue;
        $m = read_json(preg_replace('/\.\w+$/', '.json', $f));
        $out[] = $m + ['file' => basename($f), 'title' => pathinfo($f, PATHINFO_FILENAME), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
function media_name(string $title, string $ext, string $dir): string {   // "rainy-city-night-5f3a.jpg"
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(mb_substr($title, 0, 40))), '-') ?: 'item';
    return "$slug-" . bin2hex(random_bytes(2)) . ".$ext";
}
function scene_generate(string $prompt, string $aspect = '16:9', string $style = '', string $provider = 'hf'): array {
    @mkdir(SCENES, 0755, true);
    [$w, $h] = ['16:9' => [1280, 720], '9:16' => [720, 1280], '1:1' => [1024, 1024]][$aspect] ?? [1280, 720];
    $full = trim($prompt) . ', ' . (SCENE_STYLE[$style] ?? 'cinematic') . ', an empty deserted place with nobody in it, wide establishing shot, background plate, highly detailed';
    $file = media_name($prompt, 'jpg', SCENES); $out = SCENES . "/$file";
    if ($provider === 'free') {   // Pollinations: free and keyless, lower quality, small watermark
        $ch = curl_init('https://image.pollinations.ai/prompt/' . rawurlencode($full) . "?width=$w&height=$h&nologo=true&model=flux&seed=" . random_int(1, 999999));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true, CURLOPT_NOSIGNAL => true]);
        $img = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($code !== 200 || !str_starts_with($type, 'image/')) throw new RuntimeException("The free image service didn't answer (HTTP $code). Try again in a minute, or use Hugging Face.");
        file_put_contents($out, $img);
    } else {
        $r = gen_py(['image', $full, (string)$w, (string)$h, $out]);
        if (empty($r['ok'])) throw new RuntimeException($r['error'] ?? 'Image generation failed.');
    }
    $meta = ['title' => mb_substr(trim($prompt), 0, 60), 'prompt' => $prompt, 'source' => $provider === 'free' ? 'Pollinations (FLUX)' : 'Hugging Face (FLUX.1-schnell)', 'aspect' => $aspect];
    file_put_contents(preg_replace('/\.jpg$/', '.json', $out), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $meta + ['file' => $file, 'time' => time()];
}
function media_import(string $src, string $dir, array $exts, string $title): ?string {   // copy a file the user or an agent points at into the library
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if (!is_file($src) || !in_array($ext, $exts, true)) return null;
    @mkdir($dir, 0755, true);
    $file = media_name($title ?: pathinfo($src, PATHINFO_FILENAME), $ext, $dir);
    copy($src, "$dir/$file");
    file_put_contents(preg_replace('/\.\w+$/', '.json', "$dir/$file"), json_encode(['title' => mb_substr($title ?: pathinfo($src, PATHINFO_FILENAME), 0, 60), 'source' => 'imported']));
    return $file;
}
function musicgen_ready(): bool {
    return (bool)glob((getenv('HOME') ?: '') . '/.cache/huggingface/hub/models--facebook--musicgen-small/snapshots/*/model.safetensors');
}
function music_start(string $prompt, float $seconds): array {
    if (!musicgen_ready() && disk_free_space(BASE) < 4.5e9)
        throw new RuntimeException('MusicGen needs a one-time 2.4 GB download, and this disk has only ' . round(disk_free_space(BASE) / 1e9, 1) . ' GB free. Free up some space first, or upload a track.');
    @mkdir(MUSIC . '/.jobs', 0755, true);
    $id = new_id(); $d = MUSIC . "/.jobs/$id"; mkdir($d);
    $file = media_name($prompt, 'mp3', MUSIC);
    file_put_contents("$d/job.json", json_encode(['prompt' => $prompt, 'seconds' => $seconds, 'out' => MUSIC . "/$file"]));
    file_put_contents(MUSIC . '/' . preg_replace('/\.mp3$/', '.json', $file), json_encode(['title' => mb_substr($prompt, 0, 60), 'prompt' => $prompt, 'source' => 'MusicGen (made here)'], JSON_UNESCAPED_UNICODE));
    write_engine_files();
    $cmd = '(cd ' . escapeshellarg(ENGINE) . ' && exec nohup env -u HF_HOME PATH=' . escapeshellarg(SYS_PATH) . ' ./venv/bin/python gen.py music ' . escapeshellarg($d)
        . ') < /dev/null > ' . escapeshellarg("$d/log.txt") . ' 2>&1 & echo $!';
    file_put_contents("$d/pid", trim((string)shell_exec($cmd)));
    return ['id' => $id, 'file' => $file];
}
function news_search(string $q): array {
    $r = gen_py(['news', $q, '8']);
    if (isset($r['error'])) throw new RuntimeException($r['error']);
    return $r['stories'] ?? [];
}
function news_article(string $url): string {   // the story's own text (paragraphs only), for the Director to work from
    if (!preg_match('#^https?://#', $url)) return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_NOSIGNAL => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36']);
    $html = (string)curl_exec($ch);
    if ($html === '') return '';
    $doc = new DOMDocument(); libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html); libxml_clear_errors();
    $text = [];
    foreach ($doc->getElementsByTagName('p') as $p) {
        $t = trim(preg_replace('/\s+/', ' ', $p->textContent));
        if (mb_strlen($t) > 60) $text[] = $t;
    }
    return mb_substr(implode("\n", $text), 0, 7000);
}
// ── Game Creator: saved games, voxel buddies, and the two things the local model does for it ──
function game_list(): array {
    $out = [];
    foreach (glob(GAMES . '/*/game.json') ?: [] as $f) {
        $g = read_json($f); $id = basename(dirname($f));
        if (!valid_id($id) || !$g) continue;
        $out[] = ['id' => $id, 'title' => $g['title'] ?? 'Game', 'mold' => $g['mold'] ?? '', 'levels' => count($g['levels'] ?? []), 'hero' => $g['cast']['hero']['name'] ?? '',
            'time' => filemtime($f), 'thumb' => is_file(GAMES . "/$id/thumb.jpg"), 'size' => @filesize(GAMES . "/$id/index.html") ?: 0];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
function buddy_list(): array {
    $out = [];
    foreach (glob(BUDDIES . '/*.json') ?: [] as $f) { $b = read_json($f); if ($b) $out[] = ['id' => basename($f, '.json')] + $b; }
    usort($out, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
    return $out;
}
const VOX_SYSTEM = <<<'TXT'
You design cute voxel characters. You are given a prompt and you return a build sheet: a list of solid parts that a renderer stacks into one blocky character. Reply with ONE JSON object and nothing else:
{"name":"two-word name","palette":{"body":"#RRGGBB","body2":"#..","belly":"#..","accent":"#..","accent2":"#..","dark":"#..","eyew":"#..","blush":"#..","gold":"#..","metal":"#.."},"parts":[{"shape":"box","x":0,"y":0,"z":0,"w":7,"h":6,"d":5,"color":"body","bevel":1,"mirror":false}],"eyes":{"y":12,"spread":2,"style":"big"},"mouth":{"y":10,"mood":"happy"}}

COORDINATES: x runs left-right, x=0 is the mirror plane. y runs up, y=0 is the ground. z runs front-back, +z is where the face looks. A box: (x,z) is the CENTRE, y the BOTTOM; w width, h height, d depth; prefer odd w and d. A dome: centre of its flat base at (x,y,z), half-ellipsoid with radii rx ry rz. A cyl: (x,z) centre, y bottom, radius rx by rz, height h.
MIRRORING: anything paired (legs, arms, ears, wings, horns) is ONE part with "mirror": true on the positive-x side. Never emit both copies. Single things (torso, head, tail, hat) sit at x=0 with mirror false.
PROPORTIONS: stand 18 to 26 voxels tall, under 24 wide. The head is clearly WIDER than the torso (9-13 wide vs a 7-wide body). Short stubby limbs: legs 2-4 tall, arms 3-6. Hands and feet in a different colour than the limb. Build from the ground up: feet, legs, torso, arms, head, then muzzle/ears/tail/hat. Later parts paint over earlier ones. A belly patch one voxel proud of the torso front, in "belly", is worth it. bevel 1 on the torso, 2 on the head. Every part must touch another part. 6 to 40 parts.
THE FACE IS PLACED FOR YOU: do not build eyes or a mouth. Give eyes a height (a little below the middle of the head) and a spread (about a quarter of the head width); style big, dot or huge. Mouth y is 2-3 below the eyes; mood happy, calm, smug, sleepy, surprised or none. A muzzle, beak or visor IS a part: build it just below the eyes.
COLOUR: real #RRGGBB values chosen for this buddy; parts use the role names. One hue family; "accent" is the one colour that pops; "dark" is a very dark version of the body hue.
Interpret the prompt generously and commit to it: a teapot knight is a teapot with a helmet, a spout and a handle.

Example for "a chonky penguin with a crown":
{"name":"Pengu Pebble","palette":{"body":"#1f2a44","body2":"#16203a","belly":"#f4f1ea","accent":"#f4a623","accent2":"#d98c12","dark":"#0a0f1e","eyew":"#ffffff","blush":"#f49ac1","gold":"#f2c94c","metal":"#9aa6b8"},"parts":[{"shape":"box","x":2,"y":0,"z":1,"w":3,"h":1,"d":4,"color":"accent","mirror":true},{"shape":"box","x":0,"y":1,"z":0,"w":9,"h":8,"d":7,"color":"body","bevel":1},{"shape":"box","x":0,"y":2,"z":4,"w":7,"h":6,"d":1,"color":"belly"},{"shape":"box","x":5,"y":3,"z":0,"w":1,"h":5,"d":4,"color":"body2","mirror":true},{"shape":"box","x":0,"y":9,"z":0,"w":11,"h":8,"d":9,"color":"body","bevel":2},{"shape":"box","x":0,"y":11,"z":5,"w":3,"h":2,"d":2,"color":"accent"},{"shape":"box","x":0,"y":17,"z":0,"w":7,"h":1,"d":5,"color":"gold"},{"shape":"box","x":2,"y":18,"z":0,"w":1,"h":2,"d":1,"color":"gold","mirror":true},{"shape":"box","x":0,"y":18,"z":0,"w":1,"h":2,"d":1,"color":"gold"}],"eyes":{"y":13,"spread":3,"style":"big"},"mouth":{"y":11,"mood":"happy"}}
TXT;
function vox_schema(): array {   // Ollama structured output: the shape of a build sheet
    $int = ['type' => 'integer']; $str = ['type' => 'string'];
    return ['type' => 'object', 'required' => ['name', 'palette', 'parts', 'eyes', 'mouth'], 'properties' => [
        'name' => $str,
        'palette' => ['type' => 'object', 'properties' => array_fill_keys(['body', 'body2', 'belly', 'accent', 'accent2', 'dark', 'eyew', 'blush', 'gold', 'metal'], $str)],
        'parts' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['shape', 'x', 'y', 'z', 'color'], 'properties' => [
            'shape' => ['type' => 'string', 'enum' => ['box', 'dome', 'cyl']], 'x' => $int, 'y' => $int, 'z' => $int, 'w' => $int, 'h' => $int, 'd' => $int, 'rx' => $int, 'ry' => $int, 'rz' => $int,
            'color' => $str, 'bevel' => $int, 'mirror' => ['type' => 'boolean']]]],
        'eyes' => ['type' => 'object', 'properties' => ['y' => $int, 'spread' => $int, 'style' => ['type' => 'string', 'enum' => ['big', 'dot', 'huge']]]],
        'mouth' => ['type' => 'object', 'properties' => ['y' => $int, 'mood' => ['type' => 'string', 'enum' => ['happy', 'calm', 'smug', 'sleepy', 'surprised', 'none']]]],
    ]];
}
function buddy_ai(string $prompt, string $model = ''): array {
    $text = llm_chat([['role' => 'system', 'content' => VOX_SYSTEM], ['role' => 'user', 'content' => 'Design this voxel buddy: ' . mb_substr($prompt, 0, 300)]], $model, 300, vox_schema());
    $a = strpos($text, '{'); $b = strrpos($text, '}');
    $sheet = $a !== false && $b > $a ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
    if (!is_array($sheet) || empty($sheet['parts']) || !is_array($sheet['parts']) || count($sheet['parts']) < 3) throw new RuntimeException('The model did not return a usable build sheet. It said: ' . mb_substr($text, 0, 200));
    $sheet['parts'] = array_slice(array_values(array_filter($sheet['parts'], 'is_array')), 0, 90);
    return $sheet;
}
function game_schema(array $bgs): array {
    $str = ['type' => 'string']; $int = ['type' => 'integer']; $bool = ['type' => 'boolean']; $enum = fn($v) => ['type' => 'string', 'enum' => $v];
    return ['type' => 'object', 'required' => ['title', 'pitch', 'mold', 'hero', 'enemies', 'boss', 'levels', 'rules', 'voice', 'sfx', 'music'], 'properties' => [
        'title' => $str, 'pitch' => $str, 'mold' => $enum(['platformer', 'topdown', 'shooter']),
        'hero' => ['type' => 'object', 'required' => ['name', 'toon', 'buddy'], 'properties' => ['name' => $str, 'toon' => $str, 'buddy' => $str]],
        'enemies' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name', 'buddy', 'behavior'], 'properties' => ['name' => $str, 'buddy' => $str, 'behavior' => $enum(['patrol', 'chase', 'fly', 'shoot'])]]],
        'boss' => ['type' => 'object', 'required' => ['name', 'buddy'], 'properties' => ['name' => $str, 'buddy' => $str]],
        'levels' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name', 'bg', 'length', 'difficulty', 'key', 'boss'], 'properties' => ['name' => $str, 'bg' => $enum($bgs), 'length' => $enum(['short', 'medium', 'long']), 'difficulty' => $int, 'key' => $bool, 'boss' => $bool]]],
        'rules' => ['type' => 'object', 'required' => ['lives', 'difficulty', 'win'], 'properties' => ['lives' => $int, 'difficulty' => $enum(['chill', 'normal', 'hard', 'brutal']), 'win' => $enum(['goal', 'coins', 'boss', 'survive'])]],
        'voice' => ['type' => 'object', 'required' => ['start', 'win', 'lose', 'hurt'], 'properties' => ['start' => $str, 'win' => $str, 'lose' => $str, 'hurt' => $str]],
        'sfx' => $enum(['chip', 'arcade', 'soft', 'glitch']), 'music' => $str]];
}
function game_director(string $prompt, string $model, array $ctx): array {
    $sys = "You design small retro browser games for a game creator app. The user describes a game in a sentence. Reply with ONE JSON object and nothing else:\n"
        . '{"title":"…","pitch":"one sentence shown on the title screen","mold":"platformer|topdown|shooter","hero":{"name":"…","toon":"<preset or empty>","buddy":"<a voxel buddy prompt, or empty>"},'
        . '"enemies":[{"name":"…","buddy":"<voxel buddy prompt>","behavior":"patrol|chase|fly|shoot"}],"boss":{"name":"…","buddy":"<voxel buddy prompt>"}|null,'
        . '"levels":[{"name":"…","bg":"<backdrop>","length":"short|medium|long","difficulty":1|2|3,"key":false,"boss":false}],"rules":{"lives":3,"difficulty":"chill|normal|hard|brutal","win":"goal|coins|boss|survive"},'
        . '"voice":{"start":"what the hero says when the game starts","win":"…","lose":"…","hurt":"a short grunt or line"},"sfx":"chip|arcade|soft|glitch","music":"a short description of the background music"}' . "\n"
        . "Rules:\n- mold: platformer = run and jump; topdown = overhead rooms, sword, keys; shooter = vertical space shooter.\n"
        . "- hero: use a toon preset (" . implode(', ', array_keys(TOON_PRESETS)) . ") when one fits the character, otherwise describe a voxel buddy (species + colour + accessory, e.g. \"a cyber fox with headphones\").\n"
        . "- enemies: 1 to 3, each a voxel buddy prompt; vary behaviors. boss: optional, a bigger buddy; set the last level's boss to true when there is one.\n"
        . "- levels: 1 to 4. bg is one of: " . implode(', ', $ctx['bgs']) . ". Put a key on at most one level.\n"
        . "- voice lines are short (under 12 words), in the hero's voice, no emoji.\n- Keep names fun and original.\n- Where options are written a|b|c, answer with exactly ONE of them. No boss: give boss an empty name and buddy.";
    $text = llm_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $prompt]], $model, 600, game_schema($ctx['bgs']));
    $a = strpos($text, '{'); $b = strrpos($text, '}');
    $plan = $a !== false && $b > $a ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
    if (!is_array($plan)) throw new RuntimeException('The model replied, but not with a usable plan. It said: ' . mb_substr($text, 0, 300));
    if (isset($plan['boss']) && is_array($plan['boss']) && trim((string)($plan['boss']['name'] ?? '')) === '' && trim((string)($plan['boss']['buddy'] ?? '')) === '') $plan['boss'] = null;
    foreach (['platformer' => 'platform|jump|run', 'topdown' => 'top.?down|overhead|dungeon|zelda|adventure|rooms', 'shooter' => 'shoot|shmup|space ship|spaceship|galaga|bullet'] as $m => $re)
        if (preg_match("/\\b($re)/i", $prompt)) { $plan['mold'] = $m; break; }   // words in the prompt win over the model's pick
    return $plan;
}
// Runs in the background (person-build) or in the foreground for agents (person). Status goes to $dir/status.json.
function person_build(string $dir, bool $echo = false): array {
    $st = function (string $stage, float $p, array $x = []) use ($dir, $echo) {
        $s = array_merge(read_json("$dir/status.json"), ['state' => 'running', 'stage' => $stage, 'progress' => round($p, 3)], $x);
        file_put_contents("$dir/status.json", json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($echo) fwrite(STDERR, sprintf("%3d%%  %s\n", $p * 100, $stage));
        return $s;
    };
    try {
        $p = person_normalize(read_json("$dir/spec.json"));
        $bl = find_blender();
        if (!$bl) throw new RuntimeException('Blender not found. Install it from blender.org (free) into /Applications, then try again.');
        @mkdir(PERSON_DATA, 0755, true); @mkdir(AVATARS, 0755, true);
        file_put_contents(ENGINE . '/person.py', PERSON_PY);
        $spec = person_blender_spec($p);
        $run = function (array $extra, ?callable $line = null) use ($bl, $dir, $spec) {
            file_put_contents("$dir/blender.json", json_encode($spec + $extra, JSON_UNESCAPED_SLASHES));
            $h = popen('env PATH=' . escapeshellarg(SYS_PATH) . ' ' . escapeshellarg($bl) . ' -b --python-exit-code 1 -P ' . escapeshellarg(ENGINE . '/person.py')
                . ' -- ' . escapeshellarg("$dir/blender.json") . ' 2>&1', 'r');
            $need = []; $log = '';
            while (($l = fgets($h)) !== false) {
                $log .= $l;
                if (preg_match('/^PERSON_NEED (\S+)/', $l, $m)) $need[] = $m[1];
                elseif ($line && preg_match('/^PERSON (\d+) (.+)/', $l, $m)) $line((int)$m[1], trim($m[2]));
            }
            $rc = pclose($h);
            file_put_contents("$dir/log.txt", $log, FILE_APPEND);
            return [$rc, $need, $log];
        };
        $st('Checking Blender + MPFB', 0.02);
        [$rc, $need] = $run(['check' => true]);
        if (in_array('mpfb', $need, true)) {
            $st('Installing the MPFB (MakeHuman) extension into Blender', 0.05);
            exec('env PATH=' . escapeshellarg(SYS_PATH) . ' ' . escapeshellarg($bl) . ' --online-mode -c extension install -s -e mpfb 2>&1', $o, $rc);
            [$rc, $need] = $run(['check' => true]);
            if (in_array('mpfb', $need, true)) throw new RuntimeException('Could not install MPFB into Blender. In Blender: Edit → Preferences → Get Extensions → search "MPFB" → Install.');
        }
        foreach (array_unique($need) as $n) {
            if (!isset(PERSON_DL[$n])) continue;
            $d = PERSON_DL[$n];
            foreach (array_merge([$d[0]], array_slice($d, 3)) as $f) {
                if (is_file(PERSON_DATA . "/$f")) continue;
                $url = $f === $d[0] ? $d[1] : TH_BLENDER . $f;
                $st("Downloading $f" . ($d[2] ? " ({$d[2]}, first time only)" : ''), 0.08);
                exec('curl -sfL --retry 2 -o ' . escapeshellarg(PERSON_DATA . "/$f.part") . ' ' . escapeshellarg($url), $o, $rc);
                if ($rc !== 0) throw new RuntimeException("Download failed: $url");
                rename(PERSON_DATA . "/$f.part", PERSON_DATA . "/$f");
            }
        }
        $id = person_id($p['name'] ?: 'person');
        $out = AVATARS . "/$id.glb";
        [$rc, $need, $log] = $run(['out' => $out], fn($pct, $msg) => $st($msg, 0.1 + $pct / 100 * 0.88));
        if ($rc !== 0 || !is_file($out)) {
            $err = preg_match_all('/^(?:Error|.*Error:|RuntimeError|SystemExit).*$/m', $log, $m) ? implode(' | ', array_slice(array_unique($m[0]), -2)) : 'see ' . "$dir/log.txt";
            throw new RuntimeException('Blender could not build the person: ' . $err);
        }
        @unlink(PERSON_DATA . '/makehuman_system_assets.zip');   // installed into MPFB's library now
        file_put_contents(AVATARS . "/$id.json", json_encode(['label' => $p['name'] ?: 'Person', 'license' => 'CC0 (MakeHuman assets, built with Blender + MPFB)',
            'mouth' => 1.5, 'tongue' => 0.6, 'made' => $p], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $st('Done', 1, ['state' => 'done', 'avatar' => $id, 'label' => avatar_meta($id)['label'], 'path' => $out]);
    } catch (Throwable $e) {
        return $st('Failed', 1, ['state' => 'error', 'error' => $e->getMessage()]);
    }
}
function toon_schema(): array {
    static $s = null;
    if ($s === null) { $s = TOON_SCHEMA; foreach ($s as &$r) if ($r[0] === 'avatar') $r[2] = toon_avatars() ?: [['mpfb', 'Maya — MakeHuman (CC0)']]; }
    return $s;
}
function toon_normalize($c): array {
    $c = is_array($c) ? $c : [];
    if (isset($c['preset'], TOON_PRESETS[$c['preset']])) $c = array_merge(toon_presets()[$c['preset']], $c);
    if (in_array($c['hair'] ?? '', ['cap', 'beanie', 'tophat'], true)) { $c['hat'] = $c['hat'] ?? $c['hair']; $c['hair'] = 'short'; }   // older saves kept hats under hair
    $out = TOON_DEFAULT;
    $out['name'] = mb_substr(trim((string)($c['name'] ?? '')), 0, 40);
    foreach (toon_schema() as $row) {
        if (count($row) < 3 || !array_key_exists($row[0], $c)) continue;
        [$k, , $spec] = $row; $v = $c[$k];
        if (is_string($spec)) $out[$k] = $k === 'lips' && $v === 'auto' ? 'auto' : hexcol($v, $out[$k]);
        elseif (is_numeric($spec[0])) $out[$k] = round(clampf($v, $spec[0], $spec[1], $out[$k]), 2);
        else {
            $ok = array_column($spec, 0);
            if (!empty($row[3])) $out[$k] = array_values(array_intersect($ok, is_array($v) ? $v : [$v]));
            elseif (in_array($v, $ok, true)) $out[$k] = $v;
        }
    }
    $img = (string)($c['bgImage'] ?? '');   // a picture from scenes/ (bg "image")
    if (preg_match('/^[\w.-]+\.(jpe?g|png|webp)$/i', $img) && is_file(SCENES . "/$img")) $out['bgImage'] = $img;
    if ($out['bg'] === 'image' && $out['bgImage'] === '') $out['bg'] = 'stage';
    $made = avatar_meta($out['avatar'])['made'] ?? null;   // a built 3D person brings its own hair color unless one was asked for
    if ($out['style'] === 'real' && !isset($c['hairColor']) && !empty($made['hairColor'])) $out['hairColor'] = $made['hairColor'];
    return $out;
}
function make_spec(array $s): array {
    $char = is_array($s['character'] ?? null) ? $s['character'] : [];
    if (!empty($s['preset']) && empty($char['preset'])) $char['preset'] = $s['preset'];
    if (!empty($s['style'])) $char['style'] = $s['style'];
    $char = toon_normalize($char);
    if (!empty($s['name'])) $char['name'] = mb_substr(trim((string)$s['name']), 0, 40);
    $fonts = array_column(TOON_FONTS, 0);
    $font = fn($f, $d) => in_array($f, $fonts, true) ? $f : $d;
    $sub = is_array($s['subtitles'] ?? null) ? $s['subtitles'] : [];
    $subs = [
        'style' => in_array($sub['style'] ?? '', SUB_STYLES, true) ? $sub['style'] : 'karaoke',
        'font'  => $font($sub['font'] ?? '', 'Luckiest Guy'),
        'pos'   => in_array($sub['pos'] ?? '', ['top', 'middle', 'bottom'], true) ? $sub['pos'] : 'bottom',
        'size'  => clampf($sub['size'] ?? 1, 0.5, 2, 1),
        'color' => hexcol($sub['color'] ?? '', '#ffffff'),
        'hi'    => hexcol($sub['hi'] ?? '', '#ffd23f'),
        'box'   => !empty($sub['box']),
    ];
    $caps = [];
    foreach (array_slice(is_array($s['captions'] ?? null) ? $s['captions'] : [], 0, 30) as $c) {
        if (!is_array($c) || trim((string)($c['text'] ?? '')) === '') continue;
        $kind = isset(CAP_KINDS[$c['kind'] ?? '']) ? $c['kind'] : 'title';
        $start = clampf($c['start'] ?? 0, 0, 36000, 0);
        $caps[] = ['text' => mb_substr(trim((string)$c['text']), 0, 140), 'kind' => $kind, 'font' => $font($c['font'] ?? '', $subs['font']),
            'color' => hexcol($c['color'] ?? '', CAP_KINDS[$kind]), 'start' => $start, 'end' => max($start + 0.3, clampf($c['end'] ?? $start + 3, 0, 36000, $start + 3)),
            'size' => clampf($c['size'] ?? 1, 0.5, 2, 1)];
    }
    $m = is_array($s['motion'] ?? null) ? $s['motion'] : [];
    $audio = (string)($s['audio'] ?? '');
    $vf = voice_file((string)($s['voice'] ?? ''));
    return [
        'title'     => mb_substr(trim((string)($s['title'] ?? '')) ?: ($char['name'] ?: 'Toon'), 0, 80),
        'character' => $char,
        'script'    => speakable((string)($s['script'] ?? '')),
        'audio'     => $audio !== '' && is_file($audio) && preg_match('/\.(mp3|wav|m4a|aac|flac|ogg|webm|mp4|mov)$/i', $audio) ? realpath($audio) : '',
        'music'     => !empty($s['music']),
        'voice'     => $vf ? pathinfo($vf, PATHINFO_FILENAME) : '',
        'voice_missing' => !$vf && !empty($s['voice']) ? (string)$s['voice'] : '',
        'tts_voice' => tts_voice((string)($s['tts_voice'] ?? '')),
        'speed'     => clampf($s['speed'] ?? 1, 0.6, 1.6, 1),
        'steps'     => (int)clampf($s['steps'] ?? 25, 10, 50, 25),
        'subtitles' => $subs,
        'captions'  => $caps,
        'motion'    => ['energy' => clampf($m['energy'] ?? 1, 0, 2, 1), 'gestures' => clampf($m['gestures'] ?? 0.6, 0, 1, 0.6), 'mouthAmt' => clampf($m['mouthAmt'] ?? 1, 0.5, 1.6, 1),
            'offset' => (int)clampf($m['offset'] ?? -40, -200, 200, -40), 'dance' => !empty($m['dance']),
            'camera' => in_array($m['camera'] ?? '', ['full', 'medium', 'close'], true) ? $m['camera'] : 'full'],
        'aspect'    => in_array($s['aspect'] ?? '', ['16:9', '9:16', '1:1'], true) ? $s['aspect'] : '16:9',
        'bgm'       => make_bgm($s['bgm'] ?? null),
        'render'    => !array_key_exists('render', $s) || !empty($s['render']),
    ];
}
function make_bgm($b): ?array {   // background music: a track in music/ (or one being made), its volume, and ducking under the voice
    if (is_string($b)) $b = ['prompt' => $b];
    if (!is_array($b)) return null;
    $file = basename((string)($b['file'] ?? ''));
    if ($file === '' && empty($b['prompt'])) return null;
    if ($file !== '' && !is_file(MUSIC . "/$file") && empty($b['job'])) return null;
    return ['file' => $file, 'prompt' => mb_substr(trim((string)($b['prompt'] ?? '')), 0, 300), 'job' => valid_id((string)($b['job'] ?? '')) ? $b['job'] : '',
        'volume' => clampf($b['volume'] ?? 0.25, 0, 1, 0.25), 'duck' => !array_key_exists('duck', $b) || !empty($b['duck'])];
}
function toon_options(): array {
    $choices = [];
    foreach (toon_schema() as $r) {
        if (count($r) < 3) continue;
        if (is_string($r[2])) $choices[$r[0]] = ['palette' => TOON_PAL[$r[2]], 'or' => 'any #rrggbb'];
        elseif (is_numeric($r[2][0])) $choices[$r[0]] = ['min' => $r[2][0], 'max' => $r[2][1]];
        else $choices[$r[0]] = !empty($r[3]) ? ['pick_any_of' => array_column($r[2], 0)] : array_column($r[2], 0);
    }
    return [
        'character_options' => $choices,
        'character_defaults' => TOON_DEFAULT,
        'presets' => toon_presets(),
        'saved_voices' => array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), glob(VOICES . '/*')),
        'tts_voices' => TTS_VOICES,
        'fonts' => array_column(TOON_FONTS, 0),
        'subtitle_styles' => SUB_STYLES,
        'caption_kinds' => array_keys(CAP_KINDS),
        'aspects' => ['16:9', '9:16', '1:1'],
        'cameras' => ['full', 'medium', 'close'],
        'renderer' => find_chrome() ? 'headless chrome' : 'none (install Google Chrome to render MP4s without a browser tab)',
        'scenes' => array_column(media_list(SCENES, '/\.(jpe?g|png|webp)$/i'), 'title', 'file'),   // character.bgImage = file (with bg "image")
        'music' => array_column(media_list(MUSIC, '/\.(mp3|wav|m4a|ogg|flac)$/i'), 'title', 'file'),   // bgm.file = file
        'avatars' => toon_avatars(true),   // the 3D people for style "real"; character.avatar = id
        'person_builder' => ['blender' => find_blender() !== null, 'options' => array_map(fn($r) => is_numeric($r[2][0]) ? ['min' => $r[2][0], 'max' => $r[2][1]] : array_column($r[2], 0),
            array_column(PERSON_OPTS, null, 0)), 'defaults' => PERSON_DEFAULT],
    ];
}
function render_meta(string $file): ?array {
    // size, length, frame and style of a render (probed once, cached next to it with a poster jpg)
    $f = RENDERS . "/$file"; $b = substr($f, 0, -4);
    if (!is_file($f)) return null;
    $m = read_json("$b.json");
    if (!isset($m['duration'])) {
        $p = json_decode((string)shell_exec(escapeshellarg(find_bin('ffprobe') ?: 'ffprobe') . ' -v error -select_streams v:0 -show_entries stream=width,height -show_entries format=duration -of json ' . escapeshellarg($f) . ' 2>/dev/null'), true);
        $w = (int)($p['streams'][0]['width'] ?? 0); $h = (int)($p['streams'][0]['height'] ?? 0);
        $m += ['title' => preg_replace('/ \d{4}-\d\d-\d\d \d{6}$/', '', basename($f, '.mp4')), 'style' => '', 'character' => ''];
        $m['duration'] = round((float)($p['format']['duration'] ?? 0), 1);
        $m['width'] = $w; $m['height'] = $h;
        $m['aspect'] = !$w || !$h ? '' : (abs($w / $h - 16 / 9) < 0.1 ? '16:9' : (abs($w / $h - 9 / 16) < 0.1 ? '9:16' : (abs($w / $h - 1) < 0.1 ? '1:1' : "{$w}x{$h}")));
        file_put_contents("$b.json", json_encode($m));
    }
    if (!is_file("$b.jpg") && ($ff = find_bin('ffmpeg')))
        exec(escapeshellarg($ff) . ' -y -loglevel error -ss ' . min(1.5, max(0, ($m['duration'] ?? 2) / 3)) . ' -i ' . escapeshellarg($f) . ' -frames:v 1 -vf scale=480:-2 ' . escapeshellarg("$b.jpg"));
    return $m + ['file' => $file, 'time' => filemtime($f), 'size' => filesize($f)];
}
function find_chrome(): ?string {
    $home = getenv('HOME') ?: '';
    foreach (['Google Chrome.app/Contents/MacOS/Google Chrome', 'Chromium.app/Contents/MacOS/Chromium', 'Brave Browser.app/Contents/MacOS/Brave Browser',
                 'Microsoft Edge.app/Contents/MacOS/Microsoft Edge'] as $app)
        foreach (['/Applications', "$home/Applications"] as $d) if (is_executable("$d/$app")) return "$d/$app";
    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $b) if ($p = find_bin($b)) return $p;
    return null;
}

// ── the Director: a local model (Ollama by default, or any OpenAI-compatible server) turns one sentence into a full spec ──
function settings(): array {
    $s = read_json(SETTINGS);
    if ($s && empty($s['provider'])) $s['provider'] = 'openai';   // saved before Ollama support
    return $s + ['provider' => 'ollama', 'llm_url' => 'http://127.0.0.1:11434', 'llm_model' => ''];
}
function http_req(string $url, ?array $body, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_NOSIGNAL => true]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    return [$code, $r === false ? null : (json_decode($r, true) ?? ['raw' => $r]), $err];
}
function llm_base(array $s): string {
    $u = rtrim($s['llm_url'], '/');
    return $s['provider'] === 'ollama' ? preg_replace('#/v1$#', '', $u) : $u;
}
function llm_models(): array {
    // text models only (Ollama also lists image / embedding models)
    $s = settings(); $base = llm_base($s);
    if ($s['provider'] === 'ollama') {
        [$code, $r, $err] = http_req("$base/api/tags", null, 6);
        if ($code !== 200) throw new RuntimeException("Can't reach Ollama at $base" . ($err ? " ($err)" : '') . '. Start it (open the Ollama app, or run: ollama serve).');
        $out = [];
        foreach ($r['models'] ?? [] as $m)
            if (!isset($m['capabilities']) || in_array('completion', $m['capabilities'], true)) $out[] = ['id' => $m['name'], 'size' => $m['details']['parameter_size'] ?? ''];
        return $out;
    }
    [$code, $r, $err] = http_req("$base/models", null, 6);
    if ($code !== 200) throw new RuntimeException("Can't reach the model server at $base" . ($err ? " ($err)" : " (HTTP $code)"));
    return array_map(fn($m) => ['id' => $m['id'], 'size' => ''], $r['data'] ?? []);
}
function llm_chat(array $messages, string $model = '', int $timeout = 600, ?array $schema = null): string {
    $s = settings(); $base = llm_base($s);
    $model = $model ?: $s['llm_model'] ?: (llm_models()[0]['id'] ?? '');
    if ($model === '') throw new RuntimeException('No text model found. Pull one first, e.g.:  ollama pull qwen3.5:2b');
    $fail = fn($code, $r, $err) => new RuntimeException("The model \"$model\" didn't answer" . ($err ? " ($err)" : " (HTTP $code" . (isset($r['error']) ? ': ' . (is_string($r['error']) ? $r['error'] : json_encode($r['error'])) : '') . ')') . '.');
    if ($s['provider'] === 'ollama') {
        $body = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => $schema ?: 'json', 'think' => false, 'options' => ['temperature' => 0.8, 'num_ctx' => 8192]];
        [$code, $r, $err] = http_req("$base/api/chat", $body, $timeout);
        if ($code === 400 && stripos(json_encode($r), 'think') !== false) { unset($body['think']); [$code, $r, $err] = http_req("$base/api/chat", $body, $timeout); }
        if ($code !== 200) throw $fail($code, $r, $err);
        $text = (string)($r['message']['content'] ?? '');
    } else {
        $body = ['model' => $model, 'messages' => $messages, 'temperature' => 0.8, 'max_tokens' => 2500, 'chat_template_kwargs' => ['enable_thinking' => false]];
        [$code, $r, $err] = http_req("$base/chat/completions", $body, $timeout);
        if ($code !== 200) throw $fail($code, $r, $err);
        $text = (string)($r['choices'][0]['message']['content'] ?? '');
    }
    return trim(preg_replace('/<think>.*?(<\/think>|$)/s', '', $text));
}
function director(string $prompt, string $model = '', ?array $story = null): array {
    $opts = [];
    foreach (toon_schema() as $r) if (count($r) >= 3 && !is_string($r[2]) && !is_numeric($r[2][0])) $opts[] = $r[0] . ': ' . implode('|', array_column($r[2], 0));
    $sys = "You are the director of a cartoon talking-head video maker. The user describes a video in one sentence. "
        . "Reply with ONE JSON object and nothing else:\n"
        . '{"title":"…","preset":"…","character":{…},"script":"…","tts_voice":"…","captions":[{"text":"…","kind":"title|lower|bubble","start":0,"end":3}],"subtitles":{"style":"karaoke","font":"…"},"motion":{"camera":"full|medium|close","energy":1}}' . "\n"
        . "Rules:\n- script: what the character SAYS out loud, first person, in their voice and personality, 60–140 words, no stage directions, no emoji, no markdown.\n"
        . "- preset: pick the closest of " . implode(', ', array_keys(TOON_PRESETS)) . ". character: ONLY the few fields that should differ from that preset (a name, a color, maybe style) — usually 1-4 fields.\n"
        . "- character fields and allowed values:\n  " . implode("\n  ", $opts) . "\n  colors (skin, hairColor, eyeColor, hatColor, topColor, pants, shoes, bgColor) are #rrggbb; name is the character's name\n"
        . "- style is the art style: classic (bold cartoon), anime, comic (halftone comic book), sketch (pencil on paper), clay (soft 3D toy), ascii (3D ASCII art), matrix (green ASCII), pixel (8-bit), neon (glowing lines), noir (black & white film), blueprint (white lines on blue), real (realistic 3D human)\n"
        . "- tts_voice: " . implode('|', array_column(TTS_VOICES, 0)) . " (am/bm = male, af/bf = female)\n"
        . "- captions: a title at the start (0–3s) and a lower third with the character's name (3–7s). Speaking pace is about 2.6 words per second.\n"
        . "- fonts: " . implode('|', array_column(TOON_FONTS, 0));
    $user = $prompt;
    if ($story) {   // a real news story: the script reports it, facts from the article only
        $sys .= "\n- NEWS MODE: the script reports on the news story below, in the character's voice. Use only facts from the story (names, numbers, dates), "
            . "say where it's from (\"according to " . ($story['source'] ?: 'the report') . "\"), and don't invent details. The title caption is a short punchy headline.";
        $user = "Video idea: $prompt\n\nNEWS STORY\nHeadline: {$story['title']}\nSource: {$story['source']}" . ($story['date'] ? " ({$story['date']})" : '')
            . "\nSummary: {$story['body']}\n" . ($story['text'] !== '' ? "Article text:\n{$story['text']}" : '');
    }
    $text = llm_chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], $model);
    $a = strpos($text, '{'); $b = strrpos($text, '}');
    $spec = $a !== false && $b > $a ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
    if (!is_array($spec)) throw new RuntimeException('The AI model replied, but not with a usable plan. Try again or rephrase. It said: ' . mb_substr($text, 0, 300));
    if (isset($spec['character']['style']) && empty($spec['style'])) $spec['style'] = $spec['character']['style'];
    // small models often ignore the look the user asked for — words in the prompt win
    foreach (['matrix' => 'matrix', 'ascii' => 'ascii', 'anime|manga' => 'anime', 'pixel|8-bit|8 bit|retro game' => 'pixel', 'neon|synthwave|glow' => 'neon',
                 'comic|comic book|halftone' => 'comic', 'sketch|pencil|hand-drawn|hand drawn|doodle' => 'sketch', 'clay|claymation|plasticine|vinyl toy' => 'clay',
                 'noir|black and white|black & white|film noir' => 'noir', 'blueprint|schematic' => 'blueprint', 'realistic|realism|lifelike|photoreal\\w*|real person|real human|3d human|human being' => 'real'] as $re => $st)
        if (preg_match("/\\b($re)\\b/i", $prompt)) { $spec['style'] = $st; break; }
    if (!preg_match('/\b(ascii|matrix|anime|manga|pixel|8-bit|neon|glow|comic|sketch|pencil|clay|claymation|noir|blueprint|realistic|lifelike|human)\b/i', $prompt) && isset(TOON_PRESETS[$spec['preset'] ?? '']['style'])) unset($spec['style']);
    if ($story) $spec['sources'] = [['title' => $story['title'], 'source' => $story['source'], 'url' => $story['url'], 'date' => $story['date']]];
    return $spec;
}
function news_story(array $s): array {   // a picked story (title, source, url, body) plus the article's own text
    $s = ['title' => mb_substr((string)($s['title'] ?? ''), 0, 300), 'source' => mb_substr((string)($s['source'] ?? ''), 0, 80), 'url' => (string)($s['url'] ?? ''),
        'date' => mb_substr((string)($s['date'] ?? ''), 0, 10), 'body' => mb_substr((string)($s['body'] ?? ''), 0, 1200)];
    $s['text'] = news_article($s['url']);
    return $s;
}

// ── agent CLI: php index.php options | make | status | skill ──
function server_base(): string {
    $url = 'http://127.0.0.1:' . PORT;
    if (@fsockopen('127.0.0.1', PORT, $e, $s, 1)) return $url;
    fwrite(STDERR, "Starting the app server on $url …\n");
    exec('nohup ' . escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=1G -d post_max_size=1G -d max_execution_time=0 -d memory_limit=512M -S 127.0.0.1:' . PORT . ' '
        . escapeshellarg(__FILE__) . ' > /dev/null 2>&1 &');
    for ($i = 0; $i < 50; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', PORT, $e, $s, 1)) return $url; }
    fwrite(STDERR, "Couldn't start the server. Run:  php index.php\n"); exit(1);
}
function http_json(string $url, ?string $json = null, int $timeout = 600): array {
    $ctx = stream_context_create(['http' => ['method' => $json === null ? 'GET' : 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json ?? '',
        'timeout' => $timeout, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx);
    $j = json_decode((string)$r, true);
    if (!is_array($j)) { fwrite(STDERR, "No reply from $url\n"); exit(1); }
    return $j;
}
function cli_agent(string $cmd, array $args): void {
    if ($cmd === 'options') { echo json_encode(toon_options(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; return; }
    if ($cmd === 'skill') {
        $dst = (getenv('HOME') ?: '~') . '/.claude/skills/toon-studio';
        @mkdir(dirname($dst), 0755, true);
        if (!file_exists($dst)) symlink(BASE . '/skills/toon-studio', $dst);
        echo "Skill: " . BASE . "/skills/toon-studio/SKILL.md\nLinked into Claude Code at $dst\nCopy that folder to give the skill to any other agent.\n";
        return;
    }
    if ($cmd === 'person') {   // build a new 3D person in Blender, right here (no server needed)
        $src = $args[0] ?? '-';
        $in = json_decode((string)($src === '-' ? stream_get_contents(STDIN) : @file_get_contents($src)), true);
        if (!is_array($in)) { fwrite(STDERR, "Usage: php index.php person spec.json   (or - for stdin). Fields: " . implode(', ', array_column(PERSON_OPTS, 0)) . ", name\n"); exit(1); }
        $dir = AVATARS . '/.builds/' . new_id();
        @mkdir($dir, 0755, true);
        file_put_contents("$dir/spec.json", json_encode(person_normalize($in)));
        $s = person_build($dir, true);
        echo json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        exit($s['state'] === 'done' ? 0 : 1);
    }
    $wait = in_array('--wait', $args, true);
    $args = array_values(array_filter($args, fn($a) => $a !== '--wait'));
    $base = server_base();
    if ($cmd === 'make') {
        if (($args[0] ?? '') === '--prompt') $spec = ['prompt' => implode(' ', array_slice($args, 1))];
        else {
            $src = $args[0] ?? '-';
            $raw = $src === '-' ? stream_get_contents(STDIN) : @file_get_contents($src);
            $spec = json_decode((string)$raw, true);
            if (!is_array($spec)) { fwrite(STDERR, "Usage: php index.php make spec.json [--wait]   (or - for stdin, or --prompt \"…\")\n"); exit(1); }
            if (!empty($spec['audio']) && !str_starts_with($spec['audio'], '/') && $src !== '-') $spec['audio'] = dirname(realpath($src)) . '/' . $spec['audio'];
        }
        $r = http_json("$base/?a=make", json_encode($spec));
        if (!empty($r['error'])) { fwrite(STDERR, $r['error'] . "\n"); exit(1); }
        echo "project {$r['id']}\n";
        if (!$wait) { echo "check it:  php index.php status {$r['id']}  --wait\n"; return; }
        $args = [$r['id']];
    }
    $id = $args[0] ?? '';
    if (!valid_id($id)) { fwrite(STDERR, "Usage: php index.php status <project-id> [--wait]\n"); exit(1); }
    $last = '';
    for (;;) {
        $s = http_json("$base/?a=make_status&id=" . urlencode($id));
        $line = sprintf('%3d%%  %s', round(($s['progress'] ?? 0) * 100), $s['stage'] ?? $s['state'] ?? '');
        if ($line !== $last) { fwrite(STDERR, $line . "\n"); $last = $line; }
        if (($s['state'] ?? '') === 'error') { fwrite(STDERR, 'FAILED: ' . ($s['error'] ?? '') . "\n"); exit(1); }
        if (($s['state'] ?? '') === 'done' || !$wait) break;
        sleep(2);
    }
    echo json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}

// ───────────────────────────── embedded engine files ─────────────────────────────
const PERSON_PY = <<<'PY'
# Blender + MPFB (MakeHuman) → a rigged GLB person with Oculus visemes + ARKit face shapes, ready for Toon Lab's Realistic 3D style.
#   Blender -b -P person.py -- spec.json
# spec: out, name, gender/age/muscle/weight/height/proportions (0..1), race {african, asian, caucasian}, skin, hair, eyebrows, eyes,
#       clothes [...], data (folder with the asset-pack zips + TalkingHead rig files), check (only report what is missing)
# Prints "PERSON <pct> <stage>" progress lines, "PERSON_NEED <thing>" when something has to be downloaded first.
import bpy, sys, json, os, zipfile, shutil, importlib.util, addon_utils
from importlib import import_module

spec = json.load(open(sys.argv[sys.argv.index('--') + 1]))
DATA = spec['data']
PACKS = ['makehuman_system_assets', 'visemes02', 'faceunits01']
def say(p, msg): print(f'PERSON {p} {msg}', flush=True)

mod = next((m for m in addon_utils.modules() if m.__name__.endswith('.mpfb')), None)
if mod is None:
    print('PERSON_NEED mpfb', flush=True); sys.exit(3)
addon_utils.enable(mod.__name__, default_set=False)
mp = sys.modules[mod.__name__]
S = import_module(mod.__name__ + '.services')
for m in ['services.objectservice', 'services.targetservice', 'services.clothesservice', 'entities.clothes.mhclo']: import_module(mod.__name__ + '.' + m)

# ── one-time setup: asset packs and the TalkingHead rig go into MPFB's own library ──
user = S.LocationService.get_user_data()
need = []
for p in PACKS:
    if os.path.isfile(os.path.join(user, 'packs', p + '.json')): continue
    z = os.path.join(DATA, p + '.zip')
    if spec.get('check') or not os.path.isfile(z): need.append(p); continue
    say(5, 'Installing asset pack ' + p)
    with zipfile.ZipFile(z) as zf: zf.extractall(user)
rigs = os.path.join(user, 'rigs')
if not os.path.isfile(os.path.join(rigs, 'talkinghead.json')):
    src = os.path.join(DATA, 'talkinghead.mpfbskel')
    if spec.get('check') or not os.path.isfile(src): need.append('talkinghead')
    else:
        os.makedirs(rigs, exist_ok=True)
        rig = json.load(open(src)); rig.setdefault('identifying_bones', ['LeftToe_End'])
        json.dump(rig, open(os.path.join(rigs, 'talkinghead.json'), 'w'))
        shutil.copy(os.path.join(DATA, 'talkinghead.mhw'), os.path.join(rigs, 'weights.talkinghead.json'))
for f in ['talkinghead-addon.py', 'talkinghead-targets.zip']:
    if not os.path.isfile(os.path.join(DATA, f)): need.append(f)
for n in need: print('PERSON_NEED ' + n, flush=True)
if spec.get('check') or need: sys.exit(0 if spec.get('check') else 4)
S.AssetService.update_all_asset_lists(); S.AssetService.invalidate_custom_rig_cache()

# ── build the human ──
say(15, 'Sculpting the body')
for o in list(bpy.data.objects): bpy.data.objects.remove(o, do_unlink=True)
HS, OS = S.HumanService, S.ObjectService
hi = HS._create_default_human_info_dict()
for k in ['gender', 'age', 'muscle', 'weight', 'height', 'proportions', 'cupsize', 'firmness']:
    if k in spec: hi['phenotype'][k] = float(spec[k])
if spec.get('race'): hi['phenotype']['race'] = {k: float(v) for k, v in spec['race'].items()}
mh = lambda n: n + '.mhclo' if n else ''
hi.update({
    'name': spec.get('name') or 'Person', 'rig': 'custom.talkinghead',
    'eyes': 'high-poly.mhclo', 'eyelashes': 'eyelashes01.mhclo', 'tongue': 'tongue01.mhclo', 'teeth': 'teeth_base.mhclo',
    'eyebrows': mh(spec.get('eyebrows', 'eyebrow001')), 'hair': mh(spec.get('hair', '')),
    'clothes': [mh(c) for c in spec.get('clothes', []) if c],
    'skin_mhmat': spec.get('skin', 'young_caucasian_male') + '.mhmat',
    'skin_material_type': 'GAMEENGINE', 'clothes_material_type': 'GAMEENGINE', 'eyes_material_type': 'GAMEENGINE',
    'targets': [{'target': t, 'value': float(v)} for t, v in spec.get('targets', {}).items()],   # body-part shapes (belly, waist, limbs…) on top of the macros
})
st = HS.get_default_deserialization_settings()
st.update({'subdiv_levels': 0, 'material_instances': 'NEVER'})
basemesh = HS.deserialize_from_dict(hi, st)

say(45, 'Dressing up')
eyes = OS.find_object_of_type_amongst_nearest_relatives(basemesh, 'Eyes')   # eye color = swap the iris texture
tex = os.path.join(user, 'eyes', 'materials', spec.get('eyes', 'brown') + '_eye.png')
if eyes and os.path.isfile(tex):
    for slot in eyes.material_slots:
        for n in slot.material.node_tree.nodes:
            if n.type == 'TEX_IMAGE' and n.image and 'eye' in n.image.name.lower() and 'normal' not in n.image.name.lower():
                n.image = bpy.data.images.load(tex, check_existing=True)

# ── export copy: baked body shape, Oculus visemes + ARKit face units (TalkingHead's refined targets on top), helpers removed ──
say(55, 'Adding the lip-sync face shapes')
coll = bpy.data.collections.new('export'); bpy.context.scene.collection.children.link(coll)
copy = S.ExportService.create_character_copy(basemesh, name_suffix='.export', place_in_collection=coll)
nb = OS.find_object_of_type_amongst_nearest_relatives(copy)
S.TargetService.bake_targets(nb)
S.FaceService.load_targets(nb, load_microsoft_visemes=False, load_meta_visemes=True, load_arkit_faceunits=True)
S.FaceService.interpolate_targets(nb)
spa = importlib.util.spec_from_file_location('th_addon', os.path.join(DATA, 'talkinghead-addon.py'))
th = importlib.util.module_from_spec(spa); spa.loader.exec_module(th)
face = [o for o in coll.all_objects if o.type == 'MESH' and o.data.shape_keys and o is not nb]
th.build_targets(mp, [nb] + face, os.path.join(DATA, 'talkinghead-targets.zip'))
S.ExportService.bake_modifiers_remove_helpers(nb, bake_masks=True, bake_subdiv=True, remove_helpers=True, also_proxy=True)

say(80, 'Fixing the skeleton')
keep = set(coll.all_objects)
for o in list(bpy.data.objects):
    if o not in keep: bpy.data.objects.remove(o, do_unlink=True)
arm = next(o for o in keep if o.type == 'ARMATURE')
arm.name = arm.data.name = 'Armature'   # TalkingHead-style root
for o in keep:
    if o.type == 'MESH': o.name = o.name.replace('.export', '')
bpy.ops.object.select_all(action='DESELECT'); arm.select_set(True); bpy.context.view_layer.objects.active = arm
th.fix_bone_axes([arm], th.BONE_AXES_DATA_A)   # Mixamo bone rolls, or toes come out twisted
bpy.ops.object.select_all(action='DESELECT')
for o in keep: o.select_set(True)
bpy.context.view_layer.objects.active = arm
bpy.ops.object.transform_apply(location=True, rotation=True, scale=True)

say(90, 'Exporting')
bpy.ops.export_scene.gltf(filepath=spec['out'], export_format='GLB', use_selection=True, export_animations=False, export_morph=True,
                          export_skins=True, export_yup=True, export_image_format='AUTO', export_morph_normal=False)
if spec.get('skin_tint'):   # skin tone: multiply the skin texture (glTF baseColorFactor is linear)
    import struct
    b = open(spec['out'], 'rb').read(); n = struct.unpack('<I', b[12:16])[0]; j = json.loads(b[20:20 + n])
    for m in j.get('materials', []):
        if m.get('name', '').endswith('.body'):
            m.setdefault('pbrMetallicRoughness', {})['baseColorFactor'] = [c ** 2.2 for c in spec['skin_tint']] + [1]
    js = json.dumps(j, separators=(',', ':')).encode(); js += b' ' * (-len(js) % 4)
    rest = b[20 + n:]
    open(spec['out'], 'wb').write(b[:8] + struct.pack('<I', 20 + len(js) + len(rest)) + struct.pack('<I', len(js)) + b'JSON' + js + rest)
say(100, 'Done')
PY;

const GEN_PY = <<<'PY'
"""Toon Lab extras. Each command prints one JSON line.
  gen.py image <prompt> <width> <height> <out.jpg>   scene picture, FLUX.1-schnell through your Hugging Face login
  gen.py music <dir>                                 <dir>/job.json {prompt, seconds, out} → MusicGen-small; progress in <dir>/status.json
  gen.py news <query> [n]                            this week's news stories from DuckDuckGo
Models and the Hugging Face login live in the usual ~/.cache/huggingface (not the engine's own model folder)."""
import sys, os, json, time, subprocess


def say(**k):
    print(json.dumps(k), flush=True)


def image(prompt, w, h, path):
    from huggingface_hub import InferenceClient
    try:
        img = InferenceClient(provider='auto').text_to_image(prompt, model='black-forest-labs/FLUX.1-schnell', width=w, height=h)
    except Exception as e:
        m = str(e)
        if '401' in m or 'token' in m.lower() and 'invalid' in m.lower():
            m = 'Hugging Face needs you logged in: run  hf auth login  in a terminal (free account), then try again.'
        elif '402' in m or 'credit' in m.lower():
            m = "Your Hugging Face free credits for this month are used up. Pick the free no-account service, or upload a picture (e.g. one made in ChatGPT)."
        say(error=m[:400]); sys.exit(1)
    img.convert('RGB').save(path, quality=92)
    say(ok=True, path=path, width=img.size[0], height=img.size[1])


def music(d):
    job = json.load(open(os.path.join(d, 'job.json')))
    state = {}

    def st(**k):
        state.update(k)
        with open(os.path.join(d, 'status.tmp'), 'w') as f:
            json.dump(state, f)
        os.replace(os.path.join(d, 'status.tmp'), os.path.join(d, 'status.json'))

    try:
        st(state='running', stage='Loading MusicGen (the first time downloads 2.4 GB)', progress=0.03)
        import torch, numpy as np, soundfile as sf
        from transformers import AutoProcessor, MusicgenForConditionalGeneration, LogitsProcessor, LogitsProcessorList
        proc = AutoProcessor.from_pretrained('facebook/musicgen-small')
        model = MusicgenForConditionalGeneration.from_pretrained('facebook/musicgen-small', use_safetensors=True)
        dev = job.get('device') or 'cpu'   # MPS can't run EnCodec's long convolutions (and crashes with the CPU fallback)
        model.to(dev)
        secs = max(4.0, min(30.0, float(job.get('seconds', 20))))
        steps = int(secs * 50) + 4

        class Progress(LogitsProcessor):   # MusicGen writes 50 tokens per second of audio
            n = 0
            def __call__(self, ids, scores):
                Progress.n += 1
                if Progress.n % 25 == 0:
                    st(stage=f'Composing {min(99, int(100 * Progress.n / steps))}%', progress=round(0.1 + 0.85 * Progress.n / steps, 3))
                return scores

        st(stage='Composing', progress=0.1)
        inp = proc(text=[job['prompt']], padding=True, return_tensors='pt').to(dev)
        with torch.no_grad():
            a = model.generate(**inp, max_new_tokens=steps, do_sample=True, guidance_scale=3.0, logits_processor=LogitsProcessorList([Progress()]))
        sr = model.config.audio_encoder.sampling_rate
        x = a[0, 0].float().cpu().numpy()
        x = x / max(1e-4, float(np.abs(x).max())) * 0.89
        fi, fo = int(sr * 0.03), int(sr * min(1.5, len(x) / sr / 4))   # short fade in, gentle fade out
        x[:fi] *= np.linspace(0, 1, fi); x[-fo:] *= np.linspace(1, 0, fo)
        wav = os.path.join(d, 'music.wav')
        sf.write(wav, x, sr)
        subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-i', wav, '-b:a', '192k', job['out']], check=True)
        st(state='done', stage='Done', progress=1, file=os.path.basename(job['out']), seconds=round(len(x) / sr, 1))
    except Exception as e:
        st(state='error', stage='Failed', error=str(e)[-500:])
        sys.exit(1)


def news(q, n=8):
    from ddgs import DDGS
    r = []
    for tl in ('w', 'm', None):   # this week, else this month, else any time
        try:
            r = list(DDGS().news(q, max_results=n, timelimit=tl) if tl else DDGS().news(q, max_results=n))
        except Exception as e:
            if tl is None:
                say(error='DuckDuckGo search failed: ' + str(e)[:200]); sys.exit(1)
        if r:
            break
    say(stories=[{'title': x.get('title', ''), 'source': x.get('source', ''), 'date': (x.get('date') or '')[:10], 'url': x.get('url', ''),
                  'body': x.get('body', '')} for x in r])


if __name__ == '__main__':
    cmd = sys.argv[1]
    if cmd == 'image':
        image(sys.argv[2], int(sys.argv[3]), int(sys.argv[4]), sys.argv[5])
    elif cmd == 'music':
        music(sys.argv[2])
    elif cmd == 'news':
        news(sys.argv[2], int(sys.argv[3]) if len(sys.argv) > 3 else 8)
PY;

const GAME_RUNTIME_JS = <<<'JS'
/* Toon Game runtime — the whole engine in one file, no dependencies.
   window.TG = { MOLDS, TILES, SFX, generate(), createGame(), boot() }
   A game is data: a spec (cast, levels as tile maps, rules, audio) plus sprite sheets rendered by the Game Creator.
   Sprite sheets: 7 frames of 128×192, left to right: idle, walk1, walk2, walk3, jump, hurt, win. */
window.TG = (() => {
'use strict';
const W = 768, H = 432, TS = 36, FW = 128, FH = 192;
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
function rng(seed) { let a = (seed >>> 0) || 1; return () => { a |= 0; a = a + 0x6D2B79F5 | 0; let t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
const hashStr = s => { let h = 2166136261 >>> 0; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; } return h >>> 0; };

const MOLDS = {
  platformer: {name: 'Platformer', blurb: 'Run right, jump on things, reach the flag.', controls: 'Arrows / WASD move · Space / Z jump (hold for higher) · X run · stomp enemies',
    rows: 12, cols: {short: 60, medium: 110, long: 170}, tiles: ['#', '=', 'o', '*', '^', 'j', 'k', 'd', 'e', 'f', 'g', 'B', 'S', 'G']},
  topdown:    {name: 'Top-down adventure', blurb: 'Explore rooms, swing your sword, find the key, reach the exit.', controls: 'Arrows / WASD move · Space / Z attack · X dash',
    rows: {short: 18, medium: 26, long: 36}, cols: {short: 28, medium: 40, long: 56}, tiles: ['#', 'o', '*', '^', 'k', 'd', 'e', 'f', 'g', 'B', 'S', 'G']},
  shooter:    {name: 'Vertical shooter', blurb: 'Fly up the screen, blast the waves, survive the boss.', controls: 'Arrows / WASD move · Space / Z fire · X bomb (clears the screen, one per life)',
    rows: {short: 70, medium: 120, long: 180}, cols: 21, tiles: ['#', 'o', '*', '^', 'e', 'f', 'g', 'B']},
};
const TILES = {'.': 'Empty', '#': 'Ground / wall', '=': 'Platform (jump through)', 'o': 'Coin', '*': 'Gem (power-up)', '^': 'Spikes / mine', 'j': 'Spring', 'k': 'Key', 'd': 'Locked door',
  'e': 'Enemy 1', 'f': 'Enemy 2', 'g': 'Enemy 3', 'B': 'Boss', 'S': 'Start', 'G': 'Goal / exit'};
const BEHAVIORS = ['patrol', 'chase', 'fly', 'shoot'];

/* ─── sound effects: a tiny synth; every effect is a handful of numbers, rendered to a buffer once ─── */
const SFX_PRESETS = {
  jump:    {wave: 'square', f0: 320, f1: 760, sustain: .04, decay: .16, vol: .22},
  coin:    {wave: 'square', f0: 1180, f1: 1180, sustain: .05, decay: .2, vol: .18, arp: {semi: 7, at: .06}},
  gem:     {wave: 'square', f0: 660, sustain: .05, decay: .12, vol: .2, notes: [0, 4, 7, 12], step: .06},
  stomp:   {wave: 'saw', f0: 420, f1: 90, sustain: .02, decay: .2, vol: .25},
  hurt:    {wave: 'noise', f0: 260, f1: 60, sustain: .05, decay: .28, vol: .3, mix: 'saw'},
  shoot:   {wave: 'square', f0: 980, f1: 320, sustain: .02, decay: .11, vol: .14},
  hit:     {wave: 'noise', f0: 700, f1: 200, sustain: .01, decay: .1, vol: .18},
  spring:  {wave: 'sine', f0: 200, f1: 900, sustain: .05, decay: .2, vol: .25},
  key:     {wave: 'tri', f0: 660, sustain: .08, decay: .15, vol: .22, notes: [0, 5, 12], step: .09},
  door:    {wave: 'saw', f0: 150, f1: 300, sustain: .1, decay: .2, vol: .2},
  win:     {wave: 'square', f0: 523, sustain: .11, decay: .06, vol: .2, notes: [0, 4, 7, 12, 7, 12, 16], step: .12},
  lose:    {wave: 'saw', f0: 392, sustain: .16, decay: .1, vol: .2, notes: [0, -3, -7, -12], step: .22},
  select:  {wave: 'square', f0: 700, f1: 900, sustain: .03, decay: .08, vol: .14},
  boss:    {wave: 'saw', f0: 110, f1: 55, sustain: .3, decay: .35, vol: .3, vib: {rate: 9, depth: .4}},
  explode: {wave: 'noise', f0: 400, f1: 40, sustain: .08, decay: .45, vol: .32},
  bomb:    {wave: 'noise', f0: 900, f1: 30, sustain: .15, decay: .6, vol: .35, mix: 'saw'},
};
const SFX_STYLES = {   // a style nudges every preset the same way
  chip:   p => p,
  arcade: p => ({...p, wave: p.wave === 'square' ? 'saw' : p.wave, vol: (p.vol || .2) * 1.1}),
  soft:   p => ({...p, wave: p.wave === 'noise' ? 'noise' : 'sine', decay: (p.decay || .2) * 1.5, vol: (p.vol || .2) * .9}),
  glitch: p => ({...p, wave: p.wave === 'sine' ? 'square' : p.wave, duty: .18, vib: p.vib || {rate: 23, depth: .5}}),
};
const adsr = (t, a, s, d) => t < a ? t / a : t < a + s ? 1 : Math.max(0, 1 - (t - a - s) / d);
function renderSfx(p, sr = 44100) {
  const notes = p.notes ? p.notes.map(n => p.f0 * Math.pow(2, n / 12)) : null, step = p.step || .1;
  const one = (p.sustain || .1) + (p.decay || .2), total = notes ? step * (notes.length - 1) + (p.sustain || .08) + (p.decay || .1) : one;
  const n = Math.ceil(total * sr), data = new Float32Array(n);
  let phase = 0, nv = 0, nn = 0;
  for (let i = 0; i < n; i++) {
    const t = i / sr; let f, env;
    if (notes) { const k = Math.min(notes.length - 1, Math.floor(t / step)), lt = t - k * step; f = notes[k]; env = adsr(lt, .004, p.sustain || .08, p.decay || .1); }
    else { f = p.f0 * Math.pow((p.f1 || p.f0) / p.f0, Math.min(1, t / one)); env = adsr(t, p.attack || .004, p.sustain || .1, p.decay || .2); }
    if (p.vib) f *= 1 + Math.sin(t * p.vib.rate * 6.2832) * p.vib.depth * .06;
    if (p.arp && t > p.arp.at) f *= Math.pow(2, p.arp.semi / 12);
    phase += f / sr; const ph = phase % 1; let v;
    switch (p.wave) {
      case 'saw': v = 2 * ph - 1; break;
      case 'sine': v = Math.sin(ph * 6.2832); break;
      case 'tri': v = 4 * Math.abs(ph - .5) - 1; break;
      case 'noise': if (nn-- <= 0) { nv = Math.random() * 2 - 1; nn = Math.max(1, Math.floor(sr / (f * 4))); } v = nv; break;
      default: v = ph < (p.duty || .5) ? 1 : -1;
    }
    if (p.mix === 'saw') v = v * .6 + (2 * ph - 1) * .4;
    data[i] = v * env * (p.vol || .2);
  }
  return data;
}
function varySfx(p, seed) {   // a different take on the same sound
  const r = rng(seed), q = {...p};
  q.f0 = Math.round(p.f0 * Math.pow(2, (r() - .5) * .9)); if (p.f1) q.f1 = Math.round(p.f1 * Math.pow(2, (r() - .5) * .9));
  q.decay = +((p.decay || .2) * (.7 + r() * .7)).toFixed(3);
  if (r() < .3) q.wave = ['square', 'saw', 'sine', 'tri'][Math.floor(r() * 4)];
  if (p.notes && r() < .5) q.notes = p.notes.map(x => x + Math.round((r() - .5) * 4));
  return q;
}
const SFX = {presets: SFX_PRESETS, styles: Object.keys(SFX_STYLES), render: renderSfx, vary: varySfx,
  set(style, custom) { const f = SFX_STYLES[style] || SFX_STYLES.chip, out = {}; for (const k in SFX_PRESETS) out[k] = custom && custom[k] ? custom[k] : f(SFX_PRESETS[k]); return out; }};

/* ─── level generators: a seed makes the same level every time ─── */
function generate(mold, o) {
  const M = MOLDS[mold], len = o.length || 'medium', diff = o.difficulty || 1, r = rng(o.seed || 1), nE = Math.max(1, Math.min(3, o.enemies || 1));
  const E = () => 'efg'[Math.floor(r() * nE)];
  if (mold === 'platformer') {
    const rows = M.rows, cols = M.cols[len], g = Array.from({length: rows}, () => Array(cols).fill('.'));
    const groundY = rows - 2; let c = 0;
    for (let x = 0; x < cols; x++) { g[rows - 1][x] = '#'; g[groundY][x] = '#'; }
    g[groundY - 1][2] = 'S';
    c = 8;
    while (c < cols - 12) {
      const kind = r();
      if (kind < .22 + diff * .05) {   // a pit, sometimes with spikes, a platform to cross a wide one
        const w = 2 + Math.floor(r() * (1.5 + diff));
        for (let x = c; x < Math.min(cols - 10, c + w); x++) { g[groundY][x] = '.'; g[rows - 1][x] = r() < .5 + diff * .15 ? '^' : '.'; }
        if (w >= 4) for (let x = c + 1; x < c + w - 1; x++) g[groundY - 2][x] = '=';
        c += w + 1;
      } else if (kind < .55) {   // floating platforms with coins on top
        const w = 3 + Math.floor(r() * 4), h = 2 + Math.floor(r() * 3), y = groundY - h;
        for (let x = c; x < Math.min(cols - 10, c + w); x++) { g[y][x] = r() < .25 ? '#' : '='; if (y - 1 >= 1) g[y - 1][x] = r() < .7 ? 'o' : '.'; }
        if (r() < .25 && y - 2 >= 1) g[y - 2][c + Math.floor(w / 2)] = '*';
        if (r() < .35) g[groundY - 1][c + Math.floor(w / 2)] = E();
        c += w + 2 + Math.floor(r() * 3);
      } else if (kind < .68) {   // a block step or a wall to jump over
        const h = 1 + Math.floor(r() * 2);
        for (let y = 0; y < h; y++) g[groundY - 1 - y][c] = '#';
        if (r() < .5) g[groundY - 2 - h][c] = 'o';
        c += 3 + Math.floor(r() * 3);
      } else if (kind < .78) { g[groundY - 1][c] = 'j'; for (let y = 3; y < 7; y++) g[groundY - 1 - y][c + 1 + (y % 2)] = 'o'; c += 4; }   // a spring under a coin trail
      else { const n = 1 + Math.floor(r() * (1 + diff)); for (let i = 0; i < n; i++) g[groundY - 1][c + i * 3] = E(); c += n * 3 + 2; }   // a patrol
    }
    if (o.key) { g[groundY - 3][Math.floor(cols * .4)] = 'k'; for (let y = groundY - 1; y >= groundY - 3; y--) g[y][cols - 9] = 'd'; }
    if (o.boss) { g[groundY - 1][cols - 7] = 'B'; for (let x = cols - 12; x < cols - 2; x++) g[groundY - 4][x] = '.'; }
    g[groundY - 1][cols - 3] = 'G';
    return g.map(row => row.join(''));
  }
  if (mold === 'topdown') {
    const rows = M.rows[len], cols = M.cols[len], g = Array.from({length: rows}, () => Array(cols).fill('#'));
    // rooms joined by corridors, in order, so the exit is always reachable
    const rooms = []; const n = 5 + Math.floor(r() * 4) + (len === 'long' ? 3 : 0);
    for (let i = 0; i < n * 6 && rooms.length < n; i++) {
      const w = 4 + Math.floor(r() * 6), h = 3 + Math.floor(r() * 4), x = 1 + Math.floor(r() * (cols - w - 2)), y = 1 + Math.floor(r() * (rows - h - 2));
      if (rooms.some(q => x < q.x + q.w + 1 && x + w + 1 > q.x && y < q.y + q.h + 1 && y + h + 1 > q.y)) continue;
      rooms.push({x, y, w, h, cx: x + (w >> 1), cy: y + (h >> 1)});
    }
    rooms.sort((a, b) => a.cx - b.cx);
    for (const q of rooms) for (let y = q.y; y < q.y + q.h; y++) for (let x = q.x; x < q.x + q.w; x++) g[y][x] = '.';
    for (let i = 1; i < rooms.length; i++) {
      const a = rooms[i - 1], b = rooms[i]; let x = a.cx, y = a.cy;
      while (x !== b.cx) { g[y][x] = '.'; x += Math.sign(b.cx - x); } while (y !== b.cy) { g[y][x] = '.'; y += Math.sign(b.cy - y); } g[y][x] = '.';
    }
    const first = rooms[0], last = rooms[rooms.length - 1];
    g[first.cy][first.cx] = 'S'; g[last.cy][last.cx] = 'G';
    for (let i = 1; i < rooms.length; i++) {
      const q = rooms[i], cell = (dx, dy) => { const x = clamp(q.x + dx, q.x, q.x + q.w - 1), y = clamp(q.y + dy, q.y, q.y + q.h - 1); return [x, y]; };
      const spots = []; for (let y = 0; y < q.h; y++) for (let x = 0; x < q.w; x++) if (g[q.y + y][q.x + x] === '.') spots.push([q.x + x, q.y + y]);
      const take = () => spots.length ? spots.splice(Math.floor(r() * spots.length), 1)[0] : null;
      const nc = 2 + Math.floor(r() * 4); for (let k = 0; k < nc; k++) { const s = take(); if (s) g[s[1]][s[0]] = r() < .15 ? '*' : 'o'; }
      const ne = i === rooms.length - 1 ? 0 : Math.floor(r() * (1.5 + diff)); for (let k = 0; k < ne; k++) { const s = take(); if (s) g[s[1]][s[0]] = E(); }
      if (r() < .4) { const s = take(); if (s) g[s[1]][s[0]] = '^'; }
      void cell;
    }
    if (o.key && rooms.length > 2) {   // the key sits in a middle room, the door guards the exit room's corridor
      const kr = rooms[1 + Math.floor(r() * (rooms.length - 2))]; g[kr.y][kr.x] = 'k';
      const p = rooms[rooms.length - 2]; let x = p.cx, y = p.cy, steps = 0;
      while ((x !== last.cx || y !== last.cy) && steps++ < 400) { if (x !== last.cx) x += Math.sign(last.cx - x); else y += Math.sign(last.cy - y); if (g[y][x] === '.' && (x < last.x || x >= last.x + last.w || y < last.y || y >= last.y + last.h)) { g[y][x] = 'd'; break; } }
    }
    if (o.boss) { const b = last; g[b.y][b.x] = 'B'; }
    return g.map(row => row.join(''));
  }
  // shooter: rows arrive from the top in order; row 0 first
  const rows = M.rows[len], cols = M.cols, g = Array.from({length: rows}, () => Array(cols).fill('.'));
  let y = 6;
  while (y < rows - 14) {
    const kind = r();
    if (kind < .35) { const n = 3 + Math.floor(r() * 4), x0 = 2 + Math.floor(r() * (cols - 4 - n * 2)), e = E(); for (let i = 0; i < n; i++) g[y + (i % 2)][x0 + i * 2] = e; y += 4; }   // a line
    else if (kind < .55) { const e = E(), cx = 4 + Math.floor(r() * (cols - 8)); for (let i = 0; i < 4; i++) { g[y + i][cx - i] = e; g[y + i][cx + i] = e; } y += 6; }   // a V
    else if (kind < .72) { const n = 3 + Math.floor(r() * 4 * diff); for (let i = 0; i < n; i++) g[y + Math.floor(r() * 4)][1 + Math.floor(r() * (cols - 2))] = r() < .25 ? '^' : '#'; y += 6; }   // an asteroid field
    else if (kind < .85) { const x = 2 + Math.floor(r() * (cols - 4)); for (let i = 0; i < 5; i++) g[y + i][x + (i % 2 ? 1 : -1)] = 'o'; if (r() < .4) g[y + 5][x] = '*'; y += 7; }   // a coin trail
    else { g[y][Math.floor(r() * cols)] = E(); g[y + 2][Math.floor(r() * cols)] = E(); y += 4; }
  }
  if (o.boss) g[rows - 10][Math.floor(cols / 2)] = 'B';
  return g.map(row => row.join(''));
}

/* ─── the game ─── */
function createGame(api) {
  const cfg = api.cfg, spec = cfg.spec, R = spec.rules || {}, cast = spec.cast || {}, mold = MOLDS[spec.mold] ? spec.mold : 'platformer';
  const DIFF = {chill: .7, normal: 1, hard: 1.35, brutal: 1.7}[R.difficulty] || 1, IMG = api.images;
  const FR = {idle: 0, walk: [1, 2, 3, 2], jump: 4, hurt: 5, win: 6};
  const levels = spec.levels && spec.levels.length ? spec.levels : [{name: 'Stage 1', map: generate(mold, {seed: 7, length: 'short'})}];
  let screen = 'title', level = 0, score = 0, lives = R.lives || 3, coins = 0, keys = 0, time = 0, lt = 0, shake = 0, deadT = 0, msg = null, bombs = 1, power = 0;
  let L, hero, enemies, boss, bullets, ebullets, particles, pops, cam, scroll, spawnedRow, levelTime;
  const r2 = rng(99);
  const enemyDef = ch => (cast.enemies || [])[Math.max(0, 'efg'.indexOf(ch))] || (cast.enemies || [])[0] || {name: 'Enemy', behavior: 'patrol', speed: 1};
  const sheet = key => IMG[key] || IMG.hero;
  const heroDef = cast.hero || {};

  function parse(lv) {
    const rows = (lv.map && lv.map.length ? lv.map : generate(mold, {seed: lv.seed || 1, length: lv.length})).map(s => s.split(''));
    const cols = Math.max(...rows.map(x => x.length)); rows.forEach(x => { while (x.length < cols) x.push('.'); });
    const out = {rows: rows.length, cols, g: rows, name: lv.name || 'Stage', theme: lv.theme || {}, bg: IMG.bg && IMG.bg[level], spawns: [], start: null, goal: null, coinsTotal: 0, ast: [], lastRowPassed: false};
    for (let y = 0; y < out.rows; y++) for (let x = 0; x < cols; x++) {
      const ch = rows[y][x];
      if (ch === 'S') { out.start = {x, y}; rows[y][x] = '.'; }
      else if (ch === 'G') { out.goal = {x, y}; if (mold === 'shooter') rows[y][x] = '.'; }
      else if ('efgB'.includes(ch)) { out.spawns.push({ch, x, y}); rows[y][x] = '.'; }
      else if (ch === 'o') out.coinsTotal++;
    }
    if (!out.start) out.start = mold === 'platformer' ? {x: 1, y: out.rows - 3} : mold === 'topdown' ? firstFloor(rows) : {x: Math.floor(cols / 2), y: 0};
    return out;
  }
  function firstFloor(rows) { for (let y = 0; y < rows.length; y++) for (let x = 0; x < rows[y].length; x++) if (rows[y][x] === '.') return {x, y}; return {x: 1, y: 1}; }
  const tile = (x, y) => (y < 0 || x < 0 || x >= L.cols) ? (mold === 'platformer' && y < 0 ? '.' : '#') : y >= L.rows ? (mold === 'platformer' ? '.' : '#') : L.g[y][x];
  const solid = ch => ch === '#' || ch === 'd';
  function actor(key, cx, bottom, dh, extra) {   // sprite drawn dh tall, hitbox anchored at the feet
    const w = dh * .42, h = dh * .86;
    return Object.assign({key, x: cx - w / 2, y: bottom - h, w, h, dh, vx: 0, vy: 0, dir: 1, ground: false, inv: 0, hurtT: 0, anim: 0, alive: true, hp: 1, t: r2() * 10}, extra || {});
  }
  function resetLevel() {
    L = parse(levels[level]); lt = 0; levelTime = R.timeLimit ? R.timeLimit : 0; power = 0;
    bullets = []; ebullets = []; particles = []; pops = []; cam = {x: 0, y: 0}; scroll = 0; spawnedRow = -1; coins = 0; keys = 0; shake = 0;
    const dh = mold === 'topdown' ? TS * 1.55 : mold === 'shooter' ? TS * 1.5 : TS * 1.9;
    hero = actor('hero', (L.start.x + .5) * TS, (L.start.y + 1) * TS, dh, {speed: +heroDef.speed || 1, jump: +heroDef.jump || 1, coyote: 0, buffer: 0, swing: 0, cool: 0, dash: 0, fire: 0, face: 'down'});
    if (mold === 'shooter') { hero.x = W / 2 - hero.w / 2; hero.y = H - TS * 3; }
    enemies = []; boss = null;
    if (mold !== 'shooter') for (const s of L.spawns) spawnAt(s);
  }
  function spawnAt(s, yOverride) {
    const cx = (s.x + .5) * TS, by = yOverride !== undefined ? yOverride : (s.y + 1) * TS;
    if (s.ch === 'B') { const d = cast.boss || {name: 'Boss', hp: 3, speed: 1}; boss = actor('boss', cx, by, TS * (mold === 'shooter' ? 2.6 : 3.1), {hp: Math.round((+d.hp || 3) * (mold === 'shooter' ? 4 : 1) * DIFF), hpMax: 0, speed: +d.speed || 1, phase: 0, name: d.name}); boss.hpMax = boss.hp; boss.dir = -1; return; }
    const d = enemyDef(s.ch), e = actor('e' + Math.max(0, 'efg'.indexOf(s.ch)), cx, by, TS * (mold === 'shooter' ? 1.35 : 1.45), {behavior: BEHAVIORS.includes(d.behavior) ? d.behavior : 'patrol', speed: +d.speed || 1, home: {x: cx, y: by}, shootT: 1 + r2() * 2, name: d.name});
    e.dir = r2() < .5 ? -1 : 1; if (!IMG[e.key]) e.key = 'e0';
    enemies.push(e);
  }
  const overlap = (a, b) => a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
  const dist = (a, b) => Math.hypot((a.x + a.w / 2) - (b.x + b.w / 2), (a.y + a.h / 2) - (b.y + b.h / 2));
  function burst(x, y, color, n = 10, sp = 160) { for (let i = 0; i < n; i++) particles.push({x, y, vx: (r2() - .5) * sp * 2, vy: (r2() - .9) * sp, life: .5 + r2() * .4, color, size: 3 + r2() * 4}); }
  function pop(x, y, text, color) { pops.push({x, y, text, color: color || '#fff', life: .9}); }
  function addScore(n, x, y) { score += n; if (x !== undefined) pop(x, y, '+' + n, '#ffd23f'); }
  function hurt(from) {
    if (hero.inv > 0 || screen !== 'play') return;
    lives--; api.sfx('hurt'); api.voice('hurt'); shake = .35; hero.inv = 1.6; hero.hurtT = .45;
    burst(hero.x + hero.w / 2, hero.y + hero.h / 2, '#ff6b7a', 12);
    if (from && mold !== 'shooter') { hero.vx = Math.sign(hero.x - from.x || 1) * 260; if (mold === 'platformer') hero.vy = -320; }
    if (lives <= 0) { screen = 'dying'; deadT = 1; api.music(false); api.sfx('lose'); api.voice('lose'); }
  }
  function tryGoal() {
    if (R.win === 'boss' && boss && boss.alive) { say('Beat ' + (boss.name || 'the boss') + ' first!'); return; }
    if (R.win === 'coins' && coins < L.coinsTotal) { say(`Collect every coin: ${coins} / ${L.coinsTotal}`); return; }
    clearLevel();
  }
  function clearLevel() {
    addScore(1000 + Math.round(levelTime * 10)); api.sfx('win');
    if (level + 1 >= levels.length) { screen = 'win'; api.music(false); api.voice('win'); api.report({won: true, score, level: level + 1}); }
    else { level++; screen = 'intro'; deadT = 1.6; resetLevel(); api.music(true, level); }
  }
  function say(text) { msg = {text, t: 2}; }
  function pickups(a) {   // tiles under the hero
    const x0 = Math.floor((a.x + 4) / TS), x1 = Math.floor((a.x + a.w - 4) / TS), y0 = Math.floor((a.y + 4) / TS), y1 = Math.floor((a.y + a.h - 4) / TS);
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
      const ch = tile(x, y), cx = (x + .5) * TS, cy = (y + .5) * TS;
      if (ch === 'o') { L.g[y][x] = '.'; coins++; addScore(100, cx, cy); api.sfx('coin'); burst(cx, cy, '#ffd23f', 6, 90); }
      else if (ch === '*') { L.g[y][x] = '.'; addScore(500, cx, cy); api.sfx('gem'); burst(cx, cy, '#7bf5c4', 10); if (mold === 'shooter') power = 9; else hero.inv = Math.max(hero.inv, 5); }
      else if (ch === 'k') { L.g[y][x] = '.'; keys++; api.sfx('key'); pop(cx, cy, 'KEY!', '#fff'); }
      else if (ch === '^') { const box = {x: x * TS + 6, y: y * TS + TS * .45, w: TS - 12, h: TS * .55}; if (overlap(a, box)) hurt({x: cx, y: cy}); }
      else if (ch === 'G' && L.goal) { if (mold === 'topdown') tryGoal(); }
    }
    if (mold === 'platformer' && L.goal && a.x + a.w > L.goal.x * TS + 6 && a.x < (L.goal.x + 1) * TS && a.y + a.h > (L.goal.y - 1) * TS && a.y < (L.goal.y + 1) * TS) tryGoal();
  }
  function moveX(a, dx) {
    a.x += dx; let hit = false;
    const y0 = Math.floor(a.y / TS), y1 = Math.floor((a.y + a.h - .01) / TS), x0 = Math.floor(a.x / TS), x1 = Math.floor((a.x + a.w - .01) / TS);
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
      const ch = tile(x, y);
      if (ch === 'd' && a === hero && keys > 0) { keys--; L.g[y][x] = '.'; api.sfx('door'); burst((x + .5) * TS, (y + .5) * TS, '#c9a86a', 10); continue; }
      if (solid(ch)) { a.x = dx > 0 ? x * TS - a.w : (x + 1) * TS; hit = true; }
    }
    return hit;
  }
  function moveY(a, dy) {
    const prevBottom = a.y + a.h; a.y += dy; let hit = false;
    const x0 = Math.floor(a.x / TS), x1 = Math.floor((a.x + a.w - .01) / TS), y0 = Math.floor(a.y / TS), y1 = Math.floor((a.y + a.h - .01) / TS);
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
      const ch = tile(x, y);
      if (ch === 'd' && a === hero && keys > 0) { keys--; L.g[y][x] = '.'; api.sfx('door'); continue; }
      if (solid(ch) || (ch === '=' && dy > 0 && prevBottom <= y * TS + 2) || (ch === 'j' && dy > 0 && prevBottom <= y * TS + 8)) {
        if (dy > 0) { a.y = y * TS - a.h; a.ground = true; if (ch === 'j' && a === hero) { a.vy = -Math.sqrt(2 * grav() * TS * 7); api.sfx('spring'); a.ground = false; return 'spring'; } }
        else a.y = (y + 1) * TS;
        hit = true;
      }
    }
    return hit;
  }
  const grav = () => 1500 * (+R.gravity || 1);
  const key = k => api.keys.has(k), left = () => key('ArrowLeft') || key('KeyA'), right = () => key('ArrowRight') || key('KeyD'), up = () => key('ArrowUp') || key('KeyW'), down = () => key('ArrowDown') || key('KeyS');
  const actPressed = () => api.pressed('Space') || api.pressed('KeyZ'), actHeld = () => key('Space') || key('KeyZ'), altHeld = () => key('KeyX'), altPressed = () => api.pressed('KeyX');

  /* ── platformer ── */
  function stepPlatformer(dt) {
    const h = hero, run = 215 * (+R.speed || 1) * h.speed * (altHeld() ? 1.45 : 1), g = grav();
    const dir = (right() ? 1 : 0) - (left() ? 1 : 0);
    if (h.hurtT <= 0) { h.vx += (dir * run - h.vx) * Math.min(1, dt * (h.ground ? 14 : 8)); if (dir) h.dir = dir; }
    else h.vx *= Math.pow(.02, dt);
    if (h.ground) h.coyote = .1; else h.coyote -= dt;
    if (actPressed() || up() && !h.upHeld) h.buffer = .12; h.upHeld = up(); h.buffer -= dt;
    const jumpV = -Math.sqrt(2 * g * TS * 2.6 * (+R.jump || 1) * h.jump);
    if (h.buffer > 0 && h.coyote > 0 && h.hurtT <= 0) { h.vy = jumpV; h.ground = false; h.coyote = 0; h.buffer = 0; api.sfx('jump'); burst(h.x + h.w / 2, h.y + h.h, '#ffffff88', 4, 60); }
    if (h.vy < 0 && !(actHeld() || up())) h.vy += g * 1.8 * dt;   // let go early for a short hop
    h.vy = Math.min(h.vy + g * dt, 900);
    moveX(h, h.vx * dt); h.ground = false; moveY(h, h.vy * dt) && (h.vy = 0);
    if (h.ground && h.vy > 0) h.vy = 0;
    h.x = clamp(h.x, 0, L.cols * TS - h.w);
    if (h.y > L.rows * TS + 60) { lives = Math.max(1, lives); hurt(); if (screen === 'play') { h.x = Math.max(TS, h.x - TS * 4); h.y = 0; h.vy = 0; } }
    pickups(h);
    for (const e of enemies) {
      if (!e.alive) continue; e.t += dt;
      const sp = 55 * e.speed * DIFF, near = Math.abs((h.x - e.x)) < TS * 8;
      if (e.behavior === 'fly') { e.x += e.dir * sp * .8 * dt; e.y = e.home.y - e.h - TS * 1.2 + Math.sin(e.t * 2.2) * TS * .9; if (Math.abs(e.x - e.home.x) > TS * 4) e.dir *= -1; }
      else {
        let want = e.dir;
        if (e.behavior === 'chase' && near) want = Math.sign(h.x - e.x) || 1;
        const v = (e.behavior === 'chase' && near ? sp * 1.7 : e.behavior === 'shoot' ? sp * .35 : sp) * want;
        e.vy = Math.min(e.vy + grav() * dt, 900);
        const ahead = Math.floor((e.x + e.w / 2 + want * (e.w / 2 + 4)) / TS), below = Math.floor((e.y + e.h + 2) / TS);
        if (e.ground && !solid(tile(ahead, below)) && tile(ahead, below) !== '=' && e.behavior !== 'chase') e.dir = want = -want;
        if (moveX(e, v * dt)) e.dir = -want;
        e.ground = false; if (moveY(e, e.vy * dt)) e.vy = 0;
        if (e.behavior === 'shoot' && near) { e.shootT -= dt; if (e.shootT <= 0) { e.shootT = 2.4 / DIFF; ebullets.push({x: e.x + e.w / 2, y: e.y + e.h * .4, vx: Math.sign(h.x - e.x) * 190 * DIFF, vy: 0, r: 6, life: 3}); api.sfx('shoot'); } }
      }
      e.anim += dt * 6;
      if (overlap(h, e) && h.hurtT <= 0) {
        if (h.vy > 60 && h.y + h.h < e.y + e.h * .55) { e.alive = false; h.vy = jumpV * .62; addScore(200, e.x + e.w / 2, e.y); api.sfx('stomp'); burst(e.x + e.w / 2, e.y + e.h / 2, '#c7b9ff', 12); }
        else hurt(e);
      }
    }
    if (boss && boss.alive) {
      const b = boss; b.t += dt; b.inv = Math.max(0, b.inv - dt);
      const want = Math.sign(h.x - b.x) || 1; b.dir = want;
      b.vy = Math.min(b.vy + grav() * dt, 900);
      if (b.ground && b.t > 2.4 / DIFF) { b.t = 0; b.vy = -Math.sqrt(2 * grav() * TS * 2.2); api.sfx('boss'); }
      moveX(b, want * 62 * b.speed * DIFF * dt); b.ground = false; if (moveY(b, b.vy * dt)) b.vy = 0;
      b.anim += dt * 5;
      if (overlap(h, b) && h.hurtT <= 0) {
        if (h.vy > 60 && h.y + h.h < b.y + b.h * .4 && b.inv <= 0) { b.hp--; b.inv = 1; h.vy = jumpV * .7; api.sfx('hit'); burst(b.x + b.w / 2, b.y, '#ffd23f', 10); shake = .2; if (b.hp <= 0) killBoss(); }
        else hurt(b);
      }
    }
    cam.x = clamp(cam.x + ((h.x + h.w / 2 - W * .4) - cam.x) * Math.min(1, dt * 6), 0, Math.max(0, L.cols * TS - W)); cam.y = 0;
  }
  function killBoss() { const b = boss; b.alive = false; addScore(2500, b.x + b.w / 2, b.y); api.sfx('explode'); shake = .6; for (let i = 0; i < 4; i++) burst(b.x + r2() * b.w, b.y + r2() * b.h, ['#ffd23f', '#ff6b7a', '#fff', '#c7b9ff'][i], 14, 220); say((b.name || 'The boss') + ' is down!'); if (mold === 'shooter' && L.lastRowPassed) clearLevel(); }

  /* ── top-down ── */
  function stepTopdown(dt) {
    const h = hero, sp = 165 * (+R.speed || 1) * h.speed;
    let dx = (right() ? 1 : 0) - (left() ? 1 : 0), dy = (down() ? 1 : 0) - (up() ? 1 : 0);
    if (dx && dy) { dx *= .7071; dy *= .7071; }
    if (dx || dy) h.face = Math.abs(dx) >= Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
    if (dx) h.dir = dx > 0 ? 1 : -1;
    h.cool -= dt; h.dash -= dt;
    if (altPressed() && h.dash < -1) { h.dash = .18; api.sfx('select'); }
    const mul = h.dash > 0 ? 3.2 : h.hurtT > 0 ? .3 : 1;
    moveX(h, dx * sp * mul * dt); moveY(h, dy * sp * mul * dt);
    if (actPressed() && h.cool <= 0) { h.cool = .38; h.swing = .18; api.sfx('hit'); }
    h.swing -= dt;
    const sw = swingBox();
    pickups(h);
    h.anim += (dx || dy) ? dt * 7 : 0;
    for (const e of enemies) {
      if (!e.alive) continue; e.t += dt; e.anim += dt * 6;
      const v = 60 * e.speed * DIFF, d = dist(h, e), near = d < TS * 7;
      if (e.behavior === 'chase' && near) { const a = Math.atan2(h.y - e.y, h.x - e.x); e.vx = Math.cos(a) * v * 1.6; e.vy = Math.sin(a) * v * 1.6; }
      else if (e.behavior === 'shoot') { if (near) { e.shootT -= dt; if (e.shootT <= 0) { e.shootT = 2.2 / DIFF; const a = Math.atan2(h.y + h.h / 2 - e.y - e.h / 2, h.x + h.w / 2 - e.x - e.w / 2); ebullets.push({x: e.x + e.w / 2, y: e.y + e.h / 2, vx: Math.cos(a) * 170 * DIFF, vy: Math.sin(a) * 170 * DIFF, r: 6, life: 3}); api.sfx('shoot'); } } if (e.t > 1.5) { e.t = 0; const a = r2() * 6.28; e.vx = Math.cos(a) * v * .5; e.vy = Math.sin(a) * v * .5; } }
      else if (e.t > 1.2 || (!e.vx && !e.vy)) { e.t = 0; const a = r2() * 6.28; e.vx = Math.cos(a) * v; e.vy = Math.sin(a) * v; }
      if (e.behavior === 'fly') { e.x += e.vx * dt; e.y += e.vy * dt; e.x = clamp(e.x, TS, L.cols * TS - TS - e.w); e.y = clamp(e.y, TS, L.rows * TS - TS - e.h); }
      else { if (moveX(e, e.vx * dt)) e.vx *= -1; if (moveY(e, e.vy * dt)) e.vy *= -1; }
      if (e.vx) e.dir = e.vx > 0 ? 1 : -1;
      if (sw && overlap(sw, e)) { e.alive = false; addScore(200, e.x + e.w / 2, e.y); api.sfx('stomp'); burst(e.x + e.w / 2, e.y + e.h / 2, '#c7b9ff', 12); continue; }
      if (overlap(h, e)) hurt(e);
    }
    if (boss && boss.alive) {
      const b = boss; b.t += dt; b.inv = Math.max(0, b.inv - dt); b.anim += dt * 5;
      const a = Math.atan2(h.y - b.y, h.x - b.x), charge = b.t % 4 > 3;
      const v = (charge ? 170 : 55) * b.speed * DIFF;
      if (moveX(b, Math.cos(a) * v * dt)) b.t = 0; if (moveY(b, Math.sin(a) * v * dt)) b.t = 0;
      b.dir = Math.cos(a) > 0 ? 1 : -1;
      if (sw && overlap(sw, b) && b.inv <= 0) { b.hp--; b.inv = .8; api.sfx('hit'); shake = .25; burst(b.x + b.w / 2, b.y + b.h / 2, '#ffd23f', 10); b.x += Math.sign(b.x - h.x) * 14; if (b.hp <= 0) killBoss(); }
      else if (overlap(h, b)) hurt(b);
    }
    cam.x = clamp(h.x + h.w / 2 - W / 2, 0, Math.max(0, L.cols * TS - W)); cam.y = clamp(h.y + h.h / 2 - H / 2, 0, Math.max(0, L.rows * TS - H));
  }
  function swingBox() {
    const h = hero; if (h.swing <= 0) return null;
    const s = TS * 1.1, cx = h.x + h.w / 2, cy = h.y + h.h / 2;
    const o = {right: [s * .9, 0], left: [-s * .9, 0], up: [0, -s * .9], down: [0, s * .9]}[h.face] || [s, 0];
    return {x: cx + o[0] - s / 2, y: cy + o[1] - s / 2, w: s, h: s};
  }

  /* ── shooter ── */
  function stepShooter(dt) {
    const h = hero, sp = 300 * (+R.speed || 1) * h.speed, speed = 46 * (1 + .25 * (DIFF - 1)) * (altHeld() ? 1 : 1);
    scroll += speed * dt; lt += 0;
    let dx = (right() ? 1 : 0) - (left() ? 1 : 0), dy = (down() ? 1 : 0) - (up() ? 1 : 0);
    if (dx && dy) { dx *= .7071; dy *= .7071; }
    h.x = clamp(h.x + dx * sp * dt, 0, W - h.w); h.y = clamp(h.y + dy * sp * dt, H * .35, H - h.h - 6); h.dir = dx < 0 ? -1 : dx > 0 ? 1 : h.dir; h.anim += dt * 8;
    h.fire -= dt; power = Math.max(0, power - dt);
    if (actHeld() && h.fire <= 0) { h.fire = power > 0 ? .11 : .15; const bx = h.x + h.w / 2, by = h.y; bullets.push({x: bx, y: by, vx: 0, vy: -560, r: 5}); if (power > 0) { bullets.push({x: bx, y: by, vx: -150, vy: -520, r: 5}); bullets.push({x: bx, y: by, vx: 150, vy: -520, r: 5}); } api.sfx('shoot'); }
    if (altPressed() && bombs > 0) { bombs--; api.sfx('bomb'); shake = .7; for (const e of enemies) if (e.alive) { e.alive = false; addScore(100); burst(e.x + e.w / 2, e.y + e.h / 2, '#fff', 8); } ebullets = []; for (const a of L.ast) a.dead = true; if (boss && boss.alive) { boss.hp -= 3; if (boss.hp <= 0) killBoss(); } }
    // rows arrive from the top in order
    while (spawnedRow + 1 < L.rows && scroll - (spawnedRow + 1) * TS >= 0) {
      spawnedRow++; const y = -TS;
      for (let x = 0; x < L.cols; x++) {
        const ch = L.g[spawnedRow][x];
        if ('efg'.includes(ch)) spawnAt({ch, x, y: 0}, y + TS); else if (ch === 'B') spawnAt({ch: 'B', x, y: 0}, y);
        else if (ch !== '.') L.ast.push({ch, x: x * TS + (ch === '#' ? 0 : 6), y: y, w: ch === '#' ? TS : TS - 12, h: ch === '#' ? TS : TS - 12, spin: r2() * 6});
      }
      for (const s of L.spawns) if (s.y === spawnedRow) { if (s.ch === 'B') spawnAt(s, -TS * 2); else spawnAt(s, -TS * .5); }
    }
    if (spawnedRow >= L.rows - 1 && scroll - L.rows * TS > H + TS) L.lastRowPassed = true;
    if (L.lastRowPassed && (!boss || !boss.alive) && screen === 'play') clearLevel();
    for (const a of L.ast) {
      a.y += speed * dt; a.spin += dt;
      if (a.y > H + TS) a.dead = true;
      if (!a.dead && overlap(h, a)) { if (a.ch === 'o') { a.dead = true; coins++; addScore(100, a.x, a.y); api.sfx('coin'); } else if (a.ch === '*') { a.dead = true; addScore(500, a.x, a.y); api.sfx('gem'); power = 9; } else if (a.ch === 'k') { a.dead = true; keys++; api.sfx('key'); } else { hurt(a); if (a.ch === '^') { a.dead = true; burst(a.x + a.w / 2, a.y + a.h / 2, '#ff6b7a', 8); } } }
    }
    L.ast = L.ast.filter(a => !a.dead);
    for (const e of enemies) {
      if (!e.alive) continue; e.t += dt; e.anim += dt * 6;
      const v = 70 * e.speed * DIFF;
      if (e.behavior === 'chase') { e.x += Math.sign(h.x - e.x) * v * .9 * dt; e.y += (speed + v * .6) * dt; }
      else if (e.behavior === 'fly') { e.x += Math.sin(e.t * 4) * v * 2.2 * dt; e.y += (speed + v * 1.1) * dt; }
      else if (e.behavior === 'shoot') { if (e.y < 110) e.y += (speed + v) * dt; else if (e.t < 4.5) { e.x += Math.sin(e.t * 1.5) * v * .6 * dt; e.shootT -= dt; if (e.shootT <= 0) { e.shootT = 1.4 / DIFF; const a = Math.atan2(h.y - e.y, h.x - e.x); ebullets.push({x: e.x + e.w / 2, y: e.y + e.h, vx: Math.cos(a) * 180 * DIFF, vy: Math.sin(a) * 180 * DIFF, r: 6, life: 4}); api.sfx('shoot'); } } else e.y += (speed + v * 1.5) * dt; }
      else { e.x += Math.sin(e.t * 2 + e.home.x) * v * .5 * dt; e.y += (speed + v * .7) * dt; }
      e.dir = Math.sin(e.t * 2) > 0 ? 1 : -1;
      if (e.y > H + TS * 2) { e.alive = false; continue; }
      for (const b of bullets) if (!b.dead && overlap({x: b.x - b.r, y: b.y - b.r, w: b.r * 2, h: b.r * 2}, e)) { b.dead = true; e.alive = false; addScore(150, e.x + e.w / 2, e.y); api.sfx('explode'); burst(e.x + e.w / 2, e.y + e.h / 2, '#ffb3c6', 10); break; }
      if (e.alive && overlap(h, e)) { hurt(e); e.alive = false; burst(e.x + e.w / 2, e.y + e.h / 2, '#fff', 8); }
    }
    if (boss && boss.alive) {
      const b = boss; b.t += dt; b.inv = Math.max(0, b.inv - dt); b.anim += dt * 4;
      if (b.y < 70) b.y += 80 * dt; else { b.x += Math.sin(b.t * .9) * 130 * b.speed * dt; b.x = clamp(b.x, 0, W - b.w); b.phase -= dt; if (b.phase <= 0) { b.phase = 1.5 / DIFF; api.sfx('shoot'); for (let i = -2; i <= 2; i++) { const a = Math.PI / 2 + i * .32; ebullets.push({x: b.x + b.w / 2, y: b.y + b.h, vx: Math.cos(a) * 150 * DIFF, vy: Math.sin(a) * 150 * DIFF, r: 7, life: 5}); } } }
      b.dir = Math.sin(b.t * .9) > 0 ? 1 : -1;
      for (const s of bullets) if (!s.dead && overlap({x: s.x - s.r, y: s.y - s.r, w: s.r * 2, h: s.r * 2}, b)) { s.dead = true; if (b.inv <= 0) { b.hp--; b.inv = .08; api.sfx('hit'); burst(s.x, s.y, '#ffd23f', 3, 80); if (b.hp <= 0) killBoss(); } }
      if (b.alive && overlap(h, b)) hurt(b);
    }
    for (const b of bullets) { b.x += b.vx * dt; b.y += b.vy * dt; if (b.y < -20) b.dead = true; for (const a of L.ast) if (a.ch === '#' && !b.dead && overlap({x: b.x - 2, y: b.y - 2, w: 4, h: 4}, a)) { b.dead = true; burst(b.x, b.y, '#aaa', 3, 60); } }
    bullets = bullets.filter(b => !b.dead);
    cam.x = 0; cam.y = 0;
  }

  /* ── shared per-frame ── */
  function update(dt) {
    time += dt;
    if (screen === 'title') { if (api.pressed('Enter') || actPressed()) { screen = 'intro'; deadT = 1.4; api.sfx('select'); api.voice('start'); api.music(true, level); } return; }
    if (screen === 'intro') { deadT -= dt; if (deadT <= 0) screen = 'play'; return; }
    if (screen === 'dying') { deadT -= dt; stepFx(dt); if (deadT <= 0) screen = 'lose'; return; }
    if (screen === 'win' || screen === 'lose') { if (api.pressed('Enter') || actPressed()) { level = 0; score = 0; lives = R.lives || 3; bombs = 1; resetLevel(); screen = 'intro'; deadT = 1.4; api.sfx('select'); api.music(true, 0); } return; }
    lt += dt; if (levelTime) { levelTime -= dt; if (levelTime <= 0) { levelTime = 0; if (R.win === 'survive') { clearLevel(); return; } lives = 1; hurt(); return; } }
    hero.inv = Math.max(0, hero.inv - dt); hero.hurtT = Math.max(0, hero.hurtT - dt);
    if (mold === 'platformer') stepPlatformer(dt); else if (mold === 'topdown') stepTopdown(dt); else stepShooter(dt);
    for (const b of ebullets) { b.x += b.vx * dt; b.y += b.vy * dt; b.life -= dt; if (b.life <= 0) b.dead = true; if (mold !== 'shooter' && solid(tile(Math.floor(b.x / TS), Math.floor(b.y / TS)))) b.dead = true; if (!b.dead && overlap({x: b.x - b.r, y: b.y - b.r, w: b.r * 2, h: b.r * 2}, hero)) { b.dead = true; hurt(b); } }
    ebullets = ebullets.filter(b => !b.dead);
    enemies = enemies.filter(e => e.alive || e.y < 1e9);
    stepFx(dt);
    api.report({score, level: level + 1, lives});
  }
  function stepFx(dt) {
    shake = Math.max(0, shake - dt);
    for (const p of particles) { p.x += p.vx * dt; p.y += p.vy * dt; p.vy += (mold === 'shooter' ? 60 : 420) * dt; p.life -= dt; }
    particles = particles.filter(p => p.life > 0);
    for (const p of pops) { p.y -= 40 * dt; p.life -= dt; } pops = pops.filter(p => p.life > 0);
    if (msg) { msg.t -= dt; if (msg.t <= 0) msg = null; }
  }

  /* ── drawing ── */
  const theme = () => L.theme;
  function drawSprite(c, a, frame, flip) {
    const img = sheet(a.key); if (!img) return;
    const dw = a.dh * FW / FH, dx = a.x + a.w / 2 - dw / 2, dy = a.y + a.h - a.dh;
    c.save(); if (flip) { c.translate(dx + dw, dy); c.scale(-1, 1); } else c.translate(dx, dy);
    if (a.inv > 0 && Math.floor(time * 14) % 2 === 0) c.globalAlpha = .45;
    c.drawImage(img, frame * FW, 0, FW, FH, 0, 0, dw, a.dh); c.restore();
  }
  function actorFrame(a, moving) { return a.hurtT > 0 ? FR.hurt : (mold === 'platformer' && !a.ground && a !== hero && a.behavior !== 'fly') ? FR.idle : (mold === 'platformer' && a === hero && !a.ground) ? FR.jump : moving ? FR.walk[Math.floor(a.anim) % 4] : FR.idle; }
  function shadow(c, a) { c.fillStyle = 'rgba(0,0,0,.22)'; c.beginPath(); c.ellipse(a.x + a.w / 2, a.y + a.h, a.w * .9, a.w * .3, 0, 0, 6.2832); c.fill(); }
  function drawBackdrop(c) {
    const img = L.bg; c.fillStyle = theme().sky || '#1b2440'; c.fillRect(0, 0, W, H);
    if (!img) return;
    if (mold === 'shooter') { const h = img.height * (W / img.width), off = (scroll * .5) % (h * 2); for (let k = -1; k < 3; k++) { const y = -off + k * h; c.save(); if (k % 2) { c.translate(0, y + h); c.scale(1, -1); c.drawImage(img, 0, 0, W, h); } else c.drawImage(img, 0, y, W, h); c.restore(); } return; }
    const w = img.width * (H / img.height), off = (cam.x * .3 + cam.y * .1) % (w * 2);
    for (let k = -1; k < 3; k++) { const x = -off + k * w; if (x > W || x + w < 0) continue; c.save(); if (k % 2) { c.translate(x + w, 0); c.scale(-1, 1); c.drawImage(img, 0, 0, w, H); } else c.drawImage(img, x, 0, w, H); c.restore(); }
  }
  function drawTiles(c) {
    const T = theme(), ground = T.ground || '#5b4636', top = T.top || '#8bc34a', edge = T.edge || 'rgba(0,0,0,.25)';
    const x0 = Math.max(0, Math.floor(cam.x / TS)), x1 = Math.min(L.cols - 1, Math.floor((cam.x + W) / TS)), y0 = Math.max(0, Math.floor(cam.y / TS)), y1 = Math.min(L.rows - 1, Math.floor((cam.y + H) / TS));
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
      const ch = L.g[y][x], px = x * TS, py = y * TS; if (ch === '.') continue;
      if (ch === '#') { c.fillStyle = ground; c.fillRect(px, py, TS, TS); const open = tile(x, y - 1) !== '#'; if (open) { c.fillStyle = top; c.fillRect(px, py, TS, 7); } c.fillStyle = edge; c.fillRect(px, py + TS - 3, TS, 3); if (tile(x - 1, y) !== '#') c.fillRect(px, py, 2, TS); if (tile(x + 1, y) !== '#') c.fillRect(px + TS - 2, py, 2, TS); if (mold === 'topdown' && open) { c.fillStyle = 'rgba(255,255,255,.08)'; c.fillRect(px + 4, py + 10, TS - 8, 3); } }
      else if (ch === '=') { c.fillStyle = top; c.fillRect(px, py, TS, 8); c.fillStyle = edge; c.fillRect(px, py + 6, TS, 3); }
      else if (ch === 'o') { const bob = Math.sin(time * 5 + x) * 2; c.fillStyle = '#ffd23f'; c.beginPath(); c.arc(px + TS / 2, py + TS / 2 + bob, 8, 0, 6.2832); c.fill(); c.fillStyle = '#b8860b'; c.beginPath(); c.arc(px + TS / 2, py + TS / 2 + bob, 5, 0, 6.2832); c.fill(); c.fillStyle = '#fff6c2'; c.fillRect(px + TS / 2 - 5, py + TS / 2 - 6 + bob, 3, 3); }
      else if (ch === '*') { const bob = Math.sin(time * 4 + x) * 3; c.fillStyle = '#7bf5c4'; c.beginPath(); c.moveTo(px + TS / 2, py + 5 + bob); c.lineTo(px + TS - 7, py + TS / 2 + bob); c.lineTo(px + TS / 2, py + TS - 5 + bob); c.lineTo(px + 7, py + TS / 2 + bob); c.closePath(); c.fill(); c.fillStyle = '#e8fff6'; c.fillRect(px + TS / 2 - 3, py + 11 + bob, 4, 4); }
      else if (ch === '^') { c.fillStyle = '#cfd6e6'; for (let i = 0; i < 3; i++) { c.beginPath(); c.moveTo(px + i * 12, py + TS); c.lineTo(px + i * 12 + 6, py + TS * .4); c.lineTo(px + i * 12 + 12, py + TS); c.fill(); } c.fillStyle = '#8c95a8'; c.fillRect(px, py + TS - 4, TS, 4); }
      else if (ch === 'j') { c.fillStyle = '#c9a86a'; c.fillRect(px + 6, py + TS - 6, TS - 12, 6); c.strokeStyle = '#ff6b7a'; c.lineWidth = 3; c.beginPath(); for (let i = 0; i < 4; i++) { c.moveTo(px + 8, py + TS - 8 - i * 6); c.lineTo(px + TS - 8, py + TS - 11 - i * 6); } c.stroke(); c.fillStyle = '#ffd23f'; c.fillRect(px + 4, py + TS - 30, TS - 8, 5); }
      else if (ch === 'k') { const bob = Math.sin(time * 4) * 2; c.fillStyle = '#ffd23f'; c.beginPath(); c.arc(px + 12, py + 14 + bob, 7, 0, 6.2832); c.fill(); c.fillStyle = T.sky || '#1b2440'; c.beginPath(); c.arc(px + 12, py + 14 + bob, 3, 0, 6.2832); c.fill(); c.fillStyle = '#ffd23f'; c.fillRect(px + 17, py + 12 + bob, 14, 4); c.fillRect(px + 25, py + 16 + bob, 3, 5); c.fillRect(px + 29, py + 16 + bob, 3, 7); }
      else if (ch === 'd') { c.fillStyle = '#7a4b2a'; c.fillRect(px, py, TS, TS); c.fillStyle = '#4a2a14'; c.fillRect(px + 4, py + 4, TS - 8, TS - 4); c.fillStyle = '#ffd23f'; c.beginPath(); c.arc(px + TS - 11, py + TS / 2, 3, 0, 6.2832); c.fill(); c.fillStyle = 'rgba(255,255,255,.35)'; c.fillRect(px + 8, py + 8, 4, 4); }
      else if (ch === 'G') { if (mold === 'topdown') { c.fillStyle = '#2b2b3b'; c.fillRect(px + 2, py + 2, TS - 4, TS - 4); const gr = c.createRadialGradient(px + TS / 2, py + TS / 2, 2, px + TS / 2, py + TS / 2, TS * .6); gr.addColorStop(0, '#9effd6'); gr.addColorStop(1, 'rgba(0,0,0,0)'); c.fillStyle = gr; c.fillRect(px, py, TS, TS); } }
    }
    if (mold === 'platformer' && L.goal) { const px = L.goal.x * TS, py = L.goal.y * TS; c.fillStyle = '#e8e8e8'; c.fillRect(px + 10, py - TS * 1.6, 5, TS * 2.6); c.fillStyle = (R.win === 'boss' && boss && boss.alive) || (R.win === 'coins' && coins < L.coinsTotal) ? '#7a7f8c' : '#7bf5c4'; c.beginPath(); c.moveTo(px + 15, py - TS * 1.6); c.lineTo(px + 15 + 28 + Math.sin(time * 6) * 3, py - TS * 1.2); c.lineTo(px + 15, py - TS * .8); c.fill(); }
  }
  function drawWorld(c) {
    c.save(); c.translate(-Math.round(cam.x), -Math.round(cam.y));
    if (mold !== 'shooter') drawTiles(c);
    else for (const a of L.ast) { if (a.ch === '#') { c.fillStyle = theme().ground || '#6b6f7a'; c.beginPath(); c.arc(a.x + a.w / 2, a.y + a.h / 2, a.w * .55, 0, 6.2832); c.fill(); c.fillStyle = 'rgba(255,255,255,.18)'; c.beginPath(); c.arc(a.x + a.w * .4, a.y + a.h * .4, a.w * .2, 0, 6.2832); c.fill(); } else if (a.ch === '^') { c.fillStyle = '#ff6b7a'; c.beginPath(); for (let i = 0; i < 8; i++) { const r = i % 2 ? a.w * .5 : a.w * .3, an = a.spin + i * .785; c.lineTo(a.x + a.w / 2 + Math.cos(an) * r, a.y + a.h / 2 + Math.sin(an) * r); } c.closePath(); c.fill(); } else if (a.ch === 'o') { c.fillStyle = '#ffd23f'; c.beginPath(); c.arc(a.x + a.w / 2, a.y + a.h / 2, 8, 0, 6.2832); c.fill(); } else if (a.ch === '*') { c.fillStyle = '#7bf5c4'; c.beginPath(); c.moveTo(a.x + a.w / 2, a.y); c.lineTo(a.x + a.w, a.y + a.h / 2); c.lineTo(a.x + a.w / 2, a.y + a.h); c.lineTo(a.x, a.y + a.h / 2); c.fill(); } }
    for (const e of enemies) if (e.alive) { if (mold !== 'shooter') shadow(c, e); drawSprite(c, e, actorFrame(e, true), e.dir < 0); }
    if (boss && boss.alive) { if (mold !== 'shooter') shadow(c, boss); drawSprite(c, boss, boss.inv > .5 ? FR.hurt : actorFrame(boss, true), boss.dir < 0); const b = boss, bw = b.w * 2.2; c.fillStyle = 'rgba(0,0,0,.5)'; c.fillRect(b.x + b.w / 2 - bw / 2, b.y - 14, bw, 7); c.fillStyle = '#ff6b7a'; c.fillRect(b.x + b.w / 2 - bw / 2, b.y - 14, bw * b.hp / b.hpMax, 7); }
    for (const b of ebullets) { c.fillStyle = '#ff9f1c'; c.beginPath(); c.arc(b.x, b.y, b.r, 0, 6.2832); c.fill(); c.fillStyle = '#fff3c4'; c.beginPath(); c.arc(b.x, b.y, b.r * .45, 0, 6.2832); c.fill(); }
    for (const b of bullets) { c.fillStyle = '#9effd6'; c.fillRect(b.x - 2, b.y - 8, 4, 14); }
    if (mold !== 'shooter') shadow(c, hero);
    const sw = mold === 'topdown' ? swingBox() : null;
    if (sw) { c.fillStyle = 'rgba(255,255,255,.35)'; c.beginPath(); c.ellipse(sw.x + sw.w / 2, sw.y + sw.h / 2, sw.w * .55, sw.h * .55, 0, 0, 6.2832); c.fill(); }
    const moving = mold === 'platformer' ? Math.abs(hero.vx) > 20 : hero.anim > 0 && (left() || right() || up() || down());
    if (screen !== 'dying' || Math.floor(time * 10) % 2) drawSprite(c, hero, screen === 'dying' ? FR.hurt : actorFrame(hero, moving), hero.dir < 0);
    for (const p of particles) { c.globalAlpha = Math.min(1, p.life * 2); c.fillStyle = p.color; c.fillRect(p.x, p.y, p.size, p.size); } c.globalAlpha = 1;
    c.font = 'bold 15px ui-monospace, Menlo, monospace'; c.textAlign = 'center';
    for (const p of pops) { c.globalAlpha = Math.min(1, p.life * 2); c.fillStyle = '#000'; c.fillText(p.text, p.x + 1, p.y + 1); c.fillStyle = p.color; c.fillText(p.text, p.x, p.y); } c.globalAlpha = 1; c.textAlign = 'left';
    c.restore();
  }
  function text(c, s, x, y, size, color, align) { c.font = `bold ${size}px ui-monospace, Menlo, monospace`; c.textAlign = align || 'left'; c.fillStyle = 'rgba(0,0,0,.55)'; c.fillText(s, x + 2, y + 2); c.fillStyle = color || '#fff'; c.fillText(s, x, y); }
  function hud(c) {
    c.fillStyle = 'rgba(8,10,18,.62)'; c.fillRect(0, 0, W, 34);
    text(c, String(score).padStart(6, '0'), 14, 23, 15, '#ffd23f');
    text(c, '♥ ' + lives, 130, 23, 15, '#ff889f');
    text(c, '◎ ' + coins + (L.coinsTotal && mold !== 'shooter' ? '/' + L.coinsTotal : ''), 200, 23, 15, '#ffe9a6');
    if (keys) text(c, '⚷ ' + keys, 300, 23, 15, '#fff');
    if (mold === 'shooter') text(c, '✸ ' + bombs, 300, 23, 15, '#9effd6');
    if (levelTime) text(c, Math.ceil(levelTime) + 's', W / 2, 23, 15, levelTime < 10 ? '#ff6b7a' : '#fff', 'center');
    text(c, (level + 1) + '/' + levels.length + '  ' + L.name.slice(0, 30), W - 14, 23, 13, '#cfd6e6', 'right');
    if (msg) { text(c, msg.text, W / 2, 62, 16, '#fff', 'center'); }
  }
  function wrap(c, s, x, y, size, maxW, color) { c.font = `${size}px ui-monospace, Menlo, monospace`; const words = s.split(/\s+/); let line = '', yy = y; for (const w of words) { const t = line ? line + ' ' + w : w; if (c.measureText(t).width > maxW && line) { text(c, line, x, yy, size, color, 'center'); line = w; yy += size * 1.35; } else line = t; } if (line) text(c, line, x, yy, size, color, 'center'); return yy; }
  function overlay(c, kind) {
    c.fillStyle = 'rgba(6,8,16,.78)'; c.fillRect(0, 0, W, H);
    const accent = theme().accent || '#7bf5c4';
    if (kind === 'title') {
      const img = sheet('hero'); if (img) { c.save(); c.translate(120, 118); c.drawImage(img, FR.win * FW, 0, FW, FH, 0, 0, 150, 225); c.restore(); }
      text(c, (spec.title || 'TOON GAME').slice(0, 26).toUpperCase(), 450, 118, 34, accent, 'center');
      const y = wrap(c, spec.pitch || MOLDS[mold].blurb, 450, 160, 15, 440, '#cfd6e6');
      wrap(c, MOLDS[mold].controls, 450, y + 44, 13, 440, '#9aa6bd');
      text(c, 'PRESS ENTER OR A', 450, 335, 18, Math.sin(time * 3) > -.4 ? '#ffd23f' : '#79849b', 'center');
      text(c, (cast.hero && cast.hero.name ? cast.hero.name + ' · ' : '') + levels.length + (levels.length > 1 ? ' stages' : ' stage') + ' · ' + (R.difficulty || 'normal'), 450, 380, 12, '#79849b', 'center');
    } else if (kind === 'intro') { text(c, 'STAGE ' + (level + 1), W / 2, 190, 22, '#9aa6bd', 'center'); text(c, L.name.toUpperCase(), W / 2, 236, 30, accent, 'center'); }
    else if (kind === 'win') { text(c, 'YOU WIN!', W / 2, 150, 40, accent, 'center'); text(c, 'SCORE ' + score, W / 2, 205, 20, '#fff', 'center'); wrap(c, spec.winText || 'Every stage cleared. ' + (cast.hero && cast.hero.name ? cast.hero.name + ' takes a bow.' : ''), W / 2, 250, 15, 500, '#cfd6e6'); text(c, 'PRESS ENTER OR A TO PLAY AGAIN', W / 2, 335, 16, Math.sin(time * 3) > -.4 ? '#ffd23f' : '#79849b', 'center'); }
    else if (kind === 'lose') { text(c, 'GAME OVER', W / 2, 150, 40, '#ff6b7a', 'center'); text(c, 'SCORE ' + score + '  ·  STAGE ' + (level + 1), W / 2, 205, 20, '#fff', 'center'); wrap(c, spec.loseText || 'You can make it. One more run.', W / 2, 250, 15, 500, '#cfd6e6'); text(c, 'PRESS ENTER OR A TO TRY AGAIN', W / 2, 335, 16, Math.sin(time * 3) > -.4 ? '#ffd23f' : '#79849b', 'center'); }
  }
  function render(c) {
    c.save();
    if (shake > 0) c.translate((r2() - .5) * shake * 18, (r2() - .5) * shake * 18);
    drawBackdrop(c); drawWorld(c); hud(c);
    c.restore();
    if (screen !== 'play' && screen !== 'dying') overlay(c, screen);
  }
  resetLevel();
  return {update, render,
    save() { return {level, score, lives, screen: screen === 'play' ? 'intro' : screen}; },
    load(d) { if (!d || !Number.isInteger(d.level) || d.level < 0 || d.level >= levels.length || !Number.isFinite(d.score)) return; level = d.level; score = Math.max(0, d.score); lives = clamp(d.lives | 0, 1, 99); resetLevel(); screen = 'title'; }};
}

/* ─── the host: canvas, input, audio, pause/restart, checkpoints. Used by the cartridge page and the Game Creator preview. ─── */
function boot(cfg, els) {
  const canvas = els.canvas, ctx = canvas.getContext('2d');
  const keys = new Set(), edges = new Set(); let paused = false, muted = false, actx = null, game, last = 0, saveT = 0;
  const sfxSet = SFX.set(cfg.sfxStyle || 'chip', cfg.sfx), buffers = {};
  const mk = src => Object.assign(new Audio(src), {loop: true, volume: cfg.musicVolume ?? .5}), tracks = {def: cfg.music ? mk(cfg.music) : null, lv: (cfg.levelMusic || []).map(u => u ? mk(u) : null)}; let cur = null;
  const voices = {}; for (const k in cfg.voices || {}) if (cfg.voices[k]) voices[k] = new Audio(cfg.voices[k]);
  let lastVoice = 0;
  const api = {cfg, keys, images: {}, pressed(k) { const h = edges.has(k); edges.delete(k); return h; },
    sfx(name) { if (muted || !actx) return; try { let b = buffers[name]; if (!b) { const d = renderSfx(sfxSet[name] || SFX_PRESETS[name] || SFX_PRESETS.select); b = actx.createBuffer(1, d.length, 44100); b.getChannelData(0).set(d); buffers[name] = b; } const s = actx.createBufferSource(); s.buffer = b; s.connect(actx.destination); s.start(); } catch (e) {} },
    voice(name) { const v = voices[name]; if (!v || muted) return; if (name === 'hurt' && performance.now() - lastVoice < 4000) return; lastVoice = performance.now(); try { for (const k in voices) if (voices[k] !== v) { voices[k].pause(); voices[k].currentTime = 0; } v.currentTime = 0; v.play().catch(() => {}); } catch (e) {} },
    music(on, idx) { const t = idx !== undefined ? (tracks.lv[idx] || tracks.def) : (cur || tracks.def); try { if (!on) { if (cur) cur.pause(); return; } if (muted || !t) return; if (cur && cur !== t) { cur.pause(); cur.currentTime = 0; } cur = t; cur.play().catch(() => {}); } catch (e) {} },
    report(v) { if (els.status) els.status.textContent = (v.won ? 'YOU WIN · ' : '') + 'SCORE ' + (v.score | 0) + ' · STAGE ' + (v.level || 1); }};
  const load = src => new Promise(res => { if (!src) return res(null); const im = new Image(); im.onload = () => res(im); im.onerror = () => res(null); im.src = src; });
  const ready = (async () => {
    for (const k in cfg.sheets || {}) api.images[k] = await load(cfg.sheets[k]);
    api.images.bg = []; for (const b of cfg.backdrops || []) api.images.bg.push(await load(b));
    game = createGame(api);
    try { const s = localStorage.getItem('toongame.save.' + cfg.id); if (s) game.load(JSON.parse(s)); } catch (e) {}
    if (els.instructions) els.instructions.textContent = MOLDS[cfg.spec.mold] ? MOLDS[cfg.spec.mold].controls + ' · P pause · R restart' : '';
    requestAnimationFrame(frame);
  })();
  function audioOn() { if (!actx) { try { actx = new AudioContext(); } catch (e) {} } if (actx && actx.state === 'suspended') actx.resume(); }
  function persist() { try { const s = game && game.save(); if (s) localStorage.setItem('toongame.save.' + cfg.id, JSON.stringify(s)); } catch (e) {} }
  function setPause(v) { paused = v; keys.clear(); edges.clear(); if (els.pause) els.pause.textContent = paused ? 'Resume' : 'Pause'; if (paused) api.music(false); else if (game) api.music(true); }
  function restart() { keys.clear(); edges.clear(); game = createGame(api); setPause(false); canvas.focus(); }
  const CODES = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Space', 'KeyZ', 'KeyX', 'Enter', 'KeyW', 'KeyA', 'KeyS', 'KeyD', 'KeyR', 'KeyP', 'Escape'];
  window.addEventListener('keydown', e => { if (!CODES.includes(e.code)) return; e.preventDefault(); audioOn(); if (e.code === 'KeyP' || e.code === 'Escape') { if (!e.repeat) setPause(!paused); return; } if (e.code === 'KeyR') { if (!e.repeat) restart(); return; } if (!keys.has(e.code)) edges.add(e.code); keys.add(e.code); });
  window.addEventListener('keyup', e => keys.delete(e.code)); window.addEventListener('blur', () => { keys.clear(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden && game) { persist(); setPause(true); } });
  for (const b of document.querySelectorAll('[data-key]')) { b.addEventListener('pointerdown', e => { e.preventDefault(); b.setPointerCapture(e.pointerId); audioOn(); if (!keys.has(b.dataset.key)) edges.add(b.dataset.key); keys.add(b.dataset.key); }); for (const t of ['pointerup', 'pointercancel', 'lostpointercapture']) b.addEventListener(t, () => keys.delete(b.dataset.key)); }
  canvas.addEventListener('pointerdown', () => { audioOn(); canvas.focus(); });
  if (els.pause) els.pause.onclick = () => { setPause(!paused); canvas.focus(); };
  if (els.restart) els.restart.onclick = restart;
  if (els.mute) els.mute.onclick = () => { muted = !muted; els.mute.textContent = muted ? 'Sound on' : 'Mute'; if (muted) api.music(false); else { audioOn(); api.music(true); } canvas.focus(); };
  function frame(now) {
    const dt = Math.min(.04, (now - last) / 1000 || .016); last = now;
    try { if (!paused) { game.update(dt); edges.clear(); saveT += dt; if (saveT > 3) { persist(); saveT = 0; } } ctx.save(); game.render(ctx); ctx.restore();
      if (paused) { ctx.fillStyle = 'rgba(0,0,0,.55)'; ctx.fillRect(0, 0, W, H); ctx.fillStyle = '#fff'; ctx.font = 'bold 28px ui-monospace, monospace'; ctx.textAlign = 'center'; ctx.fillText('PAUSED', W / 2, H / 2); ctx.textAlign = 'left'; }
    } catch (e) { console.error(e); if (els.failure) { els.failure.style.display = 'block'; els.failure.textContent = 'The game hit an error: ' + e.message; } return; }
    requestAnimationFrame(frame);
  }
  return {ready, restart, get game() { return game; }, api};
}
return {W, H, TS, FW, FH, MOLDS, TILES, BEHAVIORS, SFX, generate, createGame, boot, rng, hashStr};
})();
JS;

const GAME_HOST_HTML = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; media-src data:; connect-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'">
<title>__TITLE__</title><style>
*{box-sizing:border-box}body{margin:0;background:#0b0e17;color:#e7eaf2;font:14px system-ui,sans-serif;text-align:center}canvas{display:block;width:min(100%,calc((100vh - 150px)*1.77778));max-width:1152px;aspect-ratio:16/9;margin:auto;outline:none;background:#101827;touch-action:none;border-radius:6px}nav{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;flex-wrap:wrap}button{border:1px solid #374159;border-radius:7px;background:#1c2538;color:#eef2ff;padding:9px 15px;font-weight:700;cursor:pointer}button:focus-visible{outline:2px solid #7bf5c4}#instructions{padding:0 16px;color:#b7c2d9;font-size:12px;max-width:900px;margin:6px auto}.touch{display:flex;justify-content:space-between;max-width:640px;margin:10px auto;padding:0 14px;user-select:none;touch-action:none}.pad{display:grid;grid-template-columns:repeat(3,44px);gap:4px}.pad button{padding:12px 0}.pad .up{grid-column:2}.pad .left{grid-column:1}.actions{display:flex;align-items:center;gap:12px}.actions button{border-radius:50%;width:54px;height:54px;background:#6c4ff0}#failure{display:none;padding:18px;color:#ffadad;white-space:pre-wrap}#status{color:#7bf5c4;font:12px ui-monospace,monospace;padding:0 8px}.credit{color:#5e687c;font-size:11px;padding:4px 0 12px}
@media(min-width:700px) and (pointer:fine){.touch{display:none}}
</style></head><body><canvas width="768" height="432" tabindex="0" aria-label="Game screen"></canvas><div id="failure" role="alert"></div>
<nav><button id="pause">Pause</button><button id="restart">Restart</button><button id="mute">Mute</button><span id="status">READY</span></nav>
<p id="instructions"></p>
<div class="touch"><div class="pad"><button class="up" data-key="ArrowUp" aria-label="Up">▲</button><button class="left" data-key="ArrowLeft" aria-label="Left">◀</button><button data-key="ArrowDown" aria-label="Down">▼</button><button data-key="ArrowRight" aria-label="Right">▶</button></div><div class="actions"><button data-key="KeyZ">A</button><button data-key="KeyX">B</button><button data-key="Enter">Start</button></div></div>
<p class="credit">Made with Voice Swap · Game Creator</p>
<script>__RUNTIME__</script>
<script>
const CONFIG = __CONFIG__;
TG.boot(CONFIG, {canvas: document.querySelector('canvas'), status: document.getElementById('status'), pause: document.getElementById('pause'), restart: document.getElementById('restart'), mute: document.getElementById('mute'), instructions: document.getElementById('instructions'), failure: document.getElementById('failure')});
</script></body></html>
HTML;

const INSTALL_SH = <<<'SH'
#!/bin/bash
# Installs Seed-VC + Demucs + Kokoro TTS into ./venv. Usage: install.sh /path/to/python3.x
set -euo pipefail
PY="$1"
cd "$(dirname "$0")"
echo "==> Using $PY ($("$PY" --version))"
command -v ffmpeg >/dev/null || { echo "!! ffmpeg not found. Run: brew install ffmpeg"; exit 1; }
command -v git >/dev/null || { echo "!! git not found. Run: xcode-select --install"; exit 1; }
if [ ! -f seed-vc/inference.py ]; then
  echo "==> Fetching Seed-VC (__COMMIT__)"
  rm -rf seed-vc && mkdir seed-vc && cd seed-vc
  git init -q && git remote add origin __REPO__
  git fetch -q --depth 1 origin __COMMIT__ && git checkout -q FETCH_HEAD
  cd ..
fi
[ -x venv/bin/python ] || { echo "==> Creating virtualenv"; "$PY" -m venv venv; }
echo "==> Installing Python packages (torch is ~200MB, be patient)"
# "python -m pip" (not venv/bin/pip) so a moved folder keeps working
./venv/bin/python -m pip install -q --upgrade pip
./venv/bin/python -m pip install --progress-bar off torch==2.5.1 torchaudio==2.5.1 numpy==1.26.4 scipy==1.13.1 \
  librosa==0.10.2 "huggingface-hub>=0.28.1,<1.0" munch==4.0.0 einops==0.8.0 transformers==4.46.3 \
  soundfile==0.12.1 pyyaml accelerate hydra-core==1.3.2 python-dotenv descript-audio-codec==1.0.0 demucs kokoro==0.9.4 ddgs
echo "==> Downloading models (~1.5GB, first time only)"
./venv/bin/python worker.py --prefetch
touch .ready
echo "==> ENGINE READY"
SH;

const WORKER_PY = <<<'PY'
"""Voice Swap worker: one job dir in, mixed MP3 out. Writes progress to <job>/status.json."""
import sys, os, json, time, math, re, subprocess, fcntl, traceback, shutil, argparse

ENGINE = os.path.dirname(os.path.abspath(__file__))
SVC = os.path.join(ENGINE, 'seed-vc')
os.environ.setdefault('HF_HOME', os.path.join(ENGINE, 'models'))
os.environ.setdefault('TORCH_HOME', os.path.join(ENGINE, 'models', 'torch'))
os.environ['PYTORCH_ENABLE_MPS_FALLBACK'] = '1'
PYTHON = sys.executable
RAM_GB = os.sysconf('SC_PAGE_SIZE') * os.sysconf('SC_PHYS_PAGES') / 2**30
# Seed-VC fits (your reference + a slice of the song) into one attention window.
# 30s needs ~8GB on its own, so smaller Macs use a shorter window and reference.
WINDOW, REF_MAX = (15, 6) if RAM_GB <= 9 else (22, 10) if RAM_GB <= 17 else (30, 15)
JOB = None
STATUS = {}


def st(**kw):
    STATUS.update(kw)
    tmp = os.path.join(JOB, 'status.tmp')
    with open(tmp, 'w') as f:
        json.dump(STATUS, f)
    os.replace(tmp, os.path.join(JOB, 'status.json'))


def log(*a):
    print('[voiceswap]', *a, flush=True)


def ffmpeg(*args):
    r = subprocess.run(['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', *args], capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError('ffmpeg failed: ' + r.stderr.strip()[-500:])


def duration(path):
    import soundfile as sf
    return sf.info(path).duration


def demucs_split(src, out_dir, p0, p1, label):
    """Run Demucs as a subprocess (frees its memory afterwards), mapping its tqdm progress onto p0..p1."""
    cmd = [PYTHON, '-m', 'demucs', '--two-stems=vocals', '-n', 'htdemucs', '-d', 'cpu', '-o', out_dir, src]
    proc = subprocess.Popen(cmd, stderr=subprocess.PIPE, stdout=subprocess.DEVNULL)
    buf, tail = b'', b''
    while True:
        ch = proc.stderr.read(256)
        if not ch:
            break
        buf = (buf + ch)[-400:]
        tail = (tail + ch)[-3000:]
        m = re.findall(rb'([\d.]+)/([\d.]+) \[', buf)
        if m:
            done, tot = map(float, m[-1])
            if tot:
                st(stage=f'{label} {int(100 * done / tot)}%', progress=p0 + (p1 - p0) * done / tot)
    if proc.wait():
        raise RuntimeError('Demucs failed: ' + tail.decode(errors='ignore')[-800:])
    base = os.path.splitext(os.path.basename(src))[0]
    d = os.path.join(out_dir, 'htdemucs', base)
    return os.path.join(d, 'vocals.wav'), os.path.join(d, 'no_vocals.wav')


def match_loudness(conv, orig, out):
    """Seed-VC output comes out much hotter than the separated vocal; match RMS so the mix keeps its balance."""
    import soundfile as sf, numpy as np
    x, sr = sf.read(conv, dtype='float32')
    o, _ = sf.read(orig, dtype='float32')
    rms = lambda a: float(np.sqrt(np.mean(np.square(a)))) + 1e-9
    gain = min(4.0, rms(o) / rms(x))
    log(f'vocal level match: gain {20 * math.log10(gain):+.1f} dB')
    sf.write(out, np.clip(x * gain, -1, 1), sr)
    return out


def load_seedvc():
    """Load Seed-VC's inference.py with the context window swapped for WINDOW (upstream file untouched).
    Apple-Silicon fixes are applied in patch_models:
       - RMVPE runs on the whole song at once and returns float64 -> 20s chunks, float32
       - BigVGAN hits an MPS length limit on long chunks -> vocode in short overlapping windows."""
    import types
    sys.path.insert(0, SVC)
    os.chdir(SVC)
    path = os.path.join(SVC, 'inference.py')
    src = open(path).read()
    needle = 'max_context_window = sr // hop_length * 30'
    if needle not in src:
        raise RuntimeError('Unexpected Seed-VC version (context window line not found)')
    src = src.replace(needle, f'max_context_window = sr // hop_length * {WINDOW}')
    mod = types.ModuleType('inference')
    mod.__file__ = path
    exec(compile(src, path, 'exec'), mod.__dict__)
    return mod


def patch_models(inference, opts, src_dur, ref_dur):
    import torch, numpy as np
    orig = inference.load_models
    f0_mode = opts['mode'] == 'sing'
    sr, hop = (44100, 512) if f0_mode else (22050, 256)
    window = sr // hop * WINDOW - int(min(ref_dur, 25) * sr / hop)
    est_chunks = max(1, math.ceil((src_dur * sr / hop) / max(1, window - 16)))
    state = {'chunks': 0, 'f0calls': 0, 'ref_med': None}

    def patched(a):
        r = list(orig(a))
        f0_fn, voc = r[2], r[3]
        dev = inference.device

        if f0_fn is not None:
            def f0_wrapped(audio, **k):
                n, parts = 16000 * 20, []           # 16kHz input, RMVPE hop = 160 samples
                for s in range(0, len(audio), n):
                    o = np.asarray(f0_fn(audio[s:s + n], **k), dtype=np.float32)
                    parts.append(o if s + n >= len(audio) else o[:n // 160])
                f0 = np.concatenate(parts)
                if dev.type == 'mps':
                    torch.mps.empty_cache()
                state['f0calls'] += 1
                voiced = f0[f0 > 1]
                if state['f0calls'] == 1:          # reference (your voice) comes first
                    state['ref_med'] = float(np.median(voiced)) if voiced.size else None
                    return f0
                shift = float(opts['semitones'])
                if opts['auto_octave'] and state['ref_med'] and voiced.size:
                    diff = 12 * math.log2(state['ref_med'] / float(np.median(voiced)))
                    octs = int(round(diff / 12))   # whole octaves only, so the vocal stays in key with the beat
                    shift += 12 * octs
                    log(f'pitch: your median {state["ref_med"]:.0f}Hz, song {np.median(voiced):.0f}Hz -> octave shift {octs}')
                if shift:
                    st(pitch_shift=shift)
                    f0 = np.where(f0 > 1, f0 * (2 ** (shift / 12)), f0).astype(np.float32)
                return f0
            r[2] = f0_wrapped

        if dev.type == 'mps':
            size, ctx = 96 if hop == 256 else 48, 8   # keep each window's waveform < 65536 samples

            def vocode(mel):
                T, outs = mel.shape[-1], []
                for s in range(0, T, size):
                    a0, b0 = max(0, s - ctx), min(T, s + size + ctx)
                    w = voc(mel[..., a0:b0].float()).cpu()
                    outs.append(w[..., (s - a0) * hop:(s - a0 + min(size, T - s)) * hop])
                return chunk_done(torch.cat(outs, -1))
        else:
            def vocode(mel):
                return chunk_done(voc(mel.float()))

        def chunk_done(out):
            state['chunks'] += 1
            frac = min(0.98, state['chunks'] / est_chunks)
            st(stage=f'Converting voice {int(frac * 100)}%', progress=0.35 + 0.55 * frac)
            return out

        r[3] = vocode
        return tuple(r)

    inference.load_models = patched


def run_job():
    opts = json.load(open(os.path.join(JOB, 'job.json')))
    work = os.path.join(JOB, 'work')
    os.makedirs(work, exist_ok=True)
    st(state='queued', stage='Waiting for previous job…', progress=0)

    lock = open(os.path.join(ENGINE, 'run.lock'), 'w')
    fcntl.flock(lock, fcntl.LOCK_EX)              # one conversion at a time (memory!)
    t0 = time.time()
    st(state='running', stage='Preparing audio', progress=0.02, started=t0)

    tts = opts.get('tts')
    convert = not tts or tts.get('convert', True)   # text jobs can skip the voice swap ("AI voice only")

    # 1. normalise inputs (or write the speech, for text jobs)
    song = os.path.join(work, 'song.wav')
    if tts:
        st(stage='Reading your text out loud', progress=0.04)
        words = synth_speech(open(os.path.join(JOB, 'script.txt')).read(), tts, song)
        with open(os.path.join(JOB, 'words.json'), 'w') as f:
            json.dump(words, f)
    else:
        ffmpeg('-i', opts['song_path'], '-ac', '2', '-ar', '44100', song)
    if convert:
        ref = os.path.join(work, 'voice.wav')
        ffmpeg('-i', opts['voice_path'], '-ac', '1', '-ar', '44100', '-t', '90', ref)
        trimmed = os.path.join(work, 'voice_trim.wav')
        ffmpeg('-i', ref, '-af', 'silenceremove=start_periods=1:start_threshold=-45dB:stop_periods=-1:stop_duration=0.6:stop_threshold=-45dB', trimmed)
        if duration(trimmed) >= 3:
            ref = trimmed

    # 2. separation
    instrumental = None
    if opts['separate']:
        vocals, instrumental = demucs_split(song, os.path.join(work, 'sep'), 0.04, 0.28, 'Separating vocals from music')
    else:
        vocals = song
    if os.path.getsize(vocals) < 1000:
        raise RuntimeError('No vocals found in the target audio.')

    # 3. conversion
    if convert:
        if opts['clean_voice']:
            ref, _ = demucs_split(ref, os.path.join(work, 'sep_voice'), 0.28, 0.34, 'Cleaning your voice clip')
        capped = os.path.join(work, 'voice_ref.wav')
        ffmpeg('-i', ref, '-ac', '1', '-t', str(REF_MAX), capped)
        ref = capped
        log(f'RAM {RAM_GB:.0f}GB -> context window {WINDOW}s, voice reference {REF_MAX}s')
        st(stage='Loading voice model (first run downloads ~1.5GB)', progress=0.35)
        src_dur, ref_dur = duration(vocals), duration(ref)
        inference = load_seedvc()
        patch_models(inference, opts, src_dur, ref_dur)
        conv_dir = os.path.join(work, 'conv')
        shutil.rmtree(conv_dir, ignore_errors=True)
        inference.main(argparse.Namespace(
            source=vocals, target=ref, output=conv_dir,
            diffusion_steps=int(opts['steps']), length_adjust=1.0,
            inference_cfg_rate=float(opts['similarity']),
            f0_condition=opts['mode'] == 'sing', auto_f0_adjust=False, semi_tone_shift=0,
            checkpoint=None, config=None, fp16=True))
        conv = os.path.join(conv_dir, next(f for f in os.listdir(conv_dir) if f.endswith('.wav')))
        conv = match_loudness(conv, vocals, os.path.join(work, 'conv_matched.wav'))
    else:
        conv = vocals

    # 4. mix + export
    st(stage='Mixing & exporting MP3', progress=0.93)
    name = opts['out_name']
    files = {}
    vox = os.path.join(JOB, f'{name} (my vocals).mp3' if convert else f'{name} (voice).mp3')
    ffmpeg('-i', conv, '-ar', '44100', '-b:a', '320k', vox)
    files['vocals'] = os.path.basename(vox)
    if instrumental:
        mix = os.path.join(JOB, f'{name} (my voice).mp3')
        vv, mv, ov = opts['vocal_vol'] / 100, opts['music_vol'] / 100, opts['orig_vol'] / 100
        inputs = ['-i', conv, '-i', instrumental]
        graph = f'[0]aresample=44100,pan=stereo|c0=c0|c1=c0,volume={vv}[a];[1]volume={mv}[b];'
        if ov > 0:
            inputs += ['-i', vocals]
            graph += f'[2]volume={ov}[c];[a][b][c]amix=inputs=3:duration=longest:normalize=0,alimiter=limit=0.95[out]'
        else:
            graph += '[a][b]amix=inputs=2:duration=longest:normalize=0,alimiter=limit=0.95[out]'
        ffmpeg(*inputs, '-filter_complex', graph, '-map', '[out]', '-b:a', '320k', mix)
        files['mix'] = os.path.basename(mix)
        inst = os.path.join(JOB, f'{name} (instrumental).mp3')
        ffmpeg('-i', instrumental, '-b:a', '320k', inst)
        files['instrumental'] = os.path.basename(inst)
    if not opts.get('keep_work'):
        shutil.rmtree(work, ignore_errors=True)
    st(state='done', stage='Done', progress=1, files=files, finished=time.time(), seconds=round(time.time() - t0))


TTS_REPO = 'hexgrad/Kokoro-82M'


def synth_speech(text, o, out):
    """Kokoro TTS -> 44.1kHz mono wav at `out`. Returns word timings [[start, end, word], ...] (exact text, no ASR needed)."""
    try:
        from kokoro import KPipeline
    except ImportError:
        st(stage='Installing text-to-speech (one time)')
        subprocess.run([PYTHON, '-m', 'pip', 'install', '-q', 'kokoro==0.9.4'], check=True)
        from kokoro import KPipeline
    import numpy as np, soundfile as sf
    voice = o.get('voice') or 'am_michael'
    st(stage='Loading text-to-speech (first run downloads ~330MB)')
    pipe = KPipeline(lang_code=voice[0], repo_id=TTS_REPO)
    gap = np.zeros(int(24000 * 0.3), dtype=np.float32)   # a breath between lines
    parts, words, off, n = [], [], 0.0, 0
    for r in pipe(text, voice=voice, speed=float(o.get('speed', 1.0)), split_pattern=r'\n+'):
        if r.audio is None:
            continue
        a = r.audio.numpy().astype(np.float32)
        for tk in (r.tokens or []):
            w = (tk.text or '').strip()
            if not w or tk.start_ts is None:
                continue
            t0, t1 = off + tk.start_ts, off + (tk.end_ts if tk.end_ts is not None else tk.start_ts + 0.2)
            if words and not re.search(r'\w', w):      # punctuation rides on the previous word
                words[-1][2] += w
                continue
            words.append([round(t0, 2), round(t1, 2), w])
        parts += [a, gap]
        off += (len(a) + len(gap)) / 24000
        n += 1
        st(stage=f'Reading your text out loud ({off:.0f}s so far)')
    if not parts:
        raise RuntimeError('No speakable text found.')
    tmp = out + '.24k.wav'
    sf.write(tmp, np.concatenate(parts), 24000)
    ffmpeg('-i', tmp, '-ac', '1', '-ar', '44100', out)
    os.remove(tmp)
    log(f'tts: {n} lines, {off:.1f}s, {len(words)} words, voice {voice}')
    return words


# ── Toon Lab: lip-sync analysis ──
PHONE_MODEL = 'facebook/wav2vec2-lv-60-espeak-cv-ft'


def loudness_envelope(wav16):
    """RMS every 10ms, scaled so loud speech is ~1."""
    import numpy as np, soundfile as sf
    x, _ = sf.read(wav16, dtype='float32')
    n = len(x) // 160
    rms = np.sqrt(np.mean(np.square(x[:n * 160].reshape(n, 160)), axis=1))
    voiced = rms[rms > 1e-4]
    ref = float(np.percentile(voiced, 95)) if voiced.size else 1.0
    return [round(float(v), 3) for v in np.clip(rms / (ref + 1e-9), 0, 1.5)]


def recognize_phonemes(wav16, p0, p1):
    """wav2vec2 phoneme CTC -> [[seconds, ipa], ...]. Runs in 20s windows (+1s context each side) so long songs fit."""
    import torch, soundfile as sf
    from huggingface_hub import snapshot_download
    from transformers import Wav2Vec2ForCTC
    st(stage='Loading lip-sync model (first run downloads ~1.2GB)', progress=p0)
    d = snapshot_download(PHONE_MODEL, allow_patterns=['*.json', 'pytorch_model.bin'])
    inv = {v: k for k, v in json.load(open(os.path.join(d, 'vocab.json'))).items()}
    model = Wav2Vec2ForCTC.from_pretrained(d).eval()
    x, _ = sf.read(wav16, dtype='float32')
    hop, chunk, pad = 320, 16000 * 20, 16000          # the model emits one frame per 20ms
    ids = []
    for s in range(0, len(x), chunk):
        a, b = max(0, s - pad), min(len(x), s + chunk + pad)
        seg = x[a:b]
        seg = (seg - seg.mean()) / (seg.std() + 1e-7)
        with torch.no_grad():
            logits = model(torch.from_numpy(seg)[None]).logits[0]
        f0 = (s - a) // hop
        ids += logits.argmax(-1)[f0:f0 + chunk // hop].tolist()
        frac = min(1.0, (s + chunk) / len(x))
        st(stage=f'Reading mouth shapes {int(100 * frac)}%', progress=p0 + (p1 - p0) * frac)
    out, prev = [], None
    for i, p in enumerate(ids):
        tok = inv.get(p, '')
        if p != prev and tok.strip() and not tok.startswith('<') and tok != '|':
            out.append([round(i * 0.02, 2), tok])
        prev = p
    return out


def transcribe_words(wav16, p0):
    """Whisper (already cached for Seed-VC) -> [[start, end, word], ...]."""
    import soundfile as sf
    from transformers import pipeline
    st(stage='Listening for the words', progress=p0)
    x, sr = sf.read(wav16, dtype='float32')
    asr = pipeline('automatic-speech-recognition', model='openai/whisper-small', chunk_length_s=30, device='cpu')
    words = []
    for c in asr({'raw': x, 'sampling_rate': sr}, return_timestamps='word').get('chunks', []):
        t0, t1 = c['timestamp']
        if t0 is not None and c['text'].strip():
            words.append([round(t0, 2), round(t1 if t1 is not None else t0 + 0.4, 2), c['text'].strip()])
    return words


def run_toon():
    opts = json.load(open(os.path.join(JOB, 'job.json')))
    work = os.path.join(JOB, 'work')
    os.makedirs(work, exist_ok=True)
    t0 = time.time()
    st(state='running', stage='Preparing audio', progress=0.02, started=t0)
    vocals = opts['audio_path']
    if opts['isolate']:
        st(stage='Waiting for the voice-swap queue…')
        lock = open(os.path.join(ENGINE, 'run.lock'), 'w')
        fcntl.flock(lock, fcntl.LOCK_EX)              # Demucs is memory-hungry; never next to a conversion
        song = os.path.join(work, 'audio.wav')
        ffmpeg('-i', vocals, '-ac', '2', '-ar', '44100', song)
        vocals, _ = demucs_split(song, os.path.join(work, 'sep'), 0.05, 0.45, 'Isolating the voice')
        fcntl.flock(lock, fcntl.LOCK_UN)
    v16 = os.path.join(work, 'voice16.wav')
    ffmpeg('-i', vocals, '-ac', '1', '-ar', '16000', v16)
    result = {'duration': round(duration(v16), 3), 'hop': 0.01, 'env': loudness_envelope(v16)}
    known = opts.get('words_path') if opts.get('words_path') and os.path.exists(opts['words_path']) else None   # text jobs know their words
    asr = opts['words'] and not known
    result['phones'] = recognize_phonemes(v16, 0.5 if opts['isolate'] else 0.08, 0.8 if asr else 0.97)
    result['words'] = json.load(open(known)) if known else transcribe_words(v16, 0.8) if asr else []
    with open(os.path.join(JOB, 'result.json'), 'w') as f:
        json.dump(result, f)
    shutil.rmtree(work, ignore_errors=True)
    st(state='done', stage='Done', progress=1, finished=time.time(), seconds=round(time.time() - t0))


# ── agents: one spec -> speech -> lip-sync -> headless render ──
def read_status(d):
    try:
        return json.load(open(os.path.join(d, 'status.json')))
    except Exception:
        return {}


def run_child(flag, d, p0, p1, label):
    """Run another worker (its own process, so its memory is freed) and mirror its progress."""
    with open(os.path.join(d, 'log.txt'), 'w') as lf:
        proc = subprocess.Popen([PYTHON, os.path.join(ENGINE, 'worker.py'), *flag, d], stdout=lf, stderr=subprocess.STDOUT, cwd=ENGINE)
    with open(os.path.join(d, 'pid'), 'w') as f:
        f.write(str(proc.pid))
    while proc.poll() is None:
        time.sleep(1)
        s = read_status(d)
        st(stage=f'{label} · {s.get("stage", "…")}', progress=round(p0 + (p1 - p0) * float(s.get('progress', 0)), 3))
    s = read_status(d)
    if s.get('state') != 'done':
        raise RuntimeError(f'{label} failed: ' + str(s.get('error') or 'see ' + os.path.join(d, 'log.txt')))
    return s


def run_make():
    spec = json.load(open(os.path.join(JOB, 'spec.json')))
    env = spec['_env']
    t0 = time.time()
    st(state='running', stage='Starting', progress=0.01, started=t0)
    stamp = lambda: time.strftime('%y%m%d-%H%M%S', time.gmtime()) + '-' + os.urandom(3).hex()   # same clock as PHP's ids
    words_path = None
    if spec['script']:
        jid = stamp()
        jd = os.path.join(env['jobs'], jid)
        os.makedirs(jd)
        with open(os.path.join(jd, 'script.txt'), 'w') as f:
            f.write(spec['script'])
        voice = env.get('voice_path') or ''
        if voice:
            shutil.copy(voice, os.path.join(jd, 'voice' + os.path.splitext(voice)[1]))
            voice = os.path.join(jd, 'voice' + os.path.splitext(voice)[1])
        title = spec['title']
        job = {'title': '📝 ' + title, 'out_name': re.sub(r'[^\w\- ().]+', '_', title)[:60] or 'speech', 'voice_name': spec['voice'] or 'AI voice',
               'voice_path': voice, 'song_path': '', 'mode': 'speech', 'separate': False, 'clean_voice': bool(voice), 'auto_octave': False,
               'semitones': 0, 'steps': spec['steps'], 'similarity': 0.7, 'vocal_vol': 100, 'music_vol': 100, 'orig_vol': 0, 'created': time.time(),
               'tts': {'voice': spec['tts_voice'], 'speed': spec['speed'], 'convert': bool(voice)}, 'project': os.path.basename(JOB)}
        with open(os.path.join(jd, 'job.json'), 'w') as f:
            json.dump(job, f, indent=2)
        st(job=jid)
        s = run_child([], jd, 0.02, 0.5, 'Speaking the script' + (' in your voice' if voice else ''))
        audio = os.path.join(jd, s['files']['vocals'])
        st(audio_path=audio)
        words_path = os.path.join(jd, 'words.json')
    else:
        audio = spec['audio']
    tid = stamp()
    td = os.path.join(env['toons'], tid)
    os.makedirs(td)
    with open(os.path.join(td, 'job.json'), 'w') as f:
        json.dump({'audio_path': audio, 'isolate': bool(spec.get('music')) and not words_path, 'words': True, 'words_path': words_path, 'created': time.time()}, f)
    s = run_child(['--toon'], td, 0.5, 0.72, 'Lip-syncing')
    dur = float(json.load(open(os.path.join(td, 'result.json'))).get('duration', 30))
    st(toon=tid, duration=dur)
    bgm = spec.get('bgm') or {}
    if bgm.get('job'):   # background music was composing in parallel; wait for it (a failure just means no music)
        md = os.path.join(env['music'], '.jobs', bgm['job'])
        for i in range(600):
            ms = json.load(open(os.path.join(md, 'status.json'))) if os.path.exists(os.path.join(md, 'status.json')) else {}
            if ms.get('state') in ('done', 'error'):
                break
            st(stage='Composing the music ' + (ms.get('stage', '').replace('Composing', '').strip() or '…'))
            time.sleep(1)
        st(bgm_ok=ms.get('state') == 'done', bgm_error=ms.get('error', ''))
    if not spec.get('render', True):
        st(state='done', stage='Ready (not rendered)', progress=1, finished=time.time(), seconds=round(time.time() - t0))
        return
    if not env.get('chrome'):
        st(state='done', stage='Ready — no Chrome found, so open the Toon Lab link and press Record', progress=1, finished=time.time(), seconds=round(time.time() - t0))
        return
    # the Toon Lab page itself renders it: headless Chrome opens it in "make" mode, records in real time, uploads the MP4
    done_file = os.path.join(JOB, 'render.json')
    url = f"http://127.0.0.1:{env['port']}/?p=toon&make={os.path.basename(JOB)}"
    prof = os.path.join(JOB, 'chrome')
    chrome = subprocess.Popen([env['chrome'], '--headless=new', '--autoplay-policy=no-user-gesture-required', '--no-first-run', '--no-default-browser-check',
                               '--disable-background-timer-throttling', '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows',
                               '--remote-debugging-port=0', f'--user-data-dir={prof}', '--window-size=1500,1100', url],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    r0, limit = time.time(), dur * 2 + 150
    try:
        while not os.path.exists(done_file):
            el = time.time() - r0
            if el > limit:
                raise RuntimeError('Rendering timed out. Open the Toon Lab link and press Record instead.')
            if chrome.poll() is not None:
                raise RuntimeError('Chrome closed before the video was finished.')
            st(stage=f'Recording the video {min(99, int(100 * el / (dur + 8)))}%', progress=round(0.72 + 0.26 * min(1, el / (dur + 8)), 3))
            time.sleep(1)
    finally:
        try:
            os.killpg(chrome.pid, 15)
        except Exception:
            pass
        time.sleep(1)
        shutil.rmtree(prof, ignore_errors=True)
    r = json.load(open(done_file))
    if r.get('error'):
        raise RuntimeError('Rendering failed: ' + r['error'])
    st(state='done', stage='Done', progress=1, render=r['file'], render_path=os.path.join(env['renders'], r['file']),
       finished=time.time(), seconds=round(time.time() - t0))


def prefetch():
    from demucs.pretrained import get_model
    get_model('htdemucs')
    inference = load_seedvc()
    inference.load_models(argparse.Namespace(f0_condition=True, checkpoint=None, config=None, fp16=True))
    print('models ready')


if __name__ == '__main__':
    if sys.argv[1] == '--prefetch':
        prefetch()
        sys.exit(0)
    mode = sys.argv[1] if sys.argv[1] in ('--toon', '--make') else ''
    JOB = os.path.abspath(sys.argv[-1])
    os.setpgrp()                                      # so "cancel" can kill demucs children too
    STATUS.update(json.load(open(os.path.join(JOB, 'status.json'))) if os.path.exists(os.path.join(JOB, 'status.json')) else {})
    try:
        {'--toon': run_toon, '--make': run_make}.get(mode, run_job)()
    except Exception as e:
        traceback.print_exc()
        st(state='error', stage='Failed', error=str(e)[-600:])
        sys.exit(1)
PY;

// ───────────────────────────── CLI (after every const above is defined) ─────────────────────────────
if (PHP_SAPI === 'cli') {
    $cmd = $argv[1] ?? '';
    if (in_array($cmd, ['options', 'make', 'status', 'skill', 'person'], true)) { cli_agent($cmd, array_slice($argv, 2)); exit; }
    if ($cmd === 'person-build') { person_build($argv[2] ?? ''); exit; }   // background job started by ?a=person_make
    if ($cmd === 'setup') {
        $py = find_python();
        if (!$py) { fwrite(STDERR, "Need Python 3.10–3.12 (brew install python@3.11)\n"); exit(1); }
        write_engine_files();
        passthru('env PATH=' . escapeshellarg(SYS_PATH) . ' bash ' . escapeshellarg(ENGINE . '/install.sh') . ' ' . escapeshellarg($py), $rc);
        exit($rc);
    }
    $url = 'http://127.0.0.1:' . PORT;
    echo "\n  VOICE SWAP running at $url  (Ctrl+C to stop)\n\n";
    if (PHP_OS_FAMILY === 'Darwin') exec('(sleep 1; open ' . escapeshellarg($url) . ') > /dev/null 2>&1 &');
    putenv('PHP_CLI_SERVER_WORKERS=8');   // long requests (the AI Director, scene pictures) mustn't block the page
    passthru(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=1G -d post_max_size=1G -d max_execution_time=0 -d memory_limit=512M'
        . ' -S 127.0.0.1:' . PORT . ' ' . escapeshellarg(__FILE__));
    exit;
}

// ───────────────────────────── API ─────────────────────────────
$a = $_GET['a'] ?? '';
if ($a !== '') {
    ini_set('display_errors', '0'); // PHP warnings must never leak into JSON responses
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && preg_match('#^(multipart/|application/x-www-form-urlencoded)#', $_SERVER['CONTENT_TYPE'] ?? ''))   // JSON bodies (agents) are read from php://input
        json_out(['error' => 'Upload too big for this PHP server (limit ' . ini_get('post_max_size') . '). Stop it and start the app with:  php index.php'], 413);
    foreach ([JOBS, VOICES, TOONS, CHARS, RENDERS, PROJECTS] as $d) @mkdir($d, 0755, true);

    switch ($a) {
        case 'engine':
            $log = @file_get_contents(ENGINE . '/install.log') ?: '';
            $pid = (int)@file_get_contents(ENGINE . '/install.pid');
            json_out([
                'ready' => engine_ready(),
                'installing' => pid_alive($pid),
                'python' => find_python(),
                'ffmpeg' => find_bin('ffmpeg'),
                'git' => find_bin('git'),
                'log' => implode("\n", array_slice(explode("\n", preg_replace('/[^\n]*\r/', '', $log)), -18)),
                'upload_limit' => ini_get('upload_max_filesize'),
                'ram_gb' => round(((int)shell_exec('sysctl -n hw.memsize 2>/dev/null')) / 1073741824),
            ]);

        case 'install':
            $py = find_python();
            if (!$py) json_out(['error' => 'Python 3.10–3.12 not found. Run: brew install python@3.11'], 400);
            if (!find_bin('ffmpeg')) json_out(['error' => 'ffmpeg not found. Run: brew install ffmpeg'], 400);
            if (pid_alive((int)@file_get_contents(ENGINE . '/install.pid'))) json_out(['ok' => true]);
            write_engine_files();
            $cmd = '(cd ' . escapeshellarg(ENGINE) . ' && exec nohup env PATH=' . escapeshellarg(SYS_PATH) . ' bash install.sh '
                . escapeshellarg($py) . ') < /dev/null > ' . escapeshellarg(ENGINE . '/install.log') . ' 2>&1 & echo $!';
            file_put_contents(ENGINE . '/install.pid', trim((string)shell_exec($cmd)));
            json_out(['ok' => true]);

        case 'voices':
            $out = [];
            foreach (glob(VOICES . '/*') as $f) $out[] = ['file' => basename($f), 'name' => pathinfo($f, PATHINFO_FILENAME)];
            json_out($out);

        case 'voice_file':
            $f = VOICES . '/' . basename($_GET['f'] ?? '');
            if (!is_file($f)) json_out(['error' => 'not found'], 404);
            header('Content-Type: ' . (mime_content_type($f) ?: 'audio/mpeg'));
            readfile($f);
            exit;

        case 'delete_voice':
            @unlink(VOICES . '/' . basename($_POST['f'] ?? ''));
            json_out(['ok' => true]);

        case 'create':
            if (!engine_ready()) json_out(['error' => 'Engine not installed yet.'], 400);
            write_engine_files();
            $text = speakable((string)($_POST['text'] ?? ''));
            $aiOnly = $text !== '' && !empty($_POST['ai_voice']);   // text read by the TTS voice itself, no swap
            // your voice: a saved one, or a new upload/recording
            $voice = null;
            if ($aiOnly) {
            } elseif (!empty($_POST['saved_voice'])) {
                $voice = VOICES . '/' . basename($_POST['saved_voice']);
                if (!is_file($voice)) json_out(['error' => 'Saved voice not found.'], 400);
            } elseif (($_FILES['voice']['error'] ?? 4) === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['voice']['name'], PATHINFO_EXTENSION)) ?: 'webm';
                $name = trim($_POST['save_as'] ?? '');
                $voice = $name !== '' ? VOICES . '/' . slug($name) . ".$ext" : JOBS . '/_voice_' . bin2hex(random_bytes(4)) . ".$ext";
                move_uploaded_file($_FILES['voice']['tmp_name'], $voice);
            } else {
                json_out(['error' => 'Add your voice clip (upload, record, or pick a saved voice).'], 400);
            }
            $songs = $_FILES['songs'] ?? null;
            if ($text === '' && (!$songs || !is_array($songs['name']) || !count(array_filter($songs['error'], fn($e) => $e === UPLOAD_ERR_OK))))
                json_out(['error' => isset($_POST['text']) ? 'Type or paste some text to speak (or drop a .txt / .md file).' : 'Add at least one target song/clip. (If it is big, start the app with "php index.php" so large uploads are allowed.)'], 400);

            $mode = ($_POST['mode'] ?? 'sing') === 'speech' ? 'speech' : 'sing';
            $ids = [];
            if ($text !== '') {
                // text → speech (Kokoro) → your voice (Seed-VC speech model)
                $id = new_id();
                $dir = JOBS . "/$id";
                mkdir($dir, 0755, true);
                file_put_contents("$dir/script.txt", $text);
                $title = trim(preg_replace('/\s+/', ' ', mb_substr($text, 0, 48))) . (mb_strlen($text) > 48 ? '…' : '');
                $vcopy = '';
                if ($voice) { $vcopy = "$dir/voice." . pathinfo($voice, PATHINFO_EXTENSION); copy($voice, $vcopy); }
                $job = [
                    'title' => '📝 ' . $title,
                    'out_name' => trim(preg_replace('/[^\w\- ]+/u', '_', mb_substr($text, 0, 32)), ' _') ?: 'speech',
                    'voice_name' => $voice ? pathinfo($voice, PATHINFO_FILENAME) : 'AI voice',
                    'voice_path' => $vcopy, 'song_path' => '', 'mode' => 'speech', 'separate' => false,
                    'clean_voice' => !empty($_POST['clean_voice']), 'auto_octave' => false, 'semitones' => 0,
                    'steps' => max(5, min(80, (int)($_POST['steps'] ?? 30))),
                    'similarity' => max(0, min(1, (float)($_POST['similarity'] ?? 0.7))),
                    'vocal_vol' => 100, 'music_vol' => 100, 'orig_vol' => 0, 'created' => time(),
                    'tts' => ['voice' => tts_voice((string)($_POST['tts_voice'] ?? '')), 'speed' => clampf($_POST['tts_speed'] ?? 1, 0.6, 1.6, 1), 'convert' => !$aiOnly],
                ];
                file_put_contents("$dir/job.json", json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                spawn_worker($dir);
                if ($voice && str_contains($voice, '/_voice_')) @unlink($voice);
                json_out(['ids' => [$id]]);
            }
            foreach ($songs['name'] as $i => $orig) {
                if ($songs['error'][$i] !== UPLOAD_ERR_OK) continue;
                $id = new_id();
                $dir = JOBS . "/$id";
                mkdir($dir, 0755, true);
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'mp3';
                move_uploaded_file($songs['tmp_name'][$i], "$dir/input.$ext");
                $vcopy = "$dir/voice." . pathinfo($voice, PATHINFO_EXTENSION);
                copy($voice, $vcopy);
                $job = [
                    'title' => pathinfo($orig, PATHINFO_FILENAME),
                    'out_name' => preg_replace('/[^\w\- ().]+/u', '_', pathinfo($orig, PATHINFO_FILENAME)),
                    'voice_name' => pathinfo($voice, PATHINFO_FILENAME),
                    'voice_path' => $vcopy,
                    'song_path' => "$dir/input.$ext",
                    'mode' => $mode,
                    'separate' => !empty($_POST['separate']),
                    'clean_voice' => !empty($_POST['clean_voice']),
                    'auto_octave' => !empty($_POST['auto_octave']) && $mode === 'sing',
                    'semitones' => max(-24, min(24, (int)($_POST['semitones'] ?? 0))),
                    'steps' => max(5, min(80, (int)($_POST['steps'] ?? 30))),
                    'similarity' => max(0, min(1, (float)($_POST['similarity'] ?? 0.7))),
                    'vocal_vol' => max(0, min(200, (int)($_POST['vocal_vol'] ?? 100))),
                    'music_vol' => max(0, min(200, (int)($_POST['music_vol'] ?? 100))),
                    'orig_vol' => max(0, min(100, (int)($_POST['orig_vol'] ?? 0))),
                    'created' => time(),
                ];
                file_put_contents("$dir/job.json", json_encode($job, JSON_PRETTY_PRINT));
                spawn_worker($dir);
                $ids[] = $id;
            }
            if (str_contains($voice, '/_voice_')) @unlink($voice);
            json_out(['ids' => $ids]);

        case 'jobs':
            $out = [];
            foreach (array_reverse(glob(JOBS . '/*', GLOB_ONLYDIR)) as $d) if ($j = job_info(basename($d))) $out[] = $j;
            json_out($out);

        case 'file':
            $id = $_GET['id'] ?? '';
            $f = JOBS . "/$id/" . basename($_GET['f'] ?? '');
            if (!valid_id($id) || !is_file($f) || !str_ends_with($f, '.mp3')) json_out(['error' => 'not found'], 404);
            serve_file($f, 'audio/mpeg');

        case 'cancel':
        case 'delete':
            $id = $_POST['id'] ?? '';
            if (!valid_id($id) || !is_dir(JOBS . "/$id")) json_out(['error' => 'bad id'], 400);
            $dir = JOBS . "/$id";
            $pid = (int)@file_get_contents("$dir/pid");
            if (pid_alive($pid)) { posix_kill(-$pid, SIGTERM); posix_kill($pid, SIGTERM); }
            if ($a === 'delete') {
                exec('rm -rf ' . escapeshellarg($dir));
            } else {
                $st = read_json("$dir/status.json");
                file_put_contents("$dir/status.json", json_encode(['state' => 'cancelled', 'stage' => 'Cancelled', 'progress' => 0] + $st));
            }
            json_out(['ok' => true]);

        case 'reveal':
            $id = $_POST['id'] ?? '';
            $render = RENDERS . '/' . basename($_POST['render'] ?? '');
            if (PHP_OS_FAMILY !== 'Darwin') json_out(['ok' => true]);
            if (valid_id($id) && is_dir(JOBS . "/$id")) exec('open ' . escapeshellarg(JOBS . "/$id"));
            elseif (is_file($render)) exec('open -R ' . escapeshellarg($render));
            json_out(['ok' => true]);

        // ── Toon Lab ──
        case 'toon_analyze':
            if (!engine_ready()) json_out(['error' => 'Precise mode needs the engine — install it on the Voice Swap page, or use Instant mode.'], 400);
            write_engine_files();
            foreach (glob(TOONS . '/*', GLOB_ONLYDIR) as $old)        // analyses are throwaway; keep a few days
                if (filemtime($old) < time() - 3 * 86400) exec('rm -rf ' . escapeshellarg($old));
            $id = new_id();
            $dir = TOONS . "/$id";
            $src = $_POST['job'] ?? '';
            $wordsPath = null;
            if ($src !== '') {
                // a finished Voice Swap job: its converted vocals are already clean, no isolation needed
                $f = $src && valid_id($src) ? (read_json(JOBS . "/$src/status.json")['files']['vocals'] ?? '') : '';
                if (!$f || !is_file(JOBS . "/$src/$f")) json_out(['error' => 'That Voice Swap result has no vocals file.'], 400);
                mkdir($dir, 0755, true);
                $audio = JOBS . "/$src/$f";
                $isolate = false;
                if (is_file(JOBS . "/$src/words.json")) $wordsPath = JOBS . "/$src/words.json";   // spoken from text: the words are known exactly
            } elseif (($_FILES['audio']['error'] ?? 4) === UPLOAD_ERR_OK) {
                mkdir($dir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION)) ?: 'mp3';
                $audio = "$dir/input." . preg_replace('/[^a-z0-9]/', '', $ext);
                move_uploaded_file($_FILES['audio']['tmp_name'], $audio);
                $isolate = !empty($_POST['isolate']);
            } else {
                json_out(['error' => 'Add an audio clip first.'], 400);
            }
            file_put_contents("$dir/job.json", json_encode(['audio_path' => $audio, 'isolate' => $isolate, 'words' => !empty($_POST['words']), 'words_path' => $wordsPath, 'created' => time()]));
            spawn_worker($dir, '--toon');
            json_out(['id' => $id]);

        case 'toon_status':
            $id = $_GET['id'] ?? '';
            $dir = TOONS . "/$id";
            if (!valid_id($id) || !is_dir($dir)) json_out(['error' => 'not found'], 404);
            $st = read_json("$dir/status.json") + ['state' => 'queued', 'stage' => 'Starting…', 'progress' => 0];
            if (in_array($st['state'], ['queued', 'running'], true) && !pid_alive((int)@file_get_contents("$dir/pid")) && filemtime($dir) < time() - 5)
                $st = ['state' => 'error', 'error' => $st['error'] ?? 'Analysis stopped unexpectedly.'] + $st;
            if ($st['state'] === 'done') $st['result'] = read_json("$dir/result.json");
            if ($st['state'] === 'error') $st['log'] = substr(preg_replace('/[^\n]*\r/', '', @file_get_contents("$dir/log.txt") ?: ''), -3000);
            json_out($st);

        case 'chars':
            $out = [];
            foreach (glob(CHARS . '/*.json') as $f) if ($c = read_json($f)) $out[] = ['file' => basename($f)] + $c;
            json_out($out);

        case 'save_char':
            $raw = (string)($_POST['data'] ?? '');
            $c = json_decode($raw, true);
            if (!is_array($c) || strlen($raw) > 20000) json_out(['error' => 'Bad character data.'], 400);
            $c['name'] = trim((string)($c['name'] ?? '')) ?: 'Toon';
            $file = slug($c['name']) . '.json';
            file_put_contents(CHARS . "/$file", json_encode($c, JSON_PRETTY_PRINT));
            json_out(['file' => $file]);

        case 'delete_char':
            @unlink(CHARS . '/' . basename($_POST['f'] ?? ''));
            json_out(['ok' => true]);

        case 'toon_mp4':
            // browser recordings are WebM or variable-frame-rate MP4; re-encode to a file every editor/phone accepts
            $ue = $_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($ue !== UPLOAD_ERR_OK) {
                $free = @disk_free_space(sys_get_temp_dir());
                json_out(['error' => match (true) {
                    $free !== false && $free < 2e9 => 'The video couldn\'t be saved: your disk is almost full (' . round($free / 1e6) . ' MB free). Free some space, then hit ↻ Try again.',
                    in_array($ue, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) => 'The recording is bigger than this server allows (' . ini_get('upload_max_filesize') . '). Start the app with  php index.php  and hit ↻ Try again.',
                    $ue === UPLOAD_ERR_CANT_WRITE, $ue === UPLOAD_ERR_NO_TMP_DIR => 'The server couldn\'t write the upload to disk (is the disk full?). Free some space, then hit ↻ Try again.',
                    $ue === UPLOAD_ERR_PARTIAL => 'The upload was cut off. Hit ↻ Try again.',
                    default => "No video received (upload error $ue). Hit ↻ Try again.",
                }], 400);
            }
            $ffmpeg = find_bin('ffmpeg');
            if (!$ffmpeg) json_out(['error' => 'ffmpeg not found. Run: brew install ffmpeg'], 400);
            $name = trim(preg_replace('/[^\w\- ]+/u', '_', (string)($_POST['name'] ?? '')), ' _') ?: 'toon';
            $base = RENDERS . "/$name " . date('Y-m-d His');
            move_uploaded_file($_FILES['video']['tmp_name'], "$base.src");
            exec(escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg("$base.src")
                . ' -c:v libx264 -preset veryfast -crf 18 -pix_fmt yuv420p -r 30 -c:a aac -b:a 192k -movflags +faststart '
                . escapeshellarg("$base.mp4") . ' 2>&1', $o, $rc);
            @unlink("$base.src");
            $mk = (string)($_POST['make'] ?? '');
            $mkDone = fn($r) => valid_id($mk) && is_dir(PROJECTS . "/$mk") ? file_put_contents(PROJECTS . "/$mk/render.json", json_encode($r)) : null;
            if ($rc) { $mkDone(['error' => 'MP4 conversion failed']); json_out(['error' => 'MP4 conversion failed: ' . substr(implode("\n", $o), -400)], 500); }
            // gallery info: what was made, plus a poster frame
            file_put_contents("$base.json", json_encode(['title' => (string)($_POST['title'] ?? $name), 'style' => (string)($_POST['style'] ?? ''), 'character' => $name]));
            render_meta(basename("$base.mp4"));
            $mkDone(['file' => basename("$base.mp4")]);
            json_out(['file' => basename("$base.mp4")]);

        case 'render':
            $f = RENDERS . '/' . basename($_GET['f'] ?? '');
            if (!is_file($f) || !str_ends_with($f, '.mp4')) json_out(['error' => 'not found'], 404);
            serve_file($f, 'video/mp4');

        case 'renders':
            $out = [];
            foreach (glob(RENDERS . '/*.mp4') as $f) if ($m = render_meta(basename($f))) $out[] = $m;
            usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
            json_out($out);

        case 'render_thumb':
            $f = RENDERS . '/' . basename($_GET['f'] ?? '', '.mp4') . '.jpg';
            if (!is_file($f)) json_out(['error' => 'not found'], 404);
            header('Cache-Control: max-age=86400');
            serve_file($f, 'image/jpeg');

        case 'delete_render':
            $f = RENDERS . '/' . basename((string)($_POST['f'] ?? ''));
            if (!str_ends_with($f, '.mp4') || !is_file($f)) json_out(['error' => 'not found'], 404);
            $b = substr($f, 0, -4);
            foreach (["$b.mp4", "$b.jpg", "$b.json"] as $x) @unlink($x);
            json_out(['ok' => true]);

        // ── agents + Director ──
        case 'avatars_fetch':   // first use of Realistic 3D: download the two ready-made people (~40 MB)
            @mkdir(AVATARS, 0755, true);
            foreach (array_keys(AVATAR_DEFAULTS) as $k) {
                if (is_file(AVATARS . "/$k.glb")) continue;
                exec('curl -sfL --retry 2 -o ' . escapeshellarg(AVATARS . "/$k.glb.part") . ' ' . escapeshellarg(AVATAR_SRC . "$k.glb"), $o, $rc);
                if ($rc !== 0) json_out(['error' => "Couldn't download the 3D person $k.glb (no internet?)."], 502);
                rename(AVATARS . "/$k.glb.part", AVATARS . "/$k.glb");
            }
            json_out(['ok' => true]);
        case 'people':   // 3D people + the Blender builder's options
            json_out(['avatars' => toon_avatars(true), 'schema' => toon_avatars(), 'blender' => find_blender() !== null, 'options' => PERSON_OPTS, 'defaults' => PERSON_DEFAULT]);
        case 'person_make':
            $in = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($in)) json_out(['error' => 'Send the person as a JSON body.'], 400);
            if (!find_blender()) json_out(['error' => 'Blender not found. Install it from blender.org (free) into /Applications.'], 400);
            $id = new_id(); $dir = AVATARS . "/.builds/$id";
            @mkdir($dir, 0755, true);
            file_put_contents("$dir/spec.json", json_encode(person_normalize($in)));
            file_put_contents("$dir/status.json", json_encode(['state' => 'running', 'stage' => 'Starting Blender', 'progress' => 0]));
            exec('(exec nohup env PATH=' . escapeshellarg(SYS_PATH) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' person-build ' . escapeshellarg($dir)
                . ') < /dev/null > ' . escapeshellarg("$dir/run.txt") . ' 2>&1 &');
            json_out(['id' => $id]);
        case 'person_status':
            $id = $_GET['id'] ?? '';
            if (!valid_id($id) || !is_dir(AVATARS . "/.builds/$id")) json_out(['error' => 'not found'], 404);
            json_out(read_json(AVATARS . "/.builds/$id/status.json"));
        case 'person_delete':   // only people made here; the downloaded defaults stay
            $k = (string)($_POST['id'] ?? '');
            if (!preg_match('/^[\w.-]+$/', $k) || isset(AVATAR_DEFAULTS[$k]) || empty(avatar_meta($k)['made'])) json_out(['error' => 'Only people built here can be deleted.'], 400);
            foreach (["$k.glb", "$k.json"] as $f) if (is_file(AVATARS . "/$f") && !@unlink(AVATARS . "/$f"))
                json_out(['error' => "Couldn't delete avatars/$f (" . (error_get_last()['message'] ?? 'permission denied') . ').'], 500);
            json_out(['ok' => true, 'deleted' => $k]);
        case 'scenes':
            json_out(media_list(SCENES, '/\.(jpe?g|png|webp)$/i'));
        case 'scene_upload':
            $f = $_FILES['image'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'No picture received.'], 400);
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); $ext = $ext === 'jpeg' ? 'jpg' : $ext;
            if (!in_array($ext, ['jpg', 'png', 'webp'], true) || !@getimagesize($f['tmp_name'])) json_out(['error' => 'Use a JPG, PNG or WebP picture.'], 400);
            @mkdir(SCENES, 0755, true);
            $title = pathinfo($f['name'], PATHINFO_FILENAME); $file = media_name($title, $ext, SCENES);
            move_uploaded_file($f['tmp_name'], SCENES . "/$file");
            file_put_contents(SCENES . '/' . preg_replace('/\.\w+$/', '.json', $file), json_encode(['title' => mb_substr($title, 0, 60), 'source' => 'uploaded'], JSON_UNESCAPED_UNICODE));
            json_out(['file' => $file, 'title' => $title]);
        case 'scene_gen':
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            if ($prompt === '') json_out(['error' => 'Describe the scene first.'], 400);
            if (!engine_ready() && ($_POST['provider'] ?? '') !== 'free') json_out(['error' => 'Install the engine first (Voice Swap page), or pick the free no-account service.'], 400);
            try { json_out(scene_generate($prompt, (string)($_POST['aspect'] ?? '16:9'), (string)($_POST['style'] ?? ''), (string)($_POST['provider'] ?? 'hf'))); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
        case 'music':
            json_out(['tracks' => media_list(MUSIC, '/\.(mp3|wav|m4a|ogg|flac)$/i'), 'musicgen' => musicgen_ready(), 'free_gb' => round(disk_free_space(BASE) / 1e9, 1)]);
        case 'music_upload':
            $f = $_FILES['audio'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'No audio received.'], 400);
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp3', 'wav', 'm4a', 'ogg', 'flac'], true)) json_out(['error' => 'Use an MP3, WAV, M4A, OGG or FLAC file.'], 400);
            @mkdir(MUSIC, 0755, true);
            $title = pathinfo($f['name'], PATHINFO_FILENAME); $file = media_name($title, $ext, MUSIC);
            move_uploaded_file($f['tmp_name'], MUSIC . "/$file");
            file_put_contents(MUSIC . '/' . preg_replace('/\.\w+$/', '.json', $file), json_encode(['title' => mb_substr($title, 0, 60), 'source' => 'uploaded'], JSON_UNESCAPED_UNICODE));
            json_out(['file' => $file, 'title' => $title]);
        case 'music_gen':
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            if ($prompt === '') json_out(['error' => 'Describe the music first.'], 400);
            if (!engine_ready()) json_out(['error' => 'Install the engine first (Voice Swap page).'], 400);
            try { json_out(music_start($prompt, clampf($_POST['seconds'] ?? 20, 4, 30, 20))); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 400); }
        case 'music_status':
            $id = (string)($_GET['id'] ?? ''); $d = MUSIC . "/.jobs/$id";
            if (!valid_id($id) || !is_dir($d)) json_out(['error' => 'not found'], 404);
            $st = read_json("$d/status.json") + ['state' => 'queued', 'stage' => 'Starting…', 'progress' => 0];
            if (in_array($st['state'], ['queued', 'running'], true) && !pid_alive((int)@file_get_contents("$d/pid")) && filemtime($d) < time() - 5)
                $st = ['state' => 'error', 'error' => substr((string)@file_get_contents("$d/log.txt"), -500) ?: 'Stopped unexpectedly.'] + $st;
            if ($st['state'] === 'error') { $j = read_json("$d/job.json"); @unlink(preg_replace('/\.mp3$/', '.json', $j['out'] ?? '')); }
            json_out($st);
        case 'media_delete':   // a scene or a music track
            $kind = $_POST['kind'] ?? ''; $f = basename((string)($_POST['file'] ?? ''));
            $dir = ['scene' => SCENES, 'music' => MUSIC][$kind] ?? null;
            if (!$dir || !preg_match('/^[\w.-]+\.(jpe?g|png|webp|mp3|wav|m4a|ogg|flac)$/i', $f) || !is_file("$dir/$f")) json_out(['error' => 'not found'], 404);
            if (!@unlink("$dir/$f")) json_out(['error' => "Couldn't delete $f."], 500);
            @unlink("$dir/" . preg_replace('/\.\w+$/', '.json', $f));
            json_out(['ok' => true]);
        case 'news':
            $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
            if ($q === '') json_out(['error' => 'Type a topic to search the news for.'], 400);
            if (!engine_ready()) json_out(['error' => 'Install the engine first (Voice Swap page).'], 400);
            try { json_out(['stories' => news_search($q)]); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
        case 'games':
            json_out(game_list());
        case 'game_get':
            $id = (string)($_GET['id'] ?? '');
            if (!valid_id($id) || !is_file(GAMES . "/$id/game.json")) json_out(['error' => 'not found'], 404);
            json_out(read_json(GAMES . "/$id/game.json"));
        case 'game_save':   // the browser builds the whole cartridge (sprites, music and voices embedded); the server just keeps it
            $spec = json_decode((string)($_POST['spec'] ?? ''), true); $html = (string)($_POST['html'] ?? '');
            if (!is_array($spec) || $html === '' || strlen($html) > 60e6) json_out(['error' => 'Bad game data.'], 400);
            $id = (string)($_POST['id'] ?? '');
            if (!valid_id($id) || !is_dir(GAMES . "/$id")) $id = new_id();
            @mkdir(GAMES . "/$id", 0755, true);
            file_put_contents(GAMES . "/$id/game.json", json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents(GAMES . "/$id/index.html", $html);
            if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)($_POST['thumb'] ?? ''), $m)) file_put_contents(GAMES . "/$id/thumb.jpg", base64_decode($m[1]));
            json_out(['id' => $id]);
        case 'game_delete':
            $id = (string)($_POST['id'] ?? '');
            if (!valid_id($id) || !is_dir(GAMES . "/$id")) json_out(['error' => 'not found'], 404);
            exec('rm -rf ' . escapeshellarg(GAMES . "/$id"));
            json_out(['ok' => true]);
        case 'game_reveal':
            $id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
            if (!valid_id($id) || !is_file(GAMES . "/$id/index.html")) json_out(['error' => 'not found'], 404);
            if (PHP_OS_FAMILY === 'Darwin') exec('open -R ' . escapeshellarg(GAMES . "/$id/index.html"));
            json_out(['ok' => true, 'path' => GAMES . "/$id/index.html"]);
        case 'game_director':
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            if ($prompt === '') json_out(['error' => 'Describe the game you want first.'], 400);
            try { json_out(['plan' => game_director($prompt, trim((string)($_POST['model'] ?? '')), ['bgs' => array_column(toon_schema()[array_search('bg', array_column(toon_schema(), 0))][2], 0)])]); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
        case 'buddies':
            json_out(buddy_list());
        case 'buddy_save':
            $b = json_decode((string)($_POST['data'] ?? ''), true);
            if (!is_array($b) || strlen((string)$_POST['data']) > 400000) json_out(['error' => 'Bad buddy data.'], 400);
            @mkdir(BUDDIES, 0755, true);
            $id = (string)($b['id'] ?? ''); if (!valid_id($id)) $id = new_id();
            unset($b['id']);
            $b = ['name' => mb_substr(trim((string)($b['name'] ?? 'Buddy')), 0, 40) ?: 'Buddy', 'prompt' => mb_substr((string)($b['prompt'] ?? ''), 0, 300), 'seed' => (int)($b['seed'] ?? 0),
                'sheet' => is_array($b['sheet'] ?? null) ? $b['sheet'] : null, 'thumb' => preg_match('#^data:image/(webp|png|jpeg);base64,[A-Za-z0-9+/=]+$#', (string)($b['thumb'] ?? '')) ? $b['thumb'] : '', 'time' => time()];
            file_put_contents(BUDDIES . "/$id.json", json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            json_out(['id' => $id] + $b);
        case 'buddy_delete':
            $id = (string)($_POST['id'] ?? '');
            if (!valid_id($id) || !is_file(BUDDIES . "/$id.json")) json_out(['error' => 'not found'], 404);
            @unlink(BUDDIES . "/$id.json"); json_out(['ok' => true]);
        case 'buddy_ai':
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            if ($prompt === '') json_out(['error' => 'Describe the buddy first.'], 400);
            try { json_out(['sheet' => buddy_ai($prompt, trim((string)($_POST['model'] ?? '')))]); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
        case 'options':
            json_out(toon_options());

        case 'settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $cur = settings();
                $new = ['provider' => ($_POST['provider'] ?? $cur['provider']) === 'openai' ? 'openai' : 'ollama',
                    'llm_url' => trim((string)($_POST['llm_url'] ?? $cur['llm_url'])), 'llm_model' => trim((string)($_POST['llm_model'] ?? $cur['llm_model']))];
                if (!preg_match('#^https?://#', $new['llm_url'])) json_out(['error' => 'The model server address should start with http:// (e.g. http://127.0.0.1:11434)'], 400);
                file_put_contents(SETTINGS, json_encode($new, JSON_PRETTY_PRINT));
            }
            json_out(settings());

        case 'llm_models':
            try { json_out(['models' => llm_models()] + settings()); }
            catch (Throwable $e) { json_out(['error' => $e->getMessage(), 'models' => []] + settings(), 502); }

        case 'director':
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            if ($prompt === '') json_out(['error' => 'Describe the video you want first.'], 400);
            $story = json_decode((string)($_POST['story'] ?? ''), true);
            try {
                $sp = director($prompt, trim((string)($_POST['model'] ?? '')), is_array($story) ? news_story($story) : null);
                json_out(['spec' => make_spec($sp) + ['sources' => $sp['sources'] ?? []]]);
            }
            catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }

        case 'make':
            if (!engine_ready()) json_out(['error' => 'Engine not installed yet — open the app and click Install engine.'], 400);
            write_engine_files();
            $in = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($in)) $in = json_decode((string)($_POST['spec'] ?? ''), true);
            if (!is_array($in)) json_out(['error' => 'Send the spec as a JSON body (see: php index.php options).'], 400);
            if (!empty($in['prompt']) && empty($in['script']) && empty($in['audio'])) {
                try {
                    $story = null;
                    if (!empty($in['news'])) {   // true = search the prompt itself, or a string = what to search for
                        $found = news_search(is_string($in['news']) ? $in['news'] : (string)$in['prompt']);
                        if (!$found) throw new RuntimeException('DuckDuckGo found no news for that.');
                        $story = news_story($found[0]);
                    }
                    $in = array_merge(director((string)$in['prompt'], '', $story), array_diff_key($in, ['prompt' => 1, 'news' => 1]));
                }
                catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
            }
            try {
                if (!empty($in['scene'])) {   // "scene": "a rainy neon street" | {"prompt": …} | {"file": "/path/to/picture.jpg"}
                    $sc = is_array($in['scene']) ? $in['scene'] : ['prompt' => (string)$in['scene']];
                    $style = (string)($in['style'] ?? $in['character']['style'] ?? (TOON_PRESETS[$in['preset'] ?? '']['style'] ?? ''));
                    $file = !empty($sc['file']) ? media_import((string)$sc['file'], SCENES, ['jpg', 'jpeg', 'png', 'webp'], (string)($sc['title'] ?? ''))
                        : scene_generate((string)($sc['prompt'] ?? ''), (string)($in['aspect'] ?? '16:9'), $style, (string)($sc['provider'] ?? 'hf'))['file'];
                    if (!$file) throw new RuntimeException('Scene picture not found: ' . ($sc['file'] ?? ''));
                    $in['character'] = (is_array($in['character'] ?? null) ? $in['character'] : []) + ['bg' => 'image', 'bgImage' => $file];
                    $in['character']['bg'] = 'image'; $in['character']['bgImage'] = $file;
                }
                if (!empty($in['bgm'])) {   // "bgm": "chill lofi beat" | {"prompt": …, "volume": 0.25} | {"file": "/path/to/track.mp3"}
                    $b = is_array($in['bgm']) ? $in['bgm'] : ['prompt' => (string)$in['bgm']];
                    if (!empty($b['file']) && str_contains((string)$b['file'], '/')) {
                        $b['file'] = media_import((string)$b['file'], MUSIC, ['mp3', 'wav', 'm4a', 'ogg', 'flac'], (string)($b['title'] ?? ''));
                        if (!$b['file']) throw new RuntimeException('Music file not found.');
                    } elseif (empty($b['file']) && !empty($b['prompt'])) { $m = music_start((string)$b['prompt'], clampf($b['seconds'] ?? 30, 4, 30, 30)); $b = ['job' => $m['id'], 'file' => $m['file']] + $b; }   // composes while the voice is made
                    $in['bgm'] = $b;
                }
            } catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
            $spec = make_spec($in);
            if ($spec['script'] === '' && $spec['audio'] === '') json_out(['error' => 'Give it a "script" (text to speak) or an "audio" file path.' . (!empty($in['audio']) ? ' Audio file not found: ' . $in['audio'] : '')], 400);
            if ($spec['voice_missing'] !== '') json_out(['error' => "No saved voice called \"{$spec['voice_missing']}\". Saved voices: " . (implode(', ', toon_options()['saved_voices']) ?: 'none yet — record one on the Voice Swap page, or leave "voice" empty for the AI voice')], 400);
            $id = new_id();
            $dir = PROJECTS . "/$id";
            mkdir($dir, 0755, true);
            $spec['_env'] = ['port' => (int)($_SERVER['SERVER_PORT'] ?? PORT), 'chrome' => find_chrome(), 'jobs' => JOBS, 'toons' => TOONS, 'renders' => RENDERS, 'music' => MUSIC,
                'voice_path' => $spec['voice'] !== '' ? voice_file($spec['voice']) : ''];
            file_put_contents("$dir/spec.json", json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            spawn_worker($dir, '--make');
            json_out(['id' => $id, 'spec' => array_diff_key($spec, ['_env' => 1]), 'renderer' => $spec['_env']['chrome'] ? 'headless chrome' : null]);

        case 'make_status':
            $id = $_GET['id'] ?? '';
            $dir = PROJECTS . "/$id";
            if (!valid_id($id) || !is_dir($dir)) json_out(['error' => 'not found'], 404);
            $st = read_json("$dir/status.json") + ['state' => 'queued', 'stage' => 'Starting…', 'progress' => 0];
            if (in_array($st['state'], ['queued', 'running'], true) && !pid_alive((int)@file_get_contents("$dir/pid")) && filemtime($dir) < time() - 5)
                $st = ['state' => 'error', 'error' => $st['error'] ?? 'Stopped unexpectedly.'] + $st;
            if ($st['state'] === 'error') $st['log'] = substr(preg_replace('/[^\n]*\r/', '', @file_get_contents("$dir/log.txt") ?: ''), -2000);
            $st['id'] = $id;
            $st['title'] = read_json("$dir/spec.json")['title'] ?? '';
            $st['toon_lab'] = 'http://127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? PORT) . "/?p=toon&make=$id&manual=1";
            json_out($st);

        case 'make_get':
            // what the Toon Lab page needs to rebuild a project: spec + the prepared audio + mouth timeline
            $id = $_GET['id'] ?? '';
            $dir = PROJECTS . "/$id";
            if (!valid_id($id) || !is_dir($dir)) json_out(['error' => 'not found'], 404);
            $spec = read_json("$dir/spec.json"); unset($spec['_env']);
            $st = read_json("$dir/status.json");
            $result = !empty($st['toon']) && valid_id($st['toon']) ? read_json(TOONS . "/{$st['toon']}/result.json") : null;
            json_out(['spec' => $spec, 'job' => $st['job'] ?? '', 'result' => $result]);

        case 'make_audio':
            $id = $_GET['id'] ?? '';
            $spec = valid_id($id) ? read_json(PROJECTS . "/$id/spec.json") : [];
            if (empty($spec['audio']) || !is_file($spec['audio'])) json_out(['error' => 'not found'], 404);
            serve_file($spec['audio'], mime_content_type($spec['audio']) ?: 'audio/mpeg');

        case 'make_report':
            $id = $_POST['id'] ?? '';
            if (valid_id($id) && is_dir(PROJECTS . "/$id")) file_put_contents(PROJECTS . "/$id/render.json", json_encode(['error' => mb_substr((string)($_POST['error'] ?? 'unknown'), 0, 500)]));
            json_out(['ok' => true]);
    }
    json_out(['error' => 'unknown action'], 404);
}
if (PHP_SAPI === 'cli-server') {   // the 3D renderer's library and people are plain files
    $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $types = ['js' => 'text/javascript', 'glb' => 'model/gltf-binary', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
        'mjs' => 'text/javascript', 'wasm' => 'application/wasm', 'task' => 'application/octet-stream', 'html' => 'text/html; charset=utf-8'];
    if (preg_match('#^/(vendor/three|vendor/mediapipe|avatars|scenes|music|games)/#', $path) && (str_contains($path, '..') || !is_file(BASE . $path) || !isset($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))]))) {
        http_response_code(404); header('Content-Type: text/plain'); exit('not found');
    }
    if (preg_match('#^/(vendor/three|vendor/mediapipe|avatars|scenes|music|games)/[\w./-]+$#', $path)) {
        header('Content-Type: ' . $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
        header('Cache-Control: max-age=3600');
        if (isset($_GET['dl']) && str_ends_with($path, '.html')) header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '-', basename($_GET['dl']) ?: 'game') . '.html"');
        header('Content-Length: ' . filesize(BASE . $path));
        readfile(BASE . $path); exit;
    }
}
$page = in_array($_GET['p'] ?? '', ['toon', 'game'], true) ? $_GET['p'] : 'swap';
?><!doctype html>
<html lang="en" data-theme="gold">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= ['swap' => 'Voice Swap', 'toon' => 'Toon Lab', 'game' => 'Game Creator'][$page] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=JetBrains+Mono:wght@400;600&family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php if ($page === 'toon' || $page === 'game'): ?>
<script type="importmap">{"imports": {"three": "./vendor/three/three.module.js", "three/addons/": "./vendor/three/addons/"}}</script>
<link href="https://fonts.googleapis.com/css2?family=Bangers&family=Luckiest+Guy&family=Permanent+Marker&family=Bebas+Neue&family=Anton&family=Fredoka:wght@600&family=Comic+Neue:wght@700&family=Press+Start+2P&family=Pacifico&family=Creepster&family=Roboto:wght@900&display=swap" rel="stylesheet">
<?php endif; ?>
<style>
:root[data-theme="gold"]  { --bg:#c9a83c; --panel:#d8b94e; --ink:#2a2005; --muted:#6b5617; --edge:#8a6d14; --hi:#f5dc77; --accent:#fff1a8; --bar:#ffe25c; --well:#bf9d33; }
:root[data-theme="iced"]  { --bg:#0d1b2a; --panel:#13263b; --ink:#e8f6ff; --muted:#86a9c4; --edge:#3d6b8f; --hi:#7fe3ff; --accent:#bff4ff; --bar:#7fe3ff; --well:#0a1622; }
:root[data-theme="dark"]  { --bg:#121212; --panel:#1c1c1c; --ink:#e6e6e6; --muted:#8c8c8c; --edge:#3a3a3a; --hi:#ff5c39; --accent:#ffb199; --bar:#ff5c39; --well:#0d0d0d; }
:root[data-theme="classic"]{ --bg:#d4d0c8; --panel:#e4e1da; --ink:#111; --muted:#555; --edge:#808080; --hi:#0a246a; --accent:#ffffff; --bar:#0a246a; --well:#c8c4bb; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font:14px/1.45 "JetBrains Mono", ui-monospace, monospace; min-height:100vh; }
:root[data-theme="iced"] body { background:radial-gradient(circle at 20% 0%, #1e3a5a, var(--bg) 60%); }
header { display:flex; align-items:center; gap:16px; padding:12px 20px; border-bottom:2px solid var(--edge); background:var(--panel); }
h1 { font:24px "Archivo Black", sans-serif; margin:0; letter-spacing:.5px; text-transform:uppercase; }
h1 span { color:var(--hi); -webkit-text-stroke:1px var(--ink); }
:root[data-theme="iced"] h1 span, :root[data-theme="dark"] h1 span { -webkit-text-stroke:0; }
.tag { color:var(--muted); font-size:12px; }
.themes { margin-left:auto; display:flex; gap:4px; flex-wrap:wrap; }
.themes button { padding:4px 8px; font-size:12px; }
.themes button.on { background:var(--hi); color:var(--bg); }
main { display:grid; grid-template-columns:minmax(0, 440px) minmax(0,1fr); gap:20px; padding:20px; max-width:1300px; margin:0 auto; }
@media (max-width:860px){ main { grid-template-columns:1fr; padding:16px; } header{flex-wrap:wrap;} }
.panel { background:var(--panel); border:2px solid var(--edge); box-shadow:inset 1px 1px 0 var(--accent), 4px 4px 0 rgba(0,0,0,.18); padding:16px; }
.panel h2 { font:15px "Archivo Black", sans-serif; text-transform:uppercase; margin:0 0 10px; display:flex; align-items:center; gap:8px; }
.step { display:inline-grid; place-items:center; width:22px; height:22px; background:var(--ink); color:var(--panel); font-size:12px; }
.sec + .sec { margin-top:18px; padding-top:16px; border-top:1px dashed var(--edge); }
button, select, input[type=text], input[type=number] { font:inherit; color:var(--ink); background:var(--panel); border:2px solid var(--edge); box-shadow:inset 1px 1px 0 var(--accent); padding:7px 12px; }
button { cursor:pointer; }
button:hover:not(:disabled) { background:var(--hi); color:var(--bg); }
button:disabled { opacity:.45; cursor:default; }
button.big { width:100%; padding:14px; font:18px "Archivo Black", sans-serif; text-transform:uppercase; background:var(--hi); color:var(--bg); }
button.big:hover:not(:disabled) { filter:brightness(1.08); }
button.rec { color:#c0152a; }
button.rec.on { background:#c0152a; color:#fff; animation:pulse 1s infinite; }
@keyframes pulse { 50% { opacity:.6 } }
select, input[type=text] { width:100%; }
textarea { width:100%; font:inherit; color:var(--ink); background:var(--well); border:2px solid var(--edge); padding:8px 10px; margin-top:8px; resize:vertical; }
textarea.over { background:var(--hi); color:var(--bg); }
.lbl { color:var(--muted); font-size:12px; }
.drop { border:2px dashed var(--edge); background:var(--well); padding:18px 12px; text-align:center; cursor:pointer; transition:.15s; color:var(--ink); }
.drop.over { background:var(--hi); color:var(--bg); }
.drop small { display:block; color:var(--muted); margin-top:4px; }
.drop.has { border-style:solid; }
.row { display:flex; gap:8px; align-items:center; margin-top:8px; }
.row > * { flex:1; }
.row > .shrink { flex:0 0 auto; }
.field { display:grid; grid-template-columns:120px 1fr 52px; align-items:center; gap:8px; margin:8px 0; }
.field label { color:var(--muted); }
.field output { text-align:right; font-weight:600; }
input[type=range] { width:100%; accent-color:var(--hi); }
.check { display:flex; gap:8px; align-items:flex-start; margin:8px 0; cursor:pointer; }
.check input { margin-top:3px; accent-color:var(--hi); }
.check small { display:block; color:var(--muted); }
.seg { display:grid; grid-template-columns:1fr 1fr; border:2px solid var(--edge); }
.seg label { padding:8px; text-align:center; cursor:pointer; }
.seg:has(> label:nth-child(3)) { grid-template-columns:repeat(3, 1fr); } .seg:has(> label:nth-child(3)) label { padding:8px 4px; white-space:nowrap; }
.seg input { display:none; }
.seg label:has(input:checked) { background:var(--ink); color:var(--panel); }
details summary { cursor:pointer; padding:6px 0; font-weight:600; }
audio { width:100%; height:36px; margin-top:6px; }
.hint { color:var(--muted); font-size:12px; margin-top:6px; }
.err { background:#b3261e; color:#fff; padding:10px 12px; margin-top:10px; white-space:pre-wrap; }
.banner { grid-column:1/-1; }
pre.log { background:var(--well); border:1px solid var(--edge); padding:10px; max-height:260px; overflow:auto; font-size:11.5px; white-space:pre-wrap; margin:10px 0 0; color:var(--ink); }
.queue-head { display:flex; align-items:center; gap:8px; }
.queue-head h2 { margin:0; flex:1; }
.jobs { display:flex; flex-direction:column; gap:12px; margin-top:12px; }
.vgrid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; margin-top:4px; align-items:start; }
.vgrid > .empty { grid-column:1 / -1; }
@media (max-width:1180px) { .vgrid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
@media (max-width:560px) { .vgrid { grid-template-columns:1fr; } }
.vcard { border:2px solid var(--edge); background:var(--well); display:flex; flex-direction:column; min-width:0; }
.vc-art { position:relative; height:64px; background:var(--panel); border-bottom:2px solid var(--edge); overflow:hidden; }
.vc-wave { position:absolute; inset:10px 34px 10px 12px; display:flex; align-items:center; gap:2px; }
.vc-wave i { flex:1; background:var(--hi); opacity:.55; min-height:2px; }
.vcard.done .vc-wave i { opacity:.9; }
.vcard.error .vc-wave i, .vcard.cancelled .vc-wave i { background:var(--muted); opacity:.35; }
.vc-kind { position:absolute; right:9px; top:50%; transform:translateY(-50%); font-size:14px; opacity:.85; }
.vc-art .state { position:absolute; left:6px; top:6px; background:var(--panel); }
.vc-body { padding:8px 10px 10px; display:flex; flex-direction:column; gap:2px; min-width:0; }
.vc-t { font-weight:600; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; line-height:1.3; min-height:2.6em; }
.vcard .meta { color:var(--muted); font-size:11.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.vcard audio { height:30px; margin-top:6px; }
.vc-dl, .vc-acts { display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; }
.vc-dl a, .vc-acts a, .vc-acts button { color:var(--ink); border:2px solid var(--edge); padding:2px 7px; font-size:11.5px; text-decoration:none; background:var(--panel); }
.vc-dl a:hover, .vc-acts a:hover { background:var(--hi); color:var(--bg); }
.vc-acts { border-top:1px dashed var(--edge); padding-top:7px; margin-top:8px; }
.vc-end { margin-left:auto; display:flex; gap:4px; }
.vc-acts a, .vc-acts button { white-space:nowrap; }
.vc-err summary { font-size:12px; color:#e5484d; }
.vc-err .log { max-height:160px; }
.job { border:2px solid var(--edge); background:var(--well); padding:12px; }
.job .top { display:flex; gap:10px; align-items:baseline; flex-wrap:wrap; }
.job .t { font-weight:600; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.job .meta { color:var(--muted); font-size:12px; }
.bar { height:14px; border:2px solid var(--edge); background:var(--panel); margin:8px 0 4px; }
.bar i { display:block; height:100%; background:var(--bar); transition:width .6s; }
.state { font-size:12px; padding:1px 6px; border:1px solid var(--edge); }
.state.done { background:var(--hi); color:var(--bg); }
.state.error { background:#b3261e; color:#fff; border-color:#b3261e; }
.dl { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.dl a { color:var(--ink); border:2px solid var(--edge); padding:4px 10px; text-decoration:none; font-size:12px; background:var(--panel); }
.dl a:hover { background:var(--hi); color:var(--bg); }
.dl a.main { background:var(--hi); color:var(--bg); font-weight:600; }
.job .acts { display:flex; gap:6px; }
.job .acts button { padding:2px 8px; font-size:12px; }
.job .acts a.toonlink { color:var(--ink); border:2px solid var(--edge); padding:1px 8px; font-size:12px; text-decoration:none; background:var(--panel); }
.job .acts a.toonlink:hover { background:var(--hi); color:var(--bg); }
.empty { color:var(--muted); text-align:center; padding:40px 10px; border:2px dashed var(--edge); }
.track-label { font-size:12px; color:var(--muted); margin-top:8px; }
nav.tabs { display:flex; gap:4px; }
nav.tabs a { color:var(--ink); text-decoration:none; border:2px solid var(--edge); padding:4px 10px; font-weight:600; font-size:13px; }
nav.tabs a:hover { background:var(--hi); color:var(--bg); }
nav.tabs a.on { background:var(--ink); color:var(--panel); }
/* Toon Lab */
main.toon { grid-template-columns:minmax(0,400px) minmax(0,1fr); max-width:1600px; }
.side { position:sticky; top:20px; align-self:start; max-height:calc(100vh - 40px); overflow:auto; display:flex; flex-direction:column; gap:14px; padding-right:4px; }
details.fold > summary { list-style:none; cursor:pointer; padding:0; }
details.fold > summary::-webkit-details-marker { display:none; }
details.fold > summary h2 { margin:0; }
details.fold > summary h2::after { content:'▾'; margin-left:auto; transition:transform .15s; }
details.fold:not([open]) > summary h2::after { transform:rotate(-90deg); }
details.fold[open] > summary { margin-bottom:10px; }
.galbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:6px 0 10px; }
.galbar input, .galbar select { width:auto; flex:1 1 140px; padding:5px 8px; }
.galbar .chips { flex:0 0 auto; }
.gallery { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:10px; }
.gcard { border:2px solid var(--edge); background:var(--well); cursor:pointer; display:flex; flex-direction:column; }
.gcard:hover { outline:3px solid var(--hi); }
.gcard.flash { animation:flash 1.2s 3; } @keyframes flash { 50% { outline:4px solid var(--hi); } }
.gthumb { background:#000 center/contain no-repeat; aspect-ratio:16/10; position:relative; }
.gthumb span { position:absolute; right:4px; bottom:4px; background:rgba(0,0,0,.75); color:#fff; font-size:11px; padding:1px 5px; }
.gcard .gt { font-size:12px; font-weight:600; padding:6px 8px 0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.gcard .meta { font-size:11px; padding:0 8px 6px; }
.modal { position:fixed; inset:0; background:rgba(0,0,0,.75); display:grid; place-items:center; z-index:50; padding:16px; }
.modal[hidden] { display:none; }
.modalbox { background:var(--panel); border:2px solid var(--edge); padding:12px; width:min(960px, 100%); max-height:94vh; display:flex; flex-direction:column; gap:8px; }
.modalbox .top { display:flex; align-items:center; gap:8px; } .modalbox .top b { flex:1; }
.modalbox video { width:100%; max-height:70vh; background:#000; }
.stagecol { display:flex; flex-direction:column; gap:20px; min-width:0; }
.opt { margin:10px 0; }
.opt > b { display:block; color:var(--muted); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.chips { display:flex; flex-wrap:wrap; gap:4px; }
.chips button { padding:3px 8px; font-size:12px; }
.chips button.on { background:var(--ink); color:var(--panel); }
.pbuild { margin-top:8px; border:1px dashed var(--edge); padding:6px 8px; }
.pbuild summary { cursor:pointer; font-weight:600; font-size:13px; }
.pbuild .opt { margin-top:8px; }
.pbuild .pgo { display:flex; gap:10px; align-items:center; margin-top:10px; flex-wrap:wrap; }
.pdel { margin-top:6px; font-size:12px; }
.campv { position:relative; margin-top:8px; border:2px solid var(--edge); background:#000; aspect-ratio:4/3; overflow:hidden; }
.campv video, .campv canvas { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transform:scaleX(-1); }
.campv canvas { transform:none; }
.camtag { position:absolute; left:6px; bottom:6px; font-size:11px; color:#fff; background:rgba(0,0,0,.6); padding:1px 6px; }
#takeBtn.on { background:#c0152a; color:#fff; border-color:#c0152a; animation:pulse 1s infinite; }
:root[data-theme="studio"] .campv { border:1px solid var(--line); border-radius:10px; }
:root[data-theme="studio"] #takeBtn.on { background:var(--coral); color:#1a0d10; border-color:var(--coral); }
.scenes { margin-top:8px; border:1px dashed var(--line, currentColor); padding:8px; border-radius:6px; }
.scenes.over { outline:2px solid currentColor; }
.scgrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(84px, 1fr)); gap:6px; margin-bottom:6px; max-height:220px; overflow:auto; }
.scthumb { position:relative; aspect-ratio:16/9; cursor:pointer; border:2px solid transparent; border-radius:4px; overflow:hidden; }
.scthumb img { width:100%; height:100%; object-fit:cover; display:block; }
.scthumb.on { border-color:currentColor; box-shadow:0 0 0 2px rgba(0,0,0,.35); }
.scdel { position:absolute; top:2px; right:2px; padding:0 5px; font-size:11px; line-height:16px; min-height:0; opacity:0; }
.scthumb:hover .scdel { opacity:1; }
.stories { display:flex; flex-direction:column; gap:6px; margin-top:6px; }
.story { text-align:left; display:block; width:100%; padding:7px 9px; font-size:12px; line-height:1.35; }
.story b { display:block; font-size:13px; } .story small { opacity:.75; }
.swatches { display:flex; flex-wrap:wrap; gap:4px; align-items:center; margin-top:4px; }
.swatches button { width:24px; height:24px; padding:0; box-shadow:none; }
.swatches button.on { outline:3px solid var(--ink); outline-offset:1px; }
.swatches input[type=color] { width:30px; height:26px; padding:0; border:2px solid var(--edge); background:none; cursor:pointer; }
.subhead { font:13px "Archivo Black", sans-serif; text-transform:uppercase; margin:16px 0 4px; padding-top:10px; border-top:1px dashed var(--edge); }
.stagewrap { background:#000; border:2px solid var(--edge); display:grid; place-items:center; }
#stage { display:block; max-width:100%; max-height:64vh; }
.transport { display:flex; gap:8px; align-items:center; margin-top:8px; }
.transport input[type=range] { flex:1; accent-color:var(--hi); }
.transport .clock { font-size:12px; color:var(--muted); min-width:92px; text-align:right; }
.vis { font:15px "Archivo Black", sans-serif; min-width:84px; text-align:center; padding:4px 8px; background:var(--ink); color:var(--panel); }
#strip { display:block; width:100%; height:84px; margin-top:8px; border:2px solid var(--edge); cursor:pointer; background:var(--well); }
.renders video { width:100%; max-height:280px; background:#000; margin-top:8px; }
.chips.fonts button { font-size:16px; padding:4px 10px; }
.chips.styles button { font-weight:600; }
.job .bar { margin-top:6px; }
.cap { border:2px solid var(--edge); background:var(--well); padding:8px; margin-top:8px; }
.cap .row { margin-top:6px; }
.cap .row:first-child { margin-top:0; }
.caprow label { display:flex; align-items:center; gap:6px; color:var(--muted); font-size:12px; }
.caprow input[type=number] { width:100%; min-width:0; padding:4px 6px; }
.caprow select { padding:4px 6px; min-width:0; }
.caprow input[type=color] { flex:0 0 36px; height:30px; padding:0; border:2px solid var(--edge); background:none; }
#subLines .row { margin-top:4px; }
#subLines .meta { color:var(--muted); font-size:12px; min-width:48px; }
@media (max-width:860px){ .side { position:static; max-height:none; } }
/* Game Creator */
.castcard { display:flex; gap:10px; border:2px solid var(--edge); background:var(--well); padding:8px; margin-top:8px; }
.castcard .cthumb { width:54px; height:81px; object-fit:contain; background:rgba(0,0,0,.15); border:1px solid var(--edge); flex:0 0 auto; }
.castcard .cbody { flex:1; min-width:0; } .castcard .row { margin-top:4px; }
.castcard .crole { font:12px "Archivo Black", sans-serif; text-transform:uppercase; flex:0 0 auto; }
.cknobs { display:grid; grid-template-columns:1fr 1fr; gap:4px 8px; margin-top:4px; }
.cknobs label { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--muted); min-width:0; }
.cknobs input[type=range] { flex:1; min-width:0; } .cknobs select { padding:2px 4px; font-size:12px; min-width:0; }
.picker { border:2px solid var(--edge); background:var(--panel); padding:8px; margin-top:8px; }
.pgrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(72px, 1fr)); gap:6px; margin-top:8px; max-height:300px; overflow:auto; }
.pitem { display:flex; flex-direction:column; align-items:center; gap:3px; padding:4px; font-size:11px; min-width:0; }
.pitem img { width:48px; height:72px; object-fit:contain; } .pitem span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%; }
.bstage { position:relative; height:240px; margin-top:8px; background:radial-gradient(circle at 50% 30%, rgba(255,255,255,.14), rgba(0,0,0,.28)); border:2px solid var(--edge); }
.bstage canvas { width:100%; height:100%; display:block; cursor:grab; }
.bplate { position:absolute; left:8px; bottom:6px; font-size:11px; color:#fff; text-shadow:0 1px 2px #000; pointer-events:none; } .bplate b { display:block; font-size:13px; }
.bgrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(64px, 1fr)); gap:6px; margin-top:6px; }
.bitem { position:relative; cursor:pointer; text-align:center; font-size:11px; border:2px solid transparent; min-width:0; }
.bitem img { width:100%; aspect-ratio:1; object-fit:contain; background:rgba(0,0,0,.15); display:block; }
.bitem:hover { border-color:var(--hi); } .bitem span { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bitem .scdel { opacity:0; } .bitem:hover .scdel { opacity:1; }
.painter { margin-top:6px; border:2px solid var(--edge); background:#000; overflow:auto; max-height:380px; }
.painter canvas { display:block; cursor:crosshair; }
#lvTools button i { display:inline-block; width:10px; height:10px; margin-right:5px; vertical-align:-1px; border:1px solid rgba(0,0,0,.4); }
.sfxlist { display:grid; grid-template-columns:1fr 1fr; gap:4px 10px; margin-top:6px; }
.sfxrow { display:flex; align-items:center; gap:4px; font-size:12px; } .sfxrow span { flex:1; } .sfxrow button { padding:2px 7px; font-size:12px; }
.vlines .lbl { width:92px; }
.gshell { position:relative; background:#0b0e17; border:2px solid var(--edge); aspect-ratio:16/11; }
.gshell iframe { width:100%; height:100%; border:0; display:block; background:#0b0e17; }
.gempty { position:absolute; inset:0; display:grid; place-items:center; text-align:center; color:#cfd6e6; padding:20px; pointer-events:none; line-height:1.6; } .gempty[hidden] { display:none; }
.ggal { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:10px; }
.ggal .gthumb { aspect-ratio:16/9; background-size:cover; }
.gacts { display:flex; gap:4px; padding:4px 8px 8px; flex-wrap:wrap; }
.gacts button, .gacts a { padding:2px 7px; font-size:12px; color:var(--ink); border:2px solid var(--edge); background:var(--panel); text-decoration:none; }
.gacts a:hover, .gacts button:hover { background:var(--hi); color:var(--bg); }
:root[data-theme="studio"] :is(.castcard, .picker, .painter, .bstage, .gshell) { border:1px solid var(--line); border-radius:10px; }
:root[data-theme="studio"] .gshell { overflow:hidden; }
:root[data-theme="studio"] :is(.gacts button, .gacts a) { border:1px solid #323948; border-radius:6px; background:var(--raised); }
/* ── Studio: graphite console, lavender-to-ice signal. One loud thing: the meters (progress bars, waveforms, the Generate key) ── */
:root[data-theme="studio"] { --bg:#14171d; --panel:#1b1f27; --ink:#e7e9ee; --muted:#8b93a7; --edge:#2c3240; --hi:#b4a7ff; --accent:transparent; --bar:#b4a7ff; --well:#161a21;
  --raised:#232834; --raised2:#2a3040; --line:#2c3240; --line2:#363d4d; --ice:#8fe3ff; --coral:#ff6b7a; --sig:linear-gradient(90deg, #a596ff, #8fe3ff);
  color-scheme:dark; }
:root[data-theme="studio"] body { font:14px/1.5 Geist, ui-sans-serif, system-ui, sans-serif; letter-spacing:-.003em;
  background:radial-gradient(1200px 500px at 70% -200px, rgba(150,135,255,.10), transparent 70%), radial-gradient(900px 400px at -10% 110%, rgba(143,227,255,.05), transparent 70%), var(--bg) fixed; }
:root[data-theme="studio"] ::selection { background:rgba(180,167,255,.35); }
:root[data-theme="studio"] header { position:sticky; top:0; z-index:20; background:rgba(20,23,29,.78); backdrop-filter:blur(14px) saturate(1.2); -webkit-backdrop-filter:blur(14px) saturate(1.2);
  border-bottom:1px solid var(--line); padding:10px 22px; }
:root[data-theme="studio"] h1 { font:600 17px Geist, sans-serif; text-transform:none; letter-spacing:-.02em; }
:root[data-theme="studio"] h1 span { -webkit-text-stroke:0; background:var(--sig); -webkit-background-clip:text; background-clip:text; color:transparent; }
:root[data-theme="studio"] .tag { color:var(--muted); font-size:12.5px; }
:root[data-theme="studio"] nav.tabs { background:#10131a; border:1px solid var(--line); border-radius:10px; padding:3px; gap:2px; }
:root[data-theme="studio"] nav.tabs a { border:0; border-radius:7px; color:var(--muted); font-weight:500; padding:5px 12px; }
:root[data-theme="studio"] nav.tabs a:hover { background:var(--raised); color:var(--ink); }
:root[data-theme="studio"] nav.tabs a.on { background:var(--raised2); color:var(--ink); box-shadow:0 1px 0 rgba(255,255,255,.06) inset, 0 1px 2px rgba(0,0,0,.4); }
:root[data-theme="studio"] .themes button { border-radius:7px; background:transparent; border-color:transparent; color:var(--muted); }
:root[data-theme="studio"] .themes button:hover { background:var(--raised); color:var(--ink); }
:root[data-theme="studio"] .themes button.on { background:rgba(180,167,255,.14); color:#d9d3ff; border-color:rgba(180,167,255,.35); }
:root[data-theme="studio"] main { gap:22px; padding:24px; }
:root[data-theme="studio"] .panel { background:linear-gradient(180deg, #1d212a, #191d24); border:1px solid #2a303c; border-radius:14px; padding:18px;
  box-shadow:0 1px 0 rgba(255,255,255,.045) inset, 0 24px 48px -28px rgba(0,0,0,.75); }
:root[data-theme="studio"] .panel h2, :root[data-theme="studio"] .subhead { font:600 14.5px Geist, sans-serif; text-transform:none; letter-spacing:-.01em; }
:root[data-theme="studio"] .subhead { border-top:1px solid var(--line); }
:root[data-theme="studio"] .step { width:22px; height:22px; border-radius:6px; background:rgba(180,167,255,.13); color:#cfc7ff; font:500 11px "Geist Mono", monospace; box-shadow:0 0 0 1px rgba(180,167,255,.22) inset; }
:root[data-theme="studio"] .sec + .sec { border-top:1px solid var(--line); }
:root[data-theme="studio"] details.fold > summary h2::after { content:''; width:7px; height:7px; border-right:1.5px solid var(--muted); border-bottom:1.5px solid var(--muted); transform:rotate(45deg) translate(-2px, -2px); }
:root[data-theme="studio"] details.fold:not([open]) > summary h2::after { transform:rotate(-45deg); }
:root[data-theme="studio"] details.fold > summary:hover h2::after { border-color:var(--ink); }
:root[data-theme="studio"] :is(button, select, input[type=text], input[type=number]) { background:var(--raised); border:1px solid #323948; border-radius:8px; box-shadow:0 1px 0 rgba(255,255,255,.04) inset; color:var(--ink); transition:background .12s, border-color .12s; }
:root[data-theme="studio"] :is(input[type=text], input[type=number], textarea) { background:#11141a; }
:root[data-theme="studio"] button:hover:not(:disabled) { background:var(--raised2); border-color:#3e4658; color:var(--ink); }
:root[data-theme="studio"] :is(button, select, input, textarea, a, summary):focus-visible { outline:2px solid var(--hi); outline-offset:2px; }
:root[data-theme="studio"] :is(input[type=text], input[type=number], textarea, select):focus { border-color:rgba(180,167,255,.6); outline:none; box-shadow:0 0 0 3px rgba(180,167,255,.15); }
:root[data-theme="studio"] textarea { border:1px solid #323948; border-radius:10px; }
:root[data-theme="studio"] textarea.over { background:rgba(180,167,255,.08); color:var(--ink); }
:root[data-theme="studio"] button.big { height:50px; border-radius:11px; font:600 15px Geist, sans-serif; text-transform:none; letter-spacing:.005em; color:#16121f; border:0;
  background:linear-gradient(180deg, #c6bcff, #9b8aff); box-shadow:0 0 0 1px rgba(196,186,255,.55), 0 10px 28px -10px rgba(150,133,255,.75), 0 1px 0 rgba(255,255,255,.55) inset; }
:root[data-theme="studio"] button.big:hover:not(:disabled) { background:linear-gradient(180deg, #d0c7ff, #a697ff); color:#16121f; filter:none; }
:root[data-theme="studio"] button.big:active:not(:disabled) { transform:translateY(1px); box-shadow:0 0 0 1px rgba(196,186,255,.5), 0 4px 14px -8px rgba(150,133,255,.8); }
:root[data-theme="studio"] button.big:disabled { background:var(--raised); color:var(--muted); box-shadow:none; opacity:.7; }
:root[data-theme="studio"] button.rec { color:var(--coral); }
:root[data-theme="studio"] button.rec.on { background:var(--coral); color:#1a0d10; border-color:var(--coral); }
:root[data-theme="studio"] .drop { border:1px dashed #3a4152; border-radius:12px; background:#151920; color:var(--ink); }
:root[data-theme="studio"] .drop:hover { border-color:#4a5268; }
:root[data-theme="studio"] .drop.over { border-color:var(--hi); background:rgba(180,167,255,.08); color:var(--ink); }
:root[data-theme="studio"] .drop.has { border-style:solid; border-color:rgba(180,167,255,.4); }
:root[data-theme="studio"] .seg { border:1px solid var(--line); border-radius:10px; padding:3px; gap:3px; background:#11141a; }
:root[data-theme="studio"] .seg label { border-radius:7px; color:var(--muted); transition:background .12s, color .12s; }
:root[data-theme="studio"] .seg label:hover { color:var(--ink); }
:root[data-theme="studio"] .seg label:has(input:checked) { background:var(--raised2); color:var(--ink); box-shadow:0 1px 0 rgba(255,255,255,.06) inset, 0 1px 3px rgba(0,0,0,.45); }
:root[data-theme="studio"] .chips button { border-radius:6px; padding:3px 9px; background:#1f2430; }
:root[data-theme="studio"] .chips button.on { background:rgba(180,167,255,.15); border-color:rgba(180,167,255,.5); color:#ddd8ff; }
:root[data-theme="studio"] .opt > b { text-transform:none; letter-spacing:0; font-size:12px; font-weight:500; }
:root[data-theme="studio"] .field output, :root[data-theme="studio"] .clock, :root[data-theme="studio"] .vis { font-family:"Geist Mono", monospace; font-weight:500; font-size:12px; }
:root[data-theme="studio"] .vis { background:var(--raised); color:#d9d3ff; border-radius:6px; }
:root[data-theme="studio"] input[type=range] { accent-color:#a99bff; }
:root[data-theme="studio"] .check input { accent-color:#a99bff; }
:root[data-theme="studio"] details summary { font-weight:500; }
:root[data-theme="studio"] .bar { height:10px; border:0; border-radius:3px; padding:0; background:repeating-linear-gradient(90deg, #252b37 0 5px, transparent 5px 7px); }
:root[data-theme="studio"] .bar i { background:var(--sig); -webkit-mask:repeating-linear-gradient(90deg, #000 0 5px, transparent 5px 7px); mask:repeating-linear-gradient(90deg, #000 0 5px, transparent 5px 7px);
  filter:drop-shadow(0 0 6px rgba(143,227,255,.45)); }
:root[data-theme="studio"] .state { border:0; border-radius:999px; padding:1px 8px; background:var(--raised2); color:var(--muted); font-size:11px; font-weight:500; }
:root[data-theme="studio"] .state.running, :root[data-theme="studio"] .state.queued { color:var(--ice); background:rgba(143,227,255,.12); }
:root[data-theme="studio"] .state.done { background:rgba(180,167,255,.16); color:#ddd8ff; }
:root[data-theme="studio"] .state.error { background:rgba(255,107,122,.15); color:#ff9aa5; }
:root[data-theme="studio"] .err { background:rgba(255,107,122,.09); border:1px solid rgba(255,107,122,.35); color:#ffc4ca; border-radius:8px; }
:root[data-theme="studio"] .empty { border:1px dashed var(--line2); border-radius:12px; }
:root[data-theme="studio"] pre.log { border:1px solid var(--line); border-radius:8px; background:#10131a; font-family:"Geist Mono", monospace; }
:root[data-theme="studio"] :is(.job, .vcard, .gcard, .cap) { background:#171b22; border:1px solid #2a303c; border-radius:12px; overflow:hidden; }
:root[data-theme="studio"] .vcard { transition:border-color .15s; }
:root[data-theme="studio"] .vcard:hover, :root[data-theme="studio"] .gcard:hover { border-color:#3a4256; outline:none; }
:root[data-theme="studio"] .vc-art { height:58px; background:linear-gradient(180deg, #1e2330, #181c24); border-bottom:1px solid var(--line); }
:root[data-theme="studio"] .vc-wave i { background:linear-gradient(180deg, #c3b8ff, #8fe3ff); border-radius:1px; opacity:.35; }
:root[data-theme="studio"] .vcard.done .vc-wave i { opacity:.85; }
:root[data-theme="studio"] .vcard.running .vc-wave i { animation:vu 1.1s ease-in-out infinite alternate; opacity:.7; }
:root[data-theme="studio"] .vcard.running .vc-wave i:nth-child(3n) { animation-delay:-.4s; } :root[data-theme="studio"] .vcard.running .vc-wave i:nth-child(3n+1) { animation-delay:-.8s; }
@keyframes vu { to { transform:scaleY(.35); } }
@media (prefers-reduced-motion:reduce) { :root[data-theme="studio"] .vcard.running .vc-wave i { animation:none; } }
:root[data-theme="studio"] .vc-art .state { background:rgba(16,19,26,.85); }
:root[data-theme="studio"] :is(.dl a, .vc-dl a, .vc-acts a, .vc-acts button, .job .acts button, .job .acts a.toonlink) { border:1px solid #323948; border-radius:6px; background:var(--raised); color:var(--ink); }
:root[data-theme="studio"] :is(.dl a, .vc-dl a, .vc-acts a, .job .acts a.toonlink):hover { background:var(--raised2); color:var(--ink); }
:root[data-theme="studio"] .dl a.main { background:rgba(180,167,255,.16); border-color:rgba(180,167,255,.45); color:#ddd8ff; }
:root[data-theme="studio"] .vc-acts { border-top:1px solid var(--line); }
:root[data-theme="studio"] .gthumb { border-bottom:1px solid var(--line); }
:root[data-theme="studio"] :is(.stagewrap, #strip) { border:1px solid var(--line); border-radius:12px; overflow:hidden; }
:root[data-theme="studio"] #strip { border-radius:8px; background:#11141a; }
:root[data-theme="studio"] .modal { background:rgba(8,10,14,.72); backdrop-filter:blur(6px); }
:root[data-theme="studio"] .modalbox { border:1px solid var(--line2); border-radius:16px; box-shadow:0 40px 80px -30px rgba(0,0,0,.8); }
:root[data-theme="studio"] :is(.pbuild, .scenes) { border:1px solid var(--line); border-radius:10px; }
:root[data-theme="studio"] .swatches button { border-radius:6px; border:1px solid rgba(255,255,255,.12); }
:root[data-theme="studio"] .swatches button.on { outline:2px solid var(--hi); }
:root[data-theme="studio"] .story { border-radius:10px; }
:root[data-theme="studio"] * { scrollbar-width:thin; scrollbar-color:#363d4d transparent; }
</style>
</head>
<body>
<header>
  <h1>Voice <span>Swap</span></h1>
  <nav class="tabs"><a href="./" class="<?= $page === 'swap' ? 'on' : '' ?>">🎤 Voice Swap</a><a href="?p=toon" class="<?= $page === 'toon' ? 'on' : '' ?>">🎬 Toon Lab</a><a href="?p=game" class="<?= $page === 'game' ? 'on' : '' ?>">🕹 Game Creator</a></nav>
  <div class="tag"><?= ['swap' => 'put your voice on any song · runs 100% on this Mac', 'toon' => 'build a toon · it lip-syncs & moves to your audio', 'game' => 'your toons star in games you design · no engine, no API'][$page] ?></div>
  <div class="themes" id="themes">
    <button data-t="classic">Classic</button><button data-t="dark">Dark</button><button data-t="gold">All Gold Everything</button><button data-t="iced">Iced Out</button><button data-t="studio">Studio</button>
  </div>
</header>

<script>
const $ = s => document.querySelector(s);
const api = (a, body) => fetch('?a=' + a, body ? {method:'POST', body} : {}).catch(() => {
  throw new Error("Can't reach the app server. It may have stopped — run  php index.php  in the voice-swap folder, then reload this page.");
}).then(async r => {
  const t = await r.text(), i = t.search(/^[{[]/m); // skip any PHP warning HTML printed before the JSON
  let j; try { j = JSON.parse(i < 0 ? t : t.slice(i)); } catch { throw new Error(`Server sent an unexpected reply (${r.status}). Restart the app with:  php index.php`); }
  if (!r.ok) throw new Error(j.error || r.statusText); return j; });
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function wireTextBox(ta, btn, input, count) {   // textarea that also takes dropped / picked .txt and .md files
  const upd = () => { const w = ta.value.trim().split(/\s+/).filter(Boolean).length; count.textContent = w ? `${w} words · about ${Math.max(1, Math.round(w / 2.6))}s of speech` : ''; };
  const load = async f => { if (f) { ta.value = await f.text(); upd(); } };
  btn.onclick = () => input.click();
  input.onchange = () => load(input.files[0]);
  ta.ondragover = e => { e.preventDefault(); ta.classList.add('over'); };
  ta.ondragleave = () => ta.classList.remove('over');
  ta.ondrop = e => { if (e.dataTransfer.files.length) { e.preventDefault(); ta.classList.remove('over'); load(e.dataTransfer.files[0]); } };
  ta.addEventListener('input', upd); upd();
}

// theme
const setTheme = t => { document.documentElement.dataset.theme = t; document.querySelectorAll('#themes button').forEach(b => b.classList.toggle('on', b.dataset.t === t)); try { localStorage.vsTheme = t; } catch(e){} };
document.querySelectorAll('#themes button').forEach(b => b.onclick = () => setTheme(b.dataset.t));
try { setTheme(localStorage.vsTheme || 'gold'); } catch(e) { setTheme('gold'); }
// folded sections stay the way you left them
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('details.fold[data-fold]').forEach(d => {
  const k = 'fold:' + d.dataset.fold;
  try { if (localStorage[k]) d.open = localStorage[k] === '1'; } catch (e) {}
  d.addEventListener('toggle', () => { try { localStorage[k] = d.open ? '1' : '0'; } catch (e) {} });
}));
</script>

<?php if ($page === 'swap'): ?>
<main>
  <section class="panel banner" id="setup" hidden>
    <h2>⚙ Engine setup</h2>
    <div id="setupMsg"></div>
    <div class="row"><button class="shrink" id="installBtn">Install engine (~3GB download, one time)</button></div>
    <pre class="log" id="installLog" hidden></pre>
  </section>

  <form class="panel" id="form">
    <details class="sec fold" data-fold="v-voice" open>
      <summary><h2><span class="step">1</span> Your voice</h2></summary>
      <select id="savedVoice" name="saved_voice"><option value="">— new clip (upload or record) —</option></select>
      <div id="newVoice">
        <div class="drop" id="voiceDrop" style="margin-top:8px">Drop your voice clip here or click to browse
          <small>10–30 seconds of you talking or singing · MP3 / WAV / M4A / anything</small></div>
        <input type="file" id="voiceFile" accept="audio/*,video/*,.mp3,.wav,.m4a,.flac,.aac,.ogg,.webm,.mp4,.mov" hidden>
        <div class="row">
          <button type="button" class="rec shrink" id="recBtn">● Record</button>
          <input type="text" name="save_as" id="saveAs" placeholder="Save voice as… (optional name)">
        </div>
        <audio id="voicePreview" controls hidden></audio>
      </div>
      <div class="row" id="savedActs" hidden><audio id="savedPreview" controls></audio><button type="button" class="shrink" id="delVoice" title="Delete saved voice">✕</button></div>
    </details>

    <details class="sec fold" data-fold="v-target" open>
      <summary><h2><span class="step">2</span> Target song / clip</h2></summary>
      <div class="seg">
        <label><input type="radio" name="src" value="song" checked> 🎵 Song / clip</label>
        <label><input type="radio" name="src" value="text"> 📝 Text → my voice</label>
      </div>
      <div id="songSrc">
        <div class="drop" id="songDrop" style="margin-top:8px">Drop the song(s) here or click to browse
          <small>e.g. your Suno MP3 · several files = a queue</small></div>
        <input type="file" id="songFile" multiple accept="audio/*,video/*,.mp3,.wav,.m4a,.flac,.aac,.ogg,.mp4,.mov" hidden>
        <div id="songList"></div>
      </div>
      <div id="textSrc" hidden>
        <textarea id="ttsText" rows="7" placeholder="Type or paste what you want said in your voice… or drop a .txt / .md file here"></textarea>
        <div class="row"><button type="button" class="shrink" id="ttsLoad">📄 Load .txt / .md</button><span class="meta" id="ttsCount"></span></div>
        <input type="file" id="ttsFile" accept=".txt,.md,.markdown,text/plain,text/markdown" hidden>
        <div class="row"><label class="shrink lbl">Reader</label><select name="tts_voice" id="ttsVoice"><?php foreach (TTS_VOICES as [$v, $l]) echo "<option value=\"$v\">$l</option>"; ?></select></div>
        <div class="field"><label>Speed</label><input type="range" name="tts_speed" min="0.7" max="1.4" step="0.05" value="1"><output></output></div>
        <div class="hint">An AI voice reads your text first (it sets the pace and accent — pick one close to yours), then it's swapped into your voice. Markdown is cleaned up (headings, bullets, links) before reading.</div>
      </div>
    </details>

    <details class="sec fold" data-fold="v-settings" open>
      <summary><h2><span class="step">3</span> Settings</h2></summary>
      <div id="songOpts">
      <div class="seg">
        <label><input type="radio" name="mode" value="sing" checked> 🎤 Singing (songs)</label>
        <label><input type="radio" name="mode" value="speech"> 🗣 Speech (talking)</label>
      </div>
      <label class="check"><input type="checkbox" name="separate" value="1" checked><span>Target has background music<small>Split vocals from the beat, convert vocals, mix back</small></span></label>
      <label class="check" id="octaveRow"><input type="checkbox" name="auto_octave" value="1" checked><span>Auto octave match<small>Moves the melody up/down whole octaves to fit your range (stays in key)</small></span></label>
      </div>
      <div class="field"><label>Quality</label><input type="range" name="steps" min="10" max="50" step="5" value="25"><output></output></div>
      <div class="field"><label>Pitch (semi)</label><input type="range" name="semitones" min="-12" max="12" value="0"><output></output></div>
      <details>
        <summary>+ Advanced settings</summary>
        <label class="check"><input type="checkbox" name="clean_voice" value="1" checked><span>Clean my voice clip<small>Remove background noise/music from your clip first</small></span></label>
        <div class="field"><label>Voice likeness</label><input type="range" name="similarity" min="0" max="1" step="0.05" value="0.7"><output></output></div>
        <div class="field"><label>My vocal vol</label><input type="range" name="vocal_vol" min="0" max="200" step="5" value="100"><output></output></div>
        <div class="field"><label>Music vol</label><input type="range" name="music_vol" min="0" max="200" step="5" value="100"><output></output></div>
        <div class="field"><label>Original vox</label><input type="range" name="orig_vol" min="0" max="100" step="5" value="0"><output></output></div>
        <div class="hint">Quality = diffusion steps (more = cleaner, slower). Voice likeness = how hard it pushes toward your timbre. Original vox blends a little of the AI singer back in (helps harmonies/ad-libs). Pitch: use ±12 if you want an octave on top of auto.</div>
      </details>
    </details>

    <div class="sec">
      <button class="big" id="go" type="submit">Generate</button>
      <div class="hint" id="speedHint"></div>
      <div class="err" id="formErr" hidden></div>
    </div>
  </form>

  <section class="panel results">
    <div class="queue-head"><h2>Results</h2><span class="meta" id="qcount"></span></div>
    <div class="galbar">
      <input type="text" id="vqSearch" placeholder="Search by title or voice…">
      <select id="vqVoice"><option value="">Any voice</option></select>
      <select id="vqSort"><option value="new">Newest first</option><option value="old">Oldest first</option><option value="name">Title A–Z</option></select>
    </div>
    <div class="galbar">
      <div class="chips" id="vqType"><button type="button" data-v="" class="on">All</button><button type="button" data-v="song">🎵 Songs</button><button type="button" data-v="speech">🗣 Speech</button><button type="button" data-v="text">📝 Text</button><button type="button" data-v="toon">🎬 Toon Lab</button></div>
      <div class="chips" id="vqState"><button type="button" data-v="" class="on">Any status</button><button type="button" data-v="active">Working</button><button type="button" data-v="done">Done</button><button type="button" data-v="failed">Failed</button></div>
    </div>
    <div class="vgrid" id="jobs"><div class="empty">Nothing yet. Add your voice + a song and hit Generate.</div></div>
  </section>
</main>

<script>
// sliders
const fmt = {steps: v => v, semitones: v => (v > 0 ? '+' : '') + v, similarity: v => (+v).toFixed(2), vocal_vol: v => v + '%', music_vol: v => v + '%', orig_vol: v => v + '%', tts_speed: v => (+v).toFixed(2) + '×'};
document.querySelectorAll('.field input[type=range]').forEach(r => { const o = r.nextElementSibling; const u = () => o.textContent = fmt[r.name](r.value); r.oninput = u; u(); });
document.querySelectorAll('input[name=mode]').forEach(r => r.onchange = () => { const sing = $('input[name=mode]:checked').value === 'sing'; $('#octaveRow').style.opacity = sing ? 1 : .4; $('#octaveRow input').disabled = !sing; });

// voice input: file, drop, record, saved
let voiceBlob = null, voiceName = '', songs = [];
function setVoice(blob, name) {
  voiceBlob = blob; voiceName = name;
  $('#voiceDrop').classList.add('has');
  $('#voiceDrop').firstChild.textContent = '✓ ' + name + ' ';
  const a = $('#voicePreview'); a.src = URL.createObjectURL(blob); a.hidden = false;
}
function wireDrop(drop, input, onFiles) {
  drop.onclick = () => input.click();
  input.onchange = () => input.files.length && onFiles([...input.files]);
  drop.ondragover = e => { e.preventDefault(); drop.classList.add('over'); };
  drop.ondragleave = () => drop.classList.remove('over');
  drop.ondrop = e => { e.preventDefault(); drop.classList.remove('over'); e.dataTransfer.files.length && onFiles([...e.dataTransfer.files]); };
}
wireDrop($('#voiceDrop'), $('#voiceFile'), f => setVoice(f[0], f[0].name));
wireDrop($('#songDrop'), $('#songFile'), f => { songs = songs.concat(f); renderSongs(); });

// text → my voice
const isText = () => $('input[name=src]:checked').value === 'text';
document.querySelectorAll('input[name=src]').forEach(r => r.onchange = () => {
  $('#songSrc').hidden = isText(); $('#textSrc').hidden = !isText(); $('#songOpts').hidden = isText();
  try { localStorage.vsSrc = $('input[name=src]:checked').value; } catch (e) {}
});
try { if (localStorage.vsSrc === 'text') { $('input[name=src][value=text]').checked = true; $('input[name=src][value=text]').onchange(); } } catch (e) {}
wireTextBox($('#ttsText'), $('#ttsLoad'), $('#ttsFile'), $('#ttsCount'));
try { if (localStorage.vsTtsVoice) $('#ttsVoice').value = localStorage.vsTtsVoice; } catch (e) {}
$('#ttsVoice').onchange = () => { try { localStorage.vsTtsVoice = $('#ttsVoice').value; } catch (e) {} };
function renderSongs() {
  $('#songDrop').classList.toggle('has', songs.length > 0);
  $('#songList').innerHTML = songs.map((s, i) => `<div class="row"><audio controls src="${URL.createObjectURL(s)}"></audio><button type="button" class="shrink" data-i="${i}" title="Remove">✕</button></div><div class="track-label">${esc(s.name)} · ${(s.size/1048576).toFixed(1)} MB</div>`).join('');
  $('#songList').querySelectorAll('button').forEach(b => b.onclick = () => { songs.splice(+b.dataset.i, 1); renderSongs(); });
}

let rec = null, recTimer = null;
$('#recBtn').onclick = async () => {
  const btn = $('#recBtn');
  if (rec) { rec.stop(); return; }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({audio: {echoCancellation:false, noiseSuppression:false, autoGainControl:false}});
    const chunks = []; rec = new MediaRecorder(stream);
    rec.ondataavailable = e => chunks.push(e.data);
    rec.onstop = () => { stream.getTracks().forEach(t => t.stop()); clearInterval(recTimer); btn.classList.remove('on'); btn.textContent = '● Record';
      const type = rec.mimeType || 'audio/webm'; setVoice(new Blob(chunks, {type}), 'recording.' + (type.includes('mp4') ? 'm4a' : 'webm')); rec = null; };
    rec.start(); btn.classList.add('on'); let t = 0;
    btn.textContent = '■ Stop 0s'; recTimer = setInterval(() => { t++; btn.textContent = '■ Stop ' + t + 's'; if (t >= 40) rec.stop(); }, 1000);
  } catch (e) { showErr('Microphone not available: ' + e.message); }
};

async function loadVoices(select) {
  const v = await api('voices'); const sel = $('#savedVoice');
  sel.innerHTML = '<option value="">— new clip (upload or record) —</option>' + v.map(x => `<option value="${esc(x.file)}">🎙 ${esc(x.name)}</option>`).join('');
  if (select !== undefined) sel.value = select;
  sel.onchange();
}
$('#savedVoice').onchange = () => {
  const f = $('#savedVoice').value;
  $('#newVoice').hidden = !!f; $('#savedActs').hidden = !f;
  if (f) $('#savedPreview').src = '?a=voice_file&f=' + encodeURIComponent(f);
};
$('#delVoice').onclick = async () => { const fd = new FormData(); fd.append('f', $('#savedVoice').value); await api('delete_voice', fd); loadVoices(''); };

// submit
function showErr(m) { const e = $('#formErr'); e.textContent = m; e.hidden = !m; }
$('#form').onsubmit = async e => {
  e.preventDefault(); showErr('');
  const fd = new FormData($('#form'));
  fd.delete('songs[]');
  if (!fd.get('saved_voice')) { if (!voiceBlob) return showErr('Add your voice clip first (step 1).'); fd.append('voice', voiceBlob, voiceName); }
  if (isText()) {
    if (!$('#ttsText').value.trim()) return showErr('Type or paste the text you want said (step 2).');
    fd.append('text', $('#ttsText').value); fd.set('mode', 'speech'); fd.delete('separate');
  } else {
    if (!songs.length) return showErr('Add a target song (step 2).');
    songs.forEach(s => fd.append('songs[]', s, s.name));
  }
  const go = $('#go'); go.disabled = true; go.textContent = 'Uploading…';
  try {
    await api('create', fd);
    if (!isText()) { songs = []; renderSongs(); }
    if (fd.get('save_as')) { await loadVoices(); $('#saveAs').value = ''; }
    poll();
  } catch (err) { showErr(err.message); }
  go.disabled = false; go.textContent = 'Generate';
};

// jobs
const elapsed = s => s >= 60 ? Math.floor(s/60) + 'm ' + (s%60) + 's' : s + 's';
const jobKind = J => J.project ? 'toon' : J.tts ? 'text' : J.mode === 'speech' ? 'speech' : 'song';
const KIND_ICON = {song: '🎵', speech: '🗣', text: '📝', toon: '🎬'};
function waveArt(id) {   // a little waveform drawn from the job id, so every card has its own fingerprint
  let h = 2166136261; for (const c of id) h = Math.imul(h ^ c.charCodeAt(0), 16777619);
  const rnd = () => ((h = Math.imul(h ^ (h >>> 15), 2246822507) >>> 0) / 4294967296);
  return Array.from({length: 36}, (_, i) => `<i style="height:${Math.round(18 + 70 * Math.abs(Math.sin(i * 0.45 + rnd() * 2)) * (0.45 + rnd() * 0.55))}%"></i>`).join('');
}
const when = t => t ? new Date(t * 1000).toLocaleString([], {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}) : '';
function jobHTML(j) {
  const s = j.status, J = j.job, st = s.state, f = s.files || {}, kind = jobKind(J), busy = ['queued', 'running'].includes(st);
  const link = (k, label) => f[k] ? `<a href="?a=file&id=${j.id}&f=${encodeURIComponent(f[k])}&dl=1" title="Download ${label}">⬇ ${label}</a>` : '';
  const play = f.mix || f.vocals;
  const title = (J.title || 'Untitled').replace(/^📝\s*/, '');
  const meta = [J.voice_name && '🎙 ' + J.voice_name, s.pitch_shift ? 'pitch ' + (s.pitch_shift > 0 ? '+' : '') + s.pitch_shift : ''].filter(Boolean).join(' · ');
  return `<article class="vcard ${st}" data-id="${j.id}">
    <div class="vc-art"><div class="vc-wave">${waveArt(j.id)}</div><span class="vc-kind">${KIND_ICON[kind]}</span>${st !== 'done' ? `<span class="state ${st}">${st}</span>` : ''}</div>
    <div class="vc-body">
      <div class="vc-t" title="${esc(title)}">${esc(title)}</div>
      <div class="meta">${esc(meta) || '&nbsp;'}</div>
      <div class="meta">${esc(when(J.created))}${s.seconds ? ' · took ' + elapsed(s.seconds) : ''}</div>
      ${busy ? `<div class="bar"><i style="width:${Math.round((s.progress || 0) * 100)}%"></i></div><div class="meta vc-stage">${esc(s.stage)}</div>` : ''}
      ${st === 'done' && play ? `<audio controls preload="none" src="?a=file&id=${j.id}&f=${encodeURIComponent(play)}"></audio>` : ''}
      ${st === 'error' ? `<details class="vc-err"><summary>What went wrong</summary><div class="err">${esc(s.error)}</div><pre class="log">${esc(j.log)}</pre></details>` : ''}
      <div class="vc-acts">${busy ? `<button data-act="cancel" data-id="${j.id}">Cancel</button>` : ''}
        ${st === 'done' ? `<a href="?p=toon&job=${j.id}" title="Lip-sync a cartoon to this">🎬 Animate</a>${link('mix', f.vocals && f.mix ? 'Mix' : 'MP3')}${f.mix ? link('vocals', 'Vocals') : link('vocals', 'MP3')}${link('instrumental', 'Beat')}` : ''}
        <span class="vc-end">${st === 'done' ? `<button data-act="reveal" data-id="${j.id}" title="Show in Finder">📂</button>` : ''}${!busy ? `<button data-act="delete" data-id="${j.id}" title="Delete">🗑</button>` : ''}</span></div>
    </div>
  </article>`;
}
const VQ = {list: [], q: '', type: '', state: '', voice: '', sort: 'new'};
try { Object.assign(VQ, JSON.parse(localStorage.vsQueueFilter || '{}'), {list: [], q: ''}); } catch (e) {}
function vqMatch(j) {
  const st = j.status.state, J = j.job;
  if (VQ.type && jobKind(J) !== VQ.type) return false;
  if (VQ.state === 'active' && !['queued', 'running'].includes(st)) return false;
  if (VQ.state === 'done' && st !== 'done') return false;
  if (VQ.state === 'failed' && !['error', 'cancelled'].includes(st)) return false;
  if (VQ.voice && J.voice_name !== VQ.voice) return false;
  return !VQ.q || ((J.title || '') + ' ' + (J.voice_name || '')).toLowerCase().includes(VQ.q.toLowerCase());
}
function renderJobs() {
  const box = $('#jobs');
  const L = VQ.list.filter(vqMatch);
  if (VQ.sort === 'old') L.reverse();
  if (VQ.sort === 'name') L.sort((a, b) => (a.job.title || '').replace(/^📝\s*/, '').localeCompare((b.job.title || '').replace(/^📝\s*/, '')));
  const voices = [...new Set(VQ.list.map(j => j.job.voice_name).filter(Boolean))].sort();
  $('#vqVoice').innerHTML = '<option value="">Any voice</option>' + voices.map(v => `<option${v === VQ.voice ? ' selected' : ''}>${esc(v)}</option>`).join('');
  const counts = {}; VQ.list.forEach(j => counts[jobKind(j.job)] = (counts[jobKind(j.job)] || 0) + 1);
  document.querySelectorAll('#vqType button').forEach(b => { b.classList.toggle('on', b.dataset.v === VQ.type); b.hidden = b.dataset.v && !counts[b.dataset.v]; });
  document.querySelectorAll('#vqState button').forEach(b => b.classList.toggle('on', b.dataset.v === VQ.state));
  $('#qcount').textContent = (L.length === VQ.list.length ? `${L.length}` : `${L.length} of ${VQ.list.length}`) + (VQ.list.length === 1 ? ' result' : ' results');
  if (!L.length) {
    box.innerHTML = VQ.list.length ? '<div class="empty">Nothing matches these filters. <button type="button" id="vqClear">Clear filters</button></div>'
      : '<div class="empty">Nothing yet. Add your voice and a song, then hit Generate.</div>';
    const c = $('#vqClear'); if (c) c.onclick = () => { Object.assign(VQ, {q: '', type: '', state: '', voice: ''}); $('#vqSearch').value = ''; vqSave(); renderJobs(); };
    return;
  }
  box.querySelectorAll(':scope > :not(.vcard)').forEach(e => e.remove());
  const have = new Map([...box.children].map(e => [e.dataset.id, e]));
  let prev = null;
  for (const j of L) {   // keyed update: only changed cards are rebuilt, so a playing track keeps playing
    const sig = JSON.stringify([j.status.state, j.status.progress, j.status.stage, j.status.files]);
    let el = have.get(j.id);
    if (!el || el.dataset.sig !== sig) {
      const t = document.createElement('template'); t.innerHTML = jobHTML(j).trim();
      const n = t.content.firstElementChild; n.dataset.sig = sig;
      n.querySelectorAll('button[data-act]').forEach(b => b.onclick = async () => { const fd = new FormData(); fd.append('id', b.dataset.id); await api(b.dataset.act, fd); poll(); });
      if (el) el.replaceWith(n);
      el = n;
    }
    have.delete(j.id);
    const want = prev ? prev.nextElementSibling : box.firstElementChild;
    if (el !== want) box.insertBefore(el, want);
    prev = el;
  }
  have.forEach(e => e.remove());
}
const vqSave = () => { try { localStorage.vsQueueFilter = JSON.stringify({type: VQ.type, state: VQ.state, voice: VQ.voice, sort: VQ.sort}); } catch (e) {} };
$('#vqSearch').oninput = () => { VQ.q = $('#vqSearch').value.trim(); renderJobs(); };
$('#vqVoice').onchange = () => { VQ.voice = $('#vqVoice').value; vqSave(); renderJobs(); };
$('#vqSort').value = VQ.sort; $('#vqSort').onchange = () => { VQ.sort = $('#vqSort').value; vqSave(); renderJobs(); };
document.querySelectorAll('#vqType button').forEach(b => b.onclick = () => { VQ.type = b.dataset.v; vqSave(); renderJobs(); });
document.querySelectorAll('#vqState button').forEach(b => b.onclick = () => { VQ.state = b.dataset.v; vqSave(); renderJobs(); });
let pollT = null;
async function poll() {
  clearTimeout(pollT);
  try { VQ.list = await api('jobs'); } catch (e) {}
  renderJobs();
  const active = VQ.list.filter(j => ['queued', 'running'].includes(j.status.state)).length;
  if (active) $('#qcount').textContent += ` · ${active} working`;
  pollT = setTimeout(poll, active ? 1500 : 8000);
}

// engine state
async function checkEngine() {
  const e = await api('engine');
  const box = $('#setup'), log = $('#installLog');
  $('#speedHint').textContent = e.ram_gb ? `This Mac: ${e.ram_gb}GB RAM. ${e.ram_gb <= 8 ? 'Expect roughly 5–10 min per minute of song; close other apps.' : 'Expect a few minutes per song.'}` : '';
  const mb = v => parseFloat(v) * ({k: 1 / 1024, m: 1, g: 1024}[String(v).trim().slice(-1).toLowerCase()] || 1 / 1048576);   // "1G" → 1024 MB
  if (mb(e.upload_limit) < 50) showErr(`Upload limit is only ${e.upload_limit}. Start the app with:  php index.php`);
  if (e.ready) { box.hidden = true; $('#go').disabled = false; return; }
  box.hidden = false; $('#go').disabled = true;
  const missing = [!e.python && 'Python 3.10–3.12 → brew install python@3.11', !e.ffmpeg && 'ffmpeg → brew install ffmpeg', !e.git && 'git → xcode-select --install'].filter(Boolean);
  $('#setupMsg').innerHTML = missing.length ? '<div class="err">Missing:\n' + missing.map(esc).join('\n') + '</div>'
    : `<div class="hint">Installs Seed-VC (zero-shot voice conversion) + Demucs (vocal separation) into <b>engine/</b> next to this file. Python: ${esc(e.python)}</div>`;
  $('#installBtn').disabled = missing.length > 0 || e.installing;
  $('#installBtn').textContent = e.installing ? 'Installing…' : 'Install engine (~3GB download, one time)';
  if (e.log) { log.hidden = false; log.textContent = e.log; log.scrollTop = 1e9; }
  if (e.installing || !e.ready) setTimeout(checkEngine, e.installing ? 2000 : 10000);
}
$('#installBtn').onclick = async () => { try { await api('install', new FormData()); } catch (e) { showErr(e.message); } checkEngine(); };

checkEngine(); loadVoices(); poll();
</script>

<?php elseif ($page === 'toon'): ?>
<main class="toon">
  <aside class="side">
  <details class="panel fold" data-fold="build" open>
    <summary><h2><span class="step">1</span> Build your toon</h2></summary>
    <div class="row"><select id="charSel"></select><button type="button" class="shrink" id="randBtn" title="Random toon">🎲 Random</button></div>
    <div class="row"><select id="presetSel"></select></div>
    <div id="opts"></div>
    <div class="subhead">Save</div>
    <div class="row"><input type="text" id="charName" placeholder="Name your toon" maxlength="40"><button type="button" class="shrink" id="saveChar">Save</button><button type="button" class="shrink" id="delChar" title="Delete this saved toon">✕</button></div>
    <div id="charMsg" hidden></div>
  </details>
  <details class="panel fold" data-fold="audio" open>
    <summary><h2><span class="step">2</span> Audio</h2></summary>
      <div class="seg">
        <label><input type="radio" name="asrc" value="file" checked> 🎵 Audio</label>
        <label><input type="radio" name="asrc" value="text"> 📝 Type it</label>
        <label><input type="radio" name="asrc" value="director"> ✨ Director</label>
      </div>
      <div id="dirSrc" hidden>
        <div class="hint">Describe a video in one sentence. Your local AI picks the toon, art style and captions and writes the script, which lands in 📝 Type it for you to edit and speak.</div>
        <textarea id="dirPrompt" rows="2" placeholder="e.g. a hacker on a computer in 3D ASCII style talking about local AI news"></textarea>
        <div class="row">
          <select id="llmModel" title="Which local model writes it"></select>
          <button type="button" class="shrink" id="llmRefresh" title="Reload the model list">↻</button>
          <button type="button" class="shrink" id="dirBtn">✨ Write it</button>
        </div>
        <div class="row"><button type="button" id="newsBtn">📰 Find a news story on this</button></div>
        <div class="hint">Searches DuckDuckGo News for this week's stories. Pick one and the script reports it.</div>
        <div class="stories" id="stories" hidden></div>
        <div class="hint" id="dirInfo"></div>
        <div class="err" id="dirErr" hidden></div>
        <details><summary>⚙ Model server</summary>
          <div class="hint">Ollama is the default (it lists every model you've pulled). Any OpenAI-compatible server works too: llama.cpp, LM Studio, MLX…</div>
          <div class="row"><select id="llmProvider" style="max-width:190px"><option value="ollama">Ollama</option><option value="openai">OpenAI-compatible</option></select><input type="text" id="llmUrl" placeholder="http://127.0.0.1:11434"><button type="button" class="shrink" id="llmSave">Save</button></div>
        </details>
      </div>
      <div id="fileSrc">
      <div class="drop" id="audioDrop" style="margin-top:8px">Drop a voice clip or song here or click to browse
        <small>talking, rapping, singing · MP3 / WAV / M4A / MP4 / anything</small></div>
      <input type="file" id="audioFile" accept="audio/*,video/*,.mp3,.wav,.m4a,.flac,.aac,.ogg,.webm,.mp4,.mov" hidden>
      <div class="row"><select id="swapJob"><option value="">…or use a Voice Swap result</option></select><button type="button" class="rec shrink" id="micBtn">🎙 Live mic</button></div>
      </div>
      <div id="textSrc" hidden>
        <textarea id="ttsText" rows="6" placeholder="What should your toon say? Type, paste, or drop a .txt / .md file here."></textarea>
        <div class="row"><button type="button" class="shrink" id="ttsLoad">📄 Load .txt / .md</button><span class="meta" id="ttsCount"></span></div>
        <input type="file" id="ttsFile" accept=".txt,.md,.markdown,text/plain,text/markdown" hidden>
        <div class="row"><label class="shrink lbl">Voice</label><select id="ttsSpeaker"></select></div>
        <div class="row"><label class="shrink lbl">Reader</label><select id="ttsVoice"><?php foreach (TTS_VOICES as [$v, $l]) echo "<option value=\"$v\">$l</option>"; ?></select></div>
        <div class="field"><label>Speed</label><input type="range" id="ttsSpeed" min="0.7" max="1.4" step="0.05" value="1"><output></output></div>
        <button class="big" id="speakBtn" type="button">🗣 Speak it</button>
        <div class="hint">Your voice: an AI reader speaks it, then Seed-VC swaps in your saved voice (record one on the Voice Swap page). AI voice only is faster. Then it syncs the mouth automatically.</div>
      </div>
      <div class="seg" style="margin-top:10px">
        <label><input type="radio" name="amode" value="precise" checked> 🔬 Precise</label>
        <label><input type="radio" name="amode" value="instant"> ⚡ Instant</label>
      </div>
      <div class="hint" id="modeHint"></div>
      <div id="preciseOpts">
        <label class="check"><input type="checkbox" id="isolate"><span>Has background music<small>Isolate the voice with Demucs first (slower, much better for songs)</small></span></label>
        <label class="check"><input type="checkbox" id="words" checked><span>Read the words too<small>Whisper transcript → captions + word labels on the timeline</small></span></label>
      </div>
      <button class="big" id="analyzeBtn" type="button" disabled>Sync the mouth</button>
      <div id="anProg" hidden><div class="bar"><i></i></div><div class="meta" id="anStage"></div></div>
      <div class="hint" id="anInfo"></div>
      <div class="err" id="anErr" hidden></div>
      <div class="subhead">🎵 Background music</div>
      <div class="row"><select id="bgmSel"><option value="">No music</option></select><button type="button" class="shrink" id="bgmPlay" title="Preview">▶</button><button type="button" class="shrink" id="bgmDel" title="Delete this track" disabled>🗑</button></div>
      <div class="field"><label>Music volume</label><input type="range" id="bgmVol" min="0" max="1" step="0.05" value="0.25"><output></output></div>
      <label class="check"><input type="checkbox" id="bgmDuck" checked><span>Dip under the voice<small>The music gets quieter while the toon talks</small></span></label>
      <details id="bgmMake"><summary>🎼 Compose or add a track</summary>
        <textarea id="bgmPrompt" rows="2" placeholder="e.g. upbeat synthwave with punchy drums · chill lofi piano · tense cinematic strings"></textarea>
        <div class="field"><label>Length</label><input type="range" id="bgmSecs" min="8" max="30" step="1" value="20"><output></output></div>
        <div class="row"><button type="button" class="shrink" id="bgmGo">🎼 Compose</button><button type="button" class="shrink" id="bgmUp">⬆ Upload a track</button></div>
        <input type="file" id="bgmFile" accept="audio/*,.mp3,.wav,.m4a,.ogg,.flac" hidden>
        <div id="bgmProg" hidden><div class="bar"><i></i></div><div class="meta" id="bgmStage"></div></div>
        <div class="hint" id="bgmMsg">MusicGen (small) composes it on this Mac in about a minute. Tracks loop to fit the video. MusicGen's license is non-commercial.</div>
      </details>
  </details>
  <details class="panel fold" data-fold="motion">
    <summary><h2><span class="step">3</span> Motion</h2></summary>
      <div class="subhead" style="margin-top:0;border-top:0;padding-top:0">🎥 Move with my camera</div>
      <div class="row"><button type="button" id="camBtn">📷 Start camera</button></div>
      <div class="campv" id="camPv" hidden><video id="camVid" playsinline muted></video><canvas id="camSk"></canvas><span class="camtag" id="camTag"></span></div>
      <div class="hint" id="camMsg">Your webcam drives the character: head, eyes, mouth, brows, arms and hands, body lean, stepping side to side, and legs when they're in view. It runs on this Mac; no video leaves it.</div>
      <div id="camOpts" hidden>
        <div class="opt"><b>Follow my</b><div class="chips" id="camParts"><button type="button" data-v="face" class="on">Face &amp; head</button><button type="button" data-v="arms" class="on">Arms &amp; hands</button><button type="button" data-v="body" class="on">Body &amp; legs</button></div></div>
        <div class="opt"><b>Mouth</b><div class="chips" id="camMouth"><button type="button" data-v="face" class="on">Copies my mouth</button><button type="button" data-v="audio">Lip-syncs to the audio</button></div></div>
        <div class="field"><label>Smoothing</label><input type="range" id="camSmooth" min="0" max="0.9" step="0.05" value="0.45"><output></output></div>
        <label class="check"><input type="checkbox" id="camWalk" checked><span>Move around the stage<small>Step left or right and the character moves with you</small></span></label>
        <div class="row"><button type="button" id="takeBtn">● Record my moves</button></div>
        <div class="hint">Record video with the camera on to act it out live: over the loaded clip, with 🎙 Live mic, or silently. Or record your moves first and add the voice later (📝 Type it → 🗣 Speak it).</div>
      </div>
      <div id="takeRow" hidden>
        <label class="check"><input type="checkbox" id="takeUse" checked><span id="takeInfo">Use my recorded moves</span></label>
        <div class="row"><button type="button" class="shrink" id="takeClear">✕ Discard my moves</button></div>
      </div>
      <div class="subhead">Automatic motion</div>
      <div class="field"><label>Mouth size</label><input type="range" id="mouthAmt" min="0.5" max="1.6" step="0.05" value="1"><output></output></div>
      <div class="field"><label>Body energy</label><input type="range" id="energy" min="0" max="2" step="0.1" value="1"><output></output></div>
      <div class="field"><label>Hand gestures</label><input type="range" id="gestures" min="0" max="1" step="0.05" value="0.6"><output></output></div>
      <div class="field"><label>Sync offset</label><input type="range" id="offset" min="-200" max="200" step="10" value="-40"><output></output></div>
      <label class="check"><input type="checkbox" id="dance"><span>Dance to the beat<small>Bob to the song's tempo (for music)</small></span></label>
      <div class="opt"><b>Camera</b><div class="chips" id="camera"><button type="button" data-v="full">Full body</button><button type="button" data-v="medium">Medium</button><button type="button" data-v="close">Close-up</button></div></div>
  </details>
  <details class="panel fold" data-fold="subs">
    <summary><h2><span class="step">4</span> Subtitles &amp; captions</h2></summary>
      <div class="opt"><b>Subtitles (what's being said)</b><div class="chips" id="subStyle"><button type="button" data-v="off">Off</button><button type="button" data-v="karaoke">Karaoke</button><button type="button" data-v="subtitle">Classic subtitle</button><button type="button" data-v="pop">Pop words</button></div></div>
      <div class="hint" id="subHint"></div>
      <div class="opt"><b>Font</b><div class="chips fonts" id="subFont"></div></div>
      <div class="opt"><b>Position</b><div class="chips" id="subPos"><button type="button" data-v="top">Top</button><button type="button" data-v="middle">Middle</button><button type="button" data-v="bottom">Bottom</button></div></div>
      <div class="field"><label>Size</label><input type="range" id="subSize" min="0.5" max="2" step="0.05" value="1"><output></output></div>
      <div class="opt"><b>Text color</b><div class="swatches" id="subColor"></div></div>
      <div class="opt"><b>Highlight color</b><div class="swatches" id="subHi"></div></div>
      <label class="check"><input type="checkbox" id="subBox"><span>Dark box behind the words<small>Easier to read over busy backgrounds</small></span></label>
      <details id="subEdit" hidden><summary>✎ Fix the subtitle text</summary>
        <div class="hint">Whisper misheard something? Edit a line here; the timing stays put.</div>
        <div id="subLines"></div>
        <div class="row"><button type="button" class="shrink" id="srtBtn">⬇ Download subtitles (.srt)</button></div>
      </details>
      <div class="subhead">Captions (your own text)</div>
      <div class="hint">Titles, lower-thirds and speech bubbles. Each shows between its start and end time, in its own font and color.</div>
      <div id="capList"></div>
      <div class="row"><button type="button" class="shrink" id="addCap">+ Add caption at the playhead</button></div>
  </details>
  <details class="panel fold" data-fold="make" open>
    <summary><h2><span class="step">5</span> Make the video</h2></summary>
      <div class="opt"><b>Frame</b><div class="chips" id="aspect"><button type="button" data-a="16:9" class="on">16:9 YouTube</button><button type="button" data-a="9:16">9:16 Shorts / TikTok</button><button type="button" data-a="1:1">1:1 Square</button></div></div>
      <button class="big" id="recordBtn" type="button" disabled>● Record video</button>
      <div class="hint">Plays the clip from the start and records the stage + audio, then converts it to MP4. Keep this tab in front while it records.</div>
      <div class="row"><button type="button" class="shrink" id="bgMake">🚀 Make it in the background</button><span class="hint">Speaks the 📝 script, syncs and renders in a hidden browser, so you can keep working.</span></div>
      <div class="jobs renders" id="renders"></div>
  </details>
  </aside>

  <div class="stagecol">
    <section class="panel">
      <div class="stagewrap"><canvas id="stage" width="1280" height="720"></canvas></div>
      <div class="transport">
        <button type="button" id="playBtn" disabled>▶ Play</button>
        <input type="range" id="seek" min="0" max="1000" value="0" disabled>
        <span class="clock" id="clock">0:00 / 0:00</span>
        <span class="vis" id="visLabel" title="Current mouth shape">·</span>
      </div>
      <canvas id="strip" width="1400" height="84" title="Mouth shapes over time · click to jump"></canvas>
    </section>

    <section class="panel">
      <h2>🎞 Gallery <span class="meta" id="galCount"></span></h2>
      <div class="galbar">
        <input type="text" id="galSearch" placeholder="Search by name…">
        <div class="chips" id="galAspect"><button type="button" data-v="" class="on">All</button><button type="button" data-v="16:9">16:9</button><button type="button" data-v="9:16">9:16</button><button type="button" data-v="1:1">1:1</button></div>
        <select id="galStyle"><option value="">Any style</option></select>
        <select id="galSort"><option value="new">Newest first</option><option value="old">Oldest first</option><option value="long">Longest</option><option value="name">Name</option></select>
      </div>
      <div class="gallery" id="gallery"></div>
    </section>
  </div>
</main>
<div class="modal" id="modal" hidden><div class="modalbox">
  <div class="top"><b id="mTitle"></b><button type="button" class="shrink" id="mClose">✕</button></div>
  <video id="mVideo" controls playsinline></video>
  <div class="meta" id="mMeta"></div>
  <div class="dl"><a class="main" id="mDl">⬇ MP4</a><a href="#" id="mReveal">Show file</a><a href="#" id="mDel">🗑 Delete</a></div>
</div></div>

<?php endif; ?>
<?php if ($page === 'toon' || $page === 'game'): ?>
<script>
/* ═══════════════ character model (shared by Toon Lab and the Game Creator) ═══════════════ */
const PAL = <?= json_encode(TOON_PAL) ?>;
const SCHEMA = <?= json_encode(toon_schema()) ?>;
const DEFAULT_CHAR = <?= json_encode(TOON_DEFAULT) ?>;
const AVATAR_META = <?= json_encode(array_column(toon_avatars(true), null, 'id')) ?>;
const PRESETS = <?= json_encode(toon_presets()) ?>;
const HATS_IN_HAIR = ['cap', 'beanie', 'tophat'];
function normChar(c) {   // older saves kept hats under "hair"
  c = {...DEFAULT_CHAR, ...c};
  if (HATS_IN_HAIR.includes(c.hair)) { if (!c.hat || c.hat === 'none') c.hat = c.hair; c.hair = 'short'; }
  return c;
}
let C = {...DEFAULT_CHAR};
try { const s = JSON.parse(localStorage.toonChar || 'null'); if (s) C = normChar(s); } catch (e) {}
const saveLocal = () => { try { localStorage.toonChar = JSON.stringify(C); } catch (e) {} };
const pick = a => a[Math.floor(Math.random() * a.length)];
const SCN = {list: null, img: {}};
function sceneImg(f) {   // a scene picture (scenes/), decoded; null while it loads
  if (!f) return null;
  let im = SCN.img[f];
  if (!im) { im = SCN.img[f] = new Image(); im.src = 'scenes/' + encodeURIComponent(f); }
  return im.complete && im.naturalWidth ? im : null;
}
</script>
<?php endif; ?>
<?php if ($page === 'toon'): ?>
<script>
/* ═══════════════ character builder (Toon Lab) ═══════════════ */
function buildOpts() {
  $('#opts').innerHTML = SCHEMA.map(([key, label, spec, multi]) => {
    if (!label) return `<div class="subhead">${key}</div>`;
    if (typeof spec === 'string') return `<div class="opt"><b>${label}</b><div class="swatches" data-k="${key}">` + PAL[spec].map(c => c === 'auto'
      ? `<button type="button" data-v="auto" title="Auto (from skin)" style="background:linear-gradient(135deg,#fff 45%,#c0392b 55%)"></button>`
      : `<button type="button" data-v="${c}" style="background:${c}"></button>`).join('') + `<input type="color" data-k="${key}" title="Custom color"></div></div>`;
    if (typeof spec[0] === 'number') return `<div class="field"><label>${label}</label><input type="range" data-k="${key}" min="${spec[0]}" max="${spec[1]}" step="${spec[2]}"><output></output></div>`;
    return `<div class="opt"><b>${label}</b><div class="chips${key === 'style' ? ' styles' : ''}" data-k="${key}"${multi ? ' data-multi="1"' : ''}>${spec.map(([v, l]) => `<button type="button" data-v="${v}">${l}</button>`).join('')}</div></div>`;
  }).join('');
  $('#opts').querySelectorAll('.chips button, .swatches button').forEach(b => b.onclick = () => {
    const g = b.parentElement, k = g.dataset.k;
    if (g.dataset.multi) { const s = new Set(C[k]); s.has(b.dataset.v) ? s.delete(b.dataset.v) : s.add(b.dataset.v); C[k] = [...s]; }
    else C[k] = b.dataset.v;
    if (k === 'avatar') { const hc = ((AVATAR_META[C.avatar] || {}).made || {}).hairColor; if (hc) C.hairColor = hc; }   // a built person brings the hair color it was made with
    charChanged();
  });
  $('#opts').querySelectorAll('input[type=color]').forEach(i => i.oninput = () => { C[i.dataset.k] = i.value; charChanged(); });
  $('#opts').querySelectorAll('input[type=range]').forEach(i => i.oninput = () => { C[i.dataset.k] = +i.value; charChanged(); });
  mountPersonBuilder($('#opts [data-k="avatar"]').closest('.opt'));
  mountScenes($('#opts [data-k="bg"]').closest('.opt'));
  const note = document.createElement('div'); note.className = 'hint realnote'; note.hidden = true;
  note.textContent = 'Showing only what changes a 3D person. Their face, hair style and outfit are set when the person is built (🧍 Build a new 3D person).';
  $('#opts [data-k="avatar"]').closest('.opt').after(note);
  syncOpts();
}

/* ── 🧍 build a new realistic 3D person in Blender (MPFB / MakeHuman) ── */
let PB = null;   // {opts, person}
function mountPersonBuilder(host) {
  const d = document.createElement('details'); d.className = 'pbuild';
  d.innerHTML = `<summary>🧍 Build a new 3D person in Blender</summary><div class="pbody"><div class="hint">Loading…</div></div>`;
  host.appendChild(d);
  const del = document.createElement('button'); del.type = 'button'; del.className = 'pdel'; del.textContent = '🗑 Delete this person'; host.appendChild(del);
  const note = document.createElement('span'); note.className = 'hint pnote'; host.appendChild(note);
  let armed = '';
  del.onclick = async () => {
    const id = C.avatar, name = (AVATAR_META[id] || {}).label || id;
    if (armed !== id) { armed = id; del.textContent = `🗑 Click again to delete ${name}`; note.textContent = ''; setTimeout(() => { if (armed === id) { armed = ''; del.textContent = '🗑 Delete this person'; } }, 4000); return; }
    armed = ''; del.disabled = true; del.textContent = 'Deleting…';
    const fd = new FormData(); fd.append('id', id);
    try {
      await api('person_delete', fd);
      r3evict(id); C.avatar = 'mpfb'; await refreshPeople();
      const n = document.querySelector('#opts .pnote'); if (n) n.textContent = `✓ Deleted ${name}`;
    } catch (e) { del.disabled = false; del.textContent = '🗑 Delete this person'; note.className = 'err pnote'; note.textContent = e.message; }
  };
  d.ontoggle = () => d.open && !PB && api('people').then(r => { PB = {opts: r.options, blender: r.blender, person: {...r.defaults}}; drawPersonBuilder(d.querySelector('.pbody')); })
    .catch(e => d.querySelector('.pbody').innerHTML = `<div class="err">${esc(e.message)}</div>`);
}
function drawPersonBuilder(box) {
  const P = PB.person;
  box.innerHTML = (PB.blender ? '' : '<div class="err">Blender isn\'t installed. Get it free from blender.org (into /Applications), then reload.</div>')
    + `<div class="hint">Makes a rigged, lip-sync-ready person with Blender + MPFB (MakeHuman, CC0 assets). Takes about 10 seconds; the first build also downloads ~280 MB of skins, hair and clothes.</div>
    <div class="field"><label>Name</label><input id="pName" maxlength="40" placeholder="e.g. Marcus" value="${esc(P.name)}"></div>`
    + PB.opts.map(([k, label, spec]) => typeof spec[0] === 'number'
      ? `<div class="field"><label>${label}</label><input type="range" data-pk="${k}" min="${spec[0]}" max="${spec[1]}" step="${spec[2]}" value="${P[k]}"><output>${P[k]}</output></div>`
      : `<div class="opt"><b>${label}</b><div class="chips" data-pk="${k}">${spec.map(([v, l]) => `<button type="button" data-v="${v}" class="${P[k] === v ? 'on' : ''}"${v[0] === '#' ? ` style="border-left:14px solid ${v}"` : ''}>${l}</button>`).join('')}</div></div>`).join('')
    + `<div class="pgo"><button type="button" class="main" id="pBuild"${PB.blender ? '' : ' disabled'}>Build in Blender</button><span id="pMsg" class="hint"></span></div>
    <div class="hint">Outfits keep their own colors. Glasses come from the toon options above, and the skin and hair swatches there can still recolor this person.</div>`;
  box.querySelectorAll('.chips[data-pk] button').forEach(b => b.onclick = () => {
    const k = b.parentElement.dataset.pk; P[k] = b.dataset.v;
    if (k === 'body' && b.dataset.v === 'woman' && P.outfit.startsWith('male_')) { P.outfit = 'female_casualsuit01'; P.hair = 'ponytail01'; }
    if (k === 'body' && b.dataset.v === 'man' && P.outfit.startsWith('female_')) { P.outfit = 'male_casualsuit04'; P.hair = 'short02'; }
    drawPersonBuilder(box);
  });
  box.querySelectorAll('input[data-pk]').forEach(i => i.oninput = () => { P[i.dataset.pk] = +i.value; i.nextElementSibling.textContent = i.value; });
  box.querySelector('#pName').oninput = e => P.name = e.target.value;
  box.querySelector('#pBuild').onclick = () => buildPerson(box);
}
async function buildPerson(box) {
  const btn = box.querySelector('#pBuild'), msg = box.querySelector('#pMsg');
  btn.disabled = true; msg.className = 'hint'; msg.textContent = 'Starting Blender…';
  try {
    const {id} = await api('person_make', JSON.stringify(PB.person));
    for (;;) {
      await sleep(800);
      const st = await api('person_status&id=' + id);
      msg.textContent = `${Math.round((st.progress || 0) * 100)}% · ${st.stage || ''}`;
      if (st.state === 'error') throw new Error(st.error);
      if (st.state === 'done') { C.style = 'real'; C.avatar = st.avatar; C.hairColor = PB.person.hairColor || C.hairColor; await refreshPeople(); return; }
    }
  } catch (e) { msg.className = 'err'; msg.textContent = e.message; btn.disabled = false; }
}
async function refreshPeople() {   // new or deleted person: update the picker and its settings
  const r = await api('people');
  SCHEMA.find(x => x[0] === 'avatar')[2] = r.schema;
  for (const k in AVATAR_META) delete AVATAR_META[k];
  for (const a of r.avatars) AVATAR_META[a.id] = a;
  if (!AVATAR_META[C.avatar]) C.avatar = r.schema[0] ? r.schema[0][0] : 'mpfb';
  PB = null; buildOpts(); charChanged();
}
function syncOpts() {
  $('#opts').querySelectorAll('.chips, .swatches').forEach(g => g.querySelectorAll('button').forEach(b => {
    const v = C[g.dataset.k]; b.classList.toggle('on', Array.isArray(v) ? v.includes(b.dataset.v) : v === b.dataset.v); }));
  $('#opts').querySelectorAll('input[type=color]').forEach(i => { if (/^#[0-9a-f]{6}$/i.test(C[i.dataset.k])) i.value = C[i.dataset.k]; });
  $('#opts').querySelectorAll('input[type=range]').forEach(i => { i.value = C[i.dataset.k]; i.nextElementSibling.textContent = (+C[i.dataset.k]).toFixed(2); });
  $('#charName').value = C.name || '';
  document.querySelectorAll('#opts .scgrid [data-f]').forEach(t => t.classList.toggle('on', C.bg === 'image' && C.bgImage === t.dataset.f));
  // Realistic 3D: only show what changes a 3D person (their face, hair style and outfit come from the person itself)
  const real = C.style === 'real', made = !!(AVATAR_META[C.avatar] || {}).made;
  const cats = R3.av && R3.av.name === C.avatar ? new Set(R3.av.mats.map(m => m.userData.r3)) : null;   // the person's recolorable parts, once loaded
  const has = (c, guess) => cats ? cats.has(c) : guess;
  const REAL_KEYS = new Set(['style', 'avatar', 'skin', 'mood', 'acc', 'bg', 'bgColor', ...(has('hair', true) || has('brow', true) ? ['hairColor'] : []),
    ...(has('top', !made) ? ['topColor'] : []), ...(has('pants', !made) ? ['pants'] : []), ...(has('shoes', true) ? ['shoes'] : [])]);
  let head = null, any = false;
  for (const el of $('#opts').children) {
    if (el.classList.contains('subhead')) { if (head) head.style.display = any ? '' : 'none'; head = el; any = false; continue; }
    if (el.classList.contains('realnote')) continue;
    const k = (el.querySelector('[data-k]') || {}).dataset?.k, show = !real || REAL_KEYS.has(k);
    if (k !== 'avatar') el.style.display = show ? '' : 'none';
    if (show) any = true;
  }
  if (head) head.style.display = any ? '' : 'none';
  document.querySelectorAll('#opts [data-k="acc"] button').forEach(b => b.style.display = !real || ['glasses', 'shades'].includes(b.dataset.v) ? '' : 'none');
  const note = $('#opts .realnote'); if (note) note.hidden = !real;
  const av = $('#opts [data-k="avatar"]');
  if (av) { const o = av.closest('.opt'); o.style.display = C.style === 'real' ? '' : 'none'; const d = o.querySelector('.pdel'); if (d) d.style.display = (AVATAR_META[C.avatar] || {}).made ? '' : 'none'; }
}
function charChanged() { syncOpts(); saveLocal(); }
const EYEWEAR = ['glasses', 'shades', 'visor', 'eyepatch'];
function randomChar() {
  const c = {name: ''};
  SCHEMA.forEach(([k, label, spec, multi]) => {
    if (!label) return;
    if (typeof spec === 'string') c[k] = pick(PAL[spec].filter(x => x !== 'auto'));
    else if (typeof spec[0] === 'number') c[k] = +(spec[0] + Math.random() * (spec[1] - spec[0])).toFixed(2);
    else if (multi) c[k] = spec.map(s => s[0]).filter(() => Math.random() < 0.16);
    else c[k] = pick(spec)[0];
  });
  c.style = pick(STYLE_POOL); c.lips = 'auto';
  c.bg = pick(['stage', 'sky', 'studio', 'hacker', 'city', 'news', 'space', 'synthwave']);
  if (Math.random() < 0.55) c.hat = 'none';
  if (Math.random() < 0.5) c.prop = 'none';
  const eye = c.acc.filter(a => EYEWEAR.includes(a));
  c.acc = c.acc.filter(a => !EYEWEAR.includes(a)).concat(eye.slice(0, 1));
  if (c.eyes === 'robot' && Math.random() < 0.7) c.eyes = 'round';
  return c;
}

let savedChars = [];
async function loadChars(sel) {
  savedChars = await api('chars').catch(() => []);
  $('#charSel').innerHTML = '<option value="">— saved toons —</option>' + savedChars.map(c => `<option value="${esc(c.file)}">🎭 ${esc(c.name)}</option>`).join('');
  $('#charSel').value = sel || '';
}
$('#charSel').onchange = () => { const c = savedChars.find(x => x.file === $('#charSel').value); if (c) { const {file, ...rest} = c; C = normChar(rest); charChanged(); } };
$('#presetSel').innerHTML = '<option value="">— presets —</option>' + Object.entries(PRESETS).map(([k, p]) => `<option value="${k}">⭐ ${esc(p.name)} (${k})</option>`).join('');
$('#presetSel').onchange = () => { const p = PRESETS[$('#presetSel').value]; if (p) { C = normChar(p); $('#charSel').value = ''; charChanged(); } $('#presetSel').value = ''; };
$('#randBtn').onclick = () => { C = normChar(randomChar()); $('#charSel').value = ''; charChanged(); };
$('#charName').oninput = () => { C.name = $('#charName').value; saveLocal(); };
function charMsg(m, isErr) { const e = $('#charMsg'); e.textContent = m; e.className = isErr ? 'err' : 'hint'; e.hidden = !m; }
$('#saveChar').onclick = async () => {
  C.name = $('#charName').value.trim();
  if (!C.name) { $('#charName').focus(); charMsg('Type a name for your toon first, then hit Save.', true); return; }
  const fd = new FormData(); fd.append('data', JSON.stringify(C));
  try { const r = await api('save_char', fd); await loadChars(r.file); charMsg(`✓ Saved "${C.name}" — pick it from the saved toons list anytime.`); }
  catch (e) { charMsg(e.message, true); }
};
$('#delChar').onclick = async () => {
  const f = $('#charSel').value; if (!f) { charMsg('Pick a saved toon from the list to delete it.', true); return; }
  const fd = new FormData(); fd.append('f', f);
  try { await api('delete_char', fd); await loadChars(''); charMsg('Deleted.'); } catch (e) { charMsg(e.message, true); }
};

</script>
<?php endif; ?>
<?php if ($page === 'toon' || $page === 'game'): ?>
<script>
/* ═══════════════ drawing (shared by Toon Lab and the Game Creator) ═══════════════ */
const INK = '#1b1b1b', MOUTH_DARK = '#4a0f1a', TONGUE = '#e35d7a', GOLD = '#ffd23f';
const BODY = {classic:{w:170, h:190, leg:110, r:44}, chunky:{w:240, h:195, leg:90, r:95}, slim:{w:128, h:205, leg:140, r:34}, bean:{w:200, h:240, leg:70, r:100}};
const HEAD = {round:{w:1, t:1}, oval:{w:0.86, t:1.08}, square:{w:0.95, t:0.98}, egg:{w:0.98, t:1.05}};
let STY = 'classic';   // line look for this frame: classic | anime | neon | sketch | clay | blueprint  (the other styles draw one of these, then post-process)
let LOOK = 'classic';  // the character's art style this frame
const STYLE_POOL = ['classic', 'classic', 'anime', 'anime', 'comic', 'sketch', 'clay', 'ascii', 'matrix', 'pixel', 'neon', 'noir', 'blueprint', 'real'];
function rgb(c) {      // '#rrggbb' | 'rgb(r,g,b)' → [r, g, b]
  c = String(c);
  if (c[0] === '#') { const n = parseInt(c.slice(1), 16) || 0; return [n >> 16, n >> 8 & 255, n & 255]; }
  const m = c.match(/[\d.]+/g) || [0, 0, 0]; return [+m[0], +m[1], +m[2]];
}
function shade(col, amt) { // amt < 0 darkens toward black, > 0 lightens toward white
  const t = amt < 0 ? 0 : 255, p = Math.abs(amt), [r, g, b] = rgb(col).map(c => Math.round(c + (t - c) * p));
  return `rgb(${r},${g},${b})`;
}
function neon(col) {   // glowing line color for a fill: same hue at full brightness; grays glow white/cyan
  const [r, g, b] = rgb(col), mx = Math.max(r, g, b, 1), mn = Math.min(r, g, b);
  if (mx - mn < 30) return mx > 170 ? '#ffffff' : '#00e5ff';
  const k = 255 / mx; return `rgb(${Math.round(r * k)},${Math.round(g * k)},${Math.round(b * k)})`;
}
const inkFor = fill => STY === 'anime' ? shade(fill, -0.6) : STY === 'neon' ? neon(fill) : STY === 'clay' ? shade(fill, -0.32)
  : STY === 'blueprint' ? '#e3f2ff' : STY === 'sketch' ? 'rgba(45,38,32,.9)' : INK;
const fillOf = fill => STY === 'neon' ? shade(fill, -0.85) : STY === 'blueprint' ? 'rgba(150,205,255,.09)' : STY === 'sketch' ? shade(fill, 0.42) : fill;
function glow(g, col) { if (STY === 'neon') { g.shadowColor = col; g.shadowBlur = col ? 14 : 0; } }
function pencil(g, k, w) {   // two broken passes whose gaps shift a few times a second — hand-drawn "boiling" lines
  const t = Math.floor(P_T * 8), u = 6;
  g.save(); g.strokeStyle = k;
  g.setLineDash([u * 7, u * 0.8, u * 3, u * 0.6]); g.lineDashOffset = (t * 13) % 50; g.lineWidth = w * 0.8; g.stroke();
  g.setLineDash([u * 4, u * 1.3, u * 9, u * 0.7]); g.lineDashOffset = (t * 29) % 60 + 7; g.lineWidth = Math.max(1, w * 0.4); g.globalAlpha *= 0.65; g.stroke();
  g.restore();
}
function fillStroke(g, fill, lw = 5) {
  g.fillStyle = fillOf(fill); g.fill();
  if (STY === 'clay') {   // no outlines: a soft dark rim inside the shape makes it look puffy and 3D
    g.save(); g.clip(); g.shadowColor = 'rgba(0,0,0,.55)'; g.shadowBlur = 20; g.lineWidth = 16; g.strokeStyle = 'rgba(0,0,0,.2)'; g.stroke(); g.restore();
    return;
  }
  if (lw) inkStroke(g, fill, lw);
}
function inkStroke(g, col, w) {
  const k = inkFor(col);
  if (STY === 'sketch') return pencil(g, k, w > 20 ? w : w);
  g.lineWidth = w; g.strokeStyle = k; glow(g, k); g.stroke(); glow(g, '');
}
function ell(g, x, y, rx, ry, rot = 0) { g.beginPath(); g.ellipse(x, y, Math.max(0.1, rx), Math.max(0.1, ry), rot, 0, Math.PI * 2); }
function rrect(g, x, y, w, h, r) { g.beginPath(); g.roundRect(x, y, w, h, r); }
const hrand = i => { const x = Math.sin(i * 127.1 + 311.7) * 43758.5453; return x - Math.floor(x); };
function headPath(g, shape, R) {
  g.beginPath();
  if (shape === 'oval') g.ellipse(0, 0, R * 0.86, R * 1.08, 0, 0, Math.PI * 2);
  else if (shape === 'square') g.roundRect(-R * 0.95, -R * 0.98, R * 1.9, R * 1.9, R * 0.32);
  else if (shape === 'egg') {
    g.moveTo(0, -R * 1.05);
    g.bezierCurveTo(R * 0.72, -R * 1.05, R * 0.98, -R * 0.4, R * 0.98, R * 0.2);
    g.bezierCurveTo(R * 0.98, R * 0.78, R * 0.6, R * 0.98, 0, R * 0.98);
    g.bezierCurveTo(-R * 0.6, R * 0.98, -R * 0.98, R * 0.78, -R * 0.98, R * 0.2);
    g.bezierCurveTo(-R * 0.98, -R * 0.4, -R * 0.72, -R * 1.05, 0, -R * 1.05);
    g.closePath();
  } else g.arc(0, 0, R, 0, Math.PI * 2);
}

/* ── backgrounds ── */
const RAIN = '0123456789ABCDEFｦｱｳｴｵｶｷｹｺｻｼｽｾｿﾀﾂﾃﾅﾆﾇﾈﾊﾋﾎﾏﾐﾑﾒﾓﾔﾕﾗﾘﾜ';
function matrixRain(g, W, H, T, alpha = 1) {
  const fs = Math.max(12, Math.round(Math.min(W, H) / 40)), cols = Math.ceil(W / fs);
  g.font = `600 ${fs}px "JetBrains Mono", monospace`; g.textAlign = 'center'; g.textBaseline = 'top';
  for (let c = 0; c < cols; c++) {
    const sp = 3 + hrand(c) * 7, len = 8 + Math.floor(hrand(c + 50) * 14), head = Math.floor((T * sp + hrand(c + 9) * 80) % (H / fs + len + 10));
    for (let k = 0; k < len; k++) {
      const row = head - k; if (row < 0 || row * fs > H) continue;
      const ch = RAIN[Math.floor(hrand(c * 97 + row * 13 + Math.floor(T * 4 + hrand(row + c) * 9)) * RAIN.length)];
      g.fillStyle = k === 0 ? `rgba(220,255,230,${alpha})` : `rgba(0,${180 + 60 * hrand(k)},70,${alpha * (1 - k / len) * 0.85})`;
      g.fillText(ch, c * fs + fs / 2, row * fs);
    }
  }
}
function codeScreen(g, x, y, w, h, T, seed, col = '0,255,140') {   // a monitor full of scrolling code
  g.fillStyle = '#021710'; g.fillRect(x, y, w, h);
  g.save(); g.beginPath(); g.rect(x, y, w, h); g.clip();
  const lh = Math.max(5, h / 16), off = (T * lh * 1.6) % lh;
  for (let i = -1; i < h / lh + 1; i++) {
    const n = i + Math.floor(T * 1.6) + seed * 100, ind = Math.floor(hrand(n) * 4) * w * 0.06;
    g.fillStyle = `rgba(${col},${0.35 + 0.5 * hrand(n + 7)})`;
    g.fillRect(x + w * 0.06 + ind, y + i * lh - off + lh * 0.3, (w * 0.75 - ind) * (0.25 + 0.75 * hrand(n + 3)), lh * 0.45);
  }
  g.restore();
}
function drawBg(g, W, H, C, T) {
  const col = C.bgColor || '#3a86ff', m = Math.min(W, H);
  let gr;
  if (C.bg === 'green') { g.fillStyle = '#00b140'; g.fillRect(0, 0, W, H); return; }
  if (C.bg === 'image') {   // a scene picture, cover-fit with a slow camera drift so it doesn't feel frozen
    const im = sceneImg(C.bgImage);
    g.fillStyle = '#1b1b22'; g.fillRect(0, 0, W, H);
    if (!im) return;
    const z = 1.05 + 0.03 * Math.sin(T * 0.05), k = Math.max(W / im.naturalWidth, H / im.naturalHeight) * z, w = im.naturalWidth * k, h = im.naturalHeight * k;
    g.drawImage(im, (W - w) / 2 + Math.sin(T * 0.037) * (w - W) * 0.35, (H - h) / 2 + Math.cos(T * 0.029) * (h - H) * 0.25, w, h);
    return;
  }
  if (C.bg === 'color') { g.fillStyle = col; g.fillRect(0, 0, W, H); return; }
  if (C.bg === 'studio') {
    gr = g.createRadialGradient(W / 2, H * 0.45, H * 0.05, W / 2, H * 0.45, Math.max(W, H) * 0.75);
    gr.addColorStop(0, shade(col, 0.45)); gr.addColorStop(1, shade(col, -0.35));
    g.fillStyle = gr; g.fillRect(0, 0, W, H); return;
  }
  if (C.bg === 'sky') {
    gr = g.createLinearGradient(0, 0, 0, H); gr.addColorStop(0, '#48b5f0'); gr.addColorStop(0.85, '#cdeefe');
    g.fillStyle = gr; g.fillRect(0, 0, W, H);
    g.fillStyle = '#ffe066'; ell(g, W * 0.86, H * 0.15, H * 0.07, H * 0.07); g.fill();
    g.fillStyle = '#fff';
    for (let i = 0; i < 3; i++) {
      const x = ((i * 0.41 + T * 0.012) % 1.3 - 0.15) * W, y = H * (0.1 + i * 0.09), r = H * 0.04;
      [[0, 0, 1], [r * 1.1, -r * 0.4, 1.25], [r * 2.2, 0, 0.95]].forEach(([dx, dy, k]) => { ell(g, x + dx, y + dy, r * k, r * k * 0.85); g.fill(); });
    }
    g.fillStyle = '#6fcf6f'; g.fillRect(0, H * 0.9, W, H * 0.1);
    g.fillStyle = '#4caf50'; g.fillRect(0, H * 0.9, W, H * 0.012);
    return;
  }
  if (C.bg === 'matrix') { g.fillStyle = '#000'; g.fillRect(0, 0, W, H); matrixRain(g, W, H, T, 0.8); return; }
  if (C.bg === 'hacker') {
    gr = g.createLinearGradient(0, 0, 0, H); gr.addColorStop(0, '#020a07'); gr.addColorStop(1, '#0b1d16');
    g.fillStyle = gr; g.fillRect(0, 0, W, H);
    const sw = Math.min(W * 0.24, H * 0.36), sh = sw * 0.62;
    [[-1.15, 0.2], [0, 0.1], [1.15, 0.2]].forEach(([k, y], i) => {
      const x = W / 2 + k * sw * 1.12 - sw / 2, yy = H * y;
      gr = g.createRadialGradient(x + sw / 2, yy + sh / 2, 0, x + sw / 2, yy + sh / 2, sw);
      gr.addColorStop(0, 'rgba(0,255,140,.16)'); gr.addColorStop(1, 'rgba(0,255,140,0)');
      g.fillStyle = gr; g.fillRect(x - sw / 2, yy - sh / 2, sw * 2, sh * 2);
      g.fillStyle = '#0d0d0d'; g.fillRect(x - 8, yy - 8, sw + 16, sh + 16);
      codeScreen(g, x, yy, sw, sh, T + i * 3.7, i, i === 1 ? '120,200,255' : '0,255,140');
    });
    g.strokeStyle = 'rgba(0,0,0,.6)'; g.lineWidth = 6;
    for (let i = 0; i < 4; i++) { g.beginPath(); g.moveTo(W * (0.15 + i * 0.22), H * 0.55); g.bezierCurveTo(W * (0.2 + i * 0.2), H * 0.8, W * (0.1 + i * 0.25), H * 0.9, W * (0.18 + i * 0.21), H); g.stroke(); }
    gr = g.createRadialGradient(W / 2, H / 2, m * 0.3, W / 2, H / 2, Math.max(W, H) * 0.8);
    gr.addColorStop(0, 'rgba(0,0,0,0)'); gr.addColorStop(1, 'rgba(0,0,0,.7)');
    g.fillStyle = gr; g.fillRect(0, 0, W, H); return;
  }
  if (C.bg === 'city') {
    gr = g.createLinearGradient(0, 0, 0, H); gr.addColorStop(0, '#070b22'); gr.addColorStop(0.7, '#3b1d5e'); gr.addColorStop(1, '#6d2c6b');
    g.fillStyle = gr; g.fillRect(0, 0, W, H);
    for (let i = 0; i < 70; i++) { g.fillStyle = `rgba(255,255,255,${0.3 + 0.5 * hrand(i + T * 0.0001)})`; g.fillRect(hrand(i) * W, hrand(i + 40) * H * 0.5, 2, 2); }
    g.fillStyle = '#fdf6d8'; ell(g, W * 0.82, H * 0.14, m * 0.05, m * 0.05); g.fill();
    for (const [layer, c0, k] of [[0, '#1a1f45', 0.55], [1, '#0e1130', 0.75]]) {
      let x = -20 + layer * 30, i = layer * 100;
      while (x < W) {
        const bw = m * (0.08 + hrand(i) * 0.1), bh = H * (0.18 + hrand(i + 5) * 0.32) * k * 1.4, y = H - bh;
        g.fillStyle = c0; g.fillRect(x, y, bw, bh);
        for (let wy = y + 8; wy < H - 6; wy += 14) for (let wx = x + 6; wx < x + bw - 6; wx += 12)
          if (hrand(wx * 7 + wy * 3 + i) > 0.62) { g.fillStyle = layer ? 'rgba(255,210,110,.85)' : 'rgba(255,210,110,.45)'; g.fillRect(wx, wy, 5, 7); }
        x += bw + 4; i++;
      }
    }
    return;
  }
  if (C.bg === 'news') {
    gr = g.createLinearGradient(0, 0, W, H); gr.addColorStop(0, '#061a4a'); gr.addColorStop(1, shade(col, -0.35));
    g.fillStyle = gr; g.fillRect(0, 0, W, H);
    g.strokeStyle = 'rgba(120,180,255,.18)'; g.lineWidth = 2;
    const cx = W / 2, cy = H * 0.36, r = m * 0.34;
    for (let i = 1; i < 6; i++) { ell(g, cx, cy, r * Math.sin(i / 6 * Math.PI) * Math.abs(Math.cos(T * 0.2)), r); g.stroke(); }
    for (let i = 1; i < 6; i++) { const y = cy - r + (2 * r * i) / 6, w = Math.sqrt(r * r - (y - cy) ** 2); g.beginPath(); g.moveTo(cx - w, y); g.lineTo(cx + w, y); g.stroke(); }
    ell(g, cx, cy, r, r); g.stroke();
    for (let i = 0; i < 5; i++) { g.fillStyle = `rgba(255,255,255,${0.04 + 0.03 * i})`; g.fillRect(((i * 0.23 + T * 0.03) % 1.2 - 0.1) * W, 0, W * 0.04, H); }
    const ty = H * 0.935, th = H * 0.065;
    g.fillStyle = '#0b0b0b'; g.fillRect(0, ty, W, th);
    g.font = `700 ${th * 0.5}px "Archivo Black", sans-serif`; g.textBaseline = 'middle'; g.textAlign = 'left';
    const msg = `${(C.name || 'TOON').toUpperCase()} NEWS NETWORK  •  BREAKING  •  LIVE  •  `, tw = g.measureText(msg).width;
    g.fillStyle = '#fff';
    for (let x = -((T * 90) % tw); x < W; x += tw) g.fillText(msg, x + th * 2.4, ty + th / 2);
    g.fillStyle = '#d00000'; g.fillRect(0, ty, th * 2.2, th);
    g.fillStyle = '#fff'; g.textAlign = 'center'; g.fillText('LIVE', th * 1.1, ty + th / 2);
    return;
  }
  if (C.bg === 'space') {
    gr = g.createLinearGradient(0, 0, 0, H); gr.addColorStop(0, '#03010a'); gr.addColorStop(1, '#1a0b3a');
    g.fillStyle = gr; g.fillRect(0, 0, W, H);
    gr = g.createRadialGradient(W * 0.25, H * 0.3, 0, W * 0.25, H * 0.3, m * 0.6);
    gr.addColorStop(0, 'rgba(160,60,255,.25)'); gr.addColorStop(1, 'rgba(160,60,255,0)'); g.fillStyle = gr; g.fillRect(0, 0, W, H);
    for (let i = 0; i < 140; i++) { const a = 0.3 + 0.7 * Math.abs(Math.sin(T * (0.5 + hrand(i + 3)) + i)); g.fillStyle = `rgba(255,255,255,${a})`; const s = hrand(i + 9) < 0.1 ? 3 : 1.6; g.fillRect(hrand(i) * W, hrand(i + 70) * H, s, s); }
    const px = W * 0.8, py = H * 0.24, pr = m * 0.11;
    gr = g.createRadialGradient(px - pr * 0.4, py - pr * 0.4, pr * 0.1, px, py, pr);
    gr.addColorStop(0, '#ffb86b'); gr.addColorStop(1, '#a23b72'); g.fillStyle = gr; ell(g, px, py, pr, pr); g.fill();
    g.strokeStyle = 'rgba(255,220,180,.7)'; g.lineWidth = pr * 0.08; ell(g, px, py, pr * 1.7, pr * 0.35, -0.3); g.stroke();
    return;
  }
  if (C.bg === 'synthwave') {
    const hz = H * 0.62;
    gr = g.createLinearGradient(0, 0, 0, hz); gr.addColorStop(0, '#12002b'); gr.addColorStop(0.6, '#5b0e6b'); gr.addColorStop(1, '#ff4f9a');
    g.fillStyle = gr; g.fillRect(0, 0, W, hz);
    const sr = m * 0.22;
    gr = g.createLinearGradient(0, hz - sr * 1.2, 0, hz); gr.addColorStop(0, '#ffe45e'); gr.addColorStop(1, '#ff3c8e');
    g.fillStyle = gr; ell(g, W / 2, hz - sr * 0.15, sr, sr); g.fill();
    g.fillStyle = '#5b0e6b';
    for (let i = 0; i < 6; i++) { const y = hz - sr * 0.15 + i * sr * 0.16; g.fillRect(W / 2 - sr, y, sr * 2, 2 + i * 2.2); }
    g.fillStyle = '#0d0018'; g.fillRect(0, hz, W, H - hz);
    g.strokeStyle = '#ff2fd0'; g.lineWidth = 2; g.shadowColor = '#ff2fd0'; g.shadowBlur = 8;
    for (let i = 0; i < 14; i++) { const z = ((i + (T * 0.8) % 1) / 14), y = hz + (H - hz) * z * z; g.beginPath(); g.moveTo(0, y); g.lineTo(W, y); g.stroke(); }
    for (let i = -12; i <= 12; i++) { g.beginPath(); g.moveTo(W / 2 + i * W * 0.02, hz); g.lineTo(W / 2 + i * W * 0.16, H); g.stroke(); }
    g.shadowBlur = 0;
    return;
  }
  // stage: back wall, wood floor, spotlight, curtains
  gr = g.createLinearGradient(0, 0, 0, H); gr.addColorStop(0, '#1b0b24'); gr.addColorStop(1, '#4a1636');
  g.fillStyle = gr; g.fillRect(0, 0, W, H);
  const fy = H * 0.86;
  gr = g.createLinearGradient(0, fy, 0, H); gr.addColorStop(0, '#8a4b2a'); gr.addColorStop(1, '#4e2914');
  g.fillStyle = gr; g.fillRect(0, fy, W, H - fy);
  g.strokeStyle = 'rgba(0,0,0,.25)'; g.lineWidth = 2;
  for (let i = 1; i < 4; i++) { const y = fy + (H - fy) * i / 4; g.beginPath(); g.moveTo(0, y); g.lineTo(W, y); g.stroke(); }
  gr = g.createRadialGradient(W / 2, H * 0.9, 10, W / 2, H * 0.9, H * 0.8);
  gr.addColorStop(0, 'rgba(255,240,200,.45)'); gr.addColorStop(1, 'rgba(255,240,200,0)');
  g.fillStyle = gr; g.fillRect(0, 0, W, H);
  const cw = m * 0.16;
  for (const x0 of [0, W - cw]) {
    gr = g.createLinearGradient(x0, 0, x0 + cw, 0);
    for (let i = 0; i <= 6; i++) gr.addColorStop(i / 6, i % 2 ? '#7a0010' : '#c1121f');
    g.fillStyle = gr; g.fillRect(x0, 0, cw, H);
  }
  g.fillStyle = '#9d0208'; g.fillRect(0, 0, W, H * 0.06);
  for (let x = 0; x < W; x += H * 0.08) { ell(g, x + H * 0.04, H * 0.06, H * 0.04, H * 0.025); g.fill(); }
}

/* ── the scene: art style + camera ── */
let CAMERA = 'full';
const CAM_ZOOM = {full: 1, medium: 1.55, close: 2.3};
function toonScale(W, H) { return Math.min(H / 840, W / 560); }
function headRest(W, H) {   // head center on the canvas at rest (camera not applied)
  const s = toonScale(W, H), B = BODY[C.body] || BODY.classic, R = 112 * C.headSize;
  return {x: W / 2, y: H * 0.93 - s * (B.leg + B.h + R * 0.78), r: R * s};
}
function camXf(W, H) {
  const z = CAM_ZOOM[CAMERA] || 1;
  if (z === 1) return [1, 0, 0];
  const hp = headRest(W, H), ty = H * (CAMERA === 'close' ? 0.46 : 0.36);
  return [z, W / 2 - hp.x * z, ty - hp.y * z];
}
function headAnchor(W, H) {   // where the head sits on the canvas, camera included (speech bubbles, ASCII lighting)
  if (C.style === 'real' && typeof R3 !== 'undefined' && R3.head) return R3.head;
  const hp = headRest(W, H), [z, dx, dy] = camXf(W, H);
  return {x: hp.x * z + dx, y: hp.y * z + dy, r: hp.r * z};
}
function drawToonCam(g, W, H, P) {
  const [z, dx, dy] = camXf(W, H);
  g.save(); g.translate(dx, dy); g.scale(z, z); drawToon(g, W, H, C, P); g.restore();
}
function drawScene(g, W, H, T, P) {
  const st = LOOK = C.style || 'classic', m = Math.min(W, H), green = C.bg === 'green';
  if (st === 'ascii' || st === 'matrix') { drawAscii(g, W, H, T, P, st === 'matrix'); LOOK = 'classic'; return; }
  if (st === 'pixel') { drawPixel(g, W, H, T, P); LOOK = 'classic'; return; }
  if (st === 'real' && typeof drawReal === 'function') { STY = 'classic'; drawReal(g, W, H, T, P); LOOK = 'classic'; return; }
  if (st === 'noir') {   // black & white film: draw, desaturate + punch the contrast, vignette, grain, flicker
    STY = 'classic';
    const L = offCv('lnoir', W, H);
    L.ctx.setTransform(1, 0, 0, 1, 0, 0); drawBg(L.ctx, W, H, C, T); drawToonCam(L.ctx, W, H, P);
    if ('filter' in g) { g.save(); g.filter = 'grayscale(1) contrast(1.5) brightness(0.9)'; g.drawImage(L, 0, 0); g.restore(); }
    else {
      const img = L.ctx.getImageData(0, 0, W, H), d = img.data;
      for (let i = 0; i < d.length; i += 4) { const v = Math.max(0, Math.min(255, ((d[i] * 0.3 + d[i + 1] * 0.59 + d[i + 2] * 0.11) - 128) * 1.5 + 115)); d[i] = d[i + 1] = d[i + 2] = v; }
      g.putImageData(img, 0, 0);
    }
    vignette(g, W, H, 0.75); grain(g, W, H, 0.09);
    g.fillStyle = `rgba(0,0,0,${0.04 + 0.04 * hrand(Math.floor(T * 12))})`; g.fillRect(0, 0, W, H);
    LOOK = 'classic'; return;
  }
  STY = ['anime', 'neon', 'sketch', 'clay', 'blueprint'].includes(st) ? st : 'classic';
  if (st === 'blueprint' && !green) blueprintBg(g, W, H, T); else drawBg(g, W, H, C, T);
  if (STY === 'neon' && !green) { g.fillStyle = 'rgba(6,0,20,.62)'; g.fillRect(0, 0, W, H); }
  if (STY === 'sketch' && !green) { g.fillStyle = 'rgba(250,245,232,.62)'; g.fillRect(0, 0, W, H); grain(g, W, H, 0.05); }
  if (st === 'clay') {   // a soft key light from the upper left makes the puffy shapes read as a vinyl toy
    const L = offCv('lclay', W, H), x = L.ctx;
    x.setTransform(1, 0, 0, 1, 0, 0); x.clearRect(0, 0, W, H); drawToonCam(x, W, H, P);
    const hp = headAnchor(W, H), gr = x.createRadialGradient(hp.x - hp.r * 0.6, hp.y - hp.r * 0.7, hp.r * 0.1, hp.x, hp.y + hp.r, hp.r * 4.5);
    gr.addColorStop(0, 'rgba(255,255,255,.38)'); gr.addColorStop(0.35, 'rgba(255,255,255,0)'); gr.addColorStop(1, 'rgba(0,0,0,.4)');
    x.globalCompositeOperation = 'source-atop'; x.fillStyle = gr; x.fillRect(0, 0, W, H); x.globalCompositeOperation = 'source-over';
    g.save(); g.shadowColor = 'rgba(0,0,0,.45)'; g.shadowBlur = m * 0.03; g.shadowOffsetX = m * 0.012; g.shadowOffsetY = m * 0.018; g.drawImage(L, 0, 0); g.restore();
  } else if (st === 'comic') {   // halftone dots on the background, the toon gets halftone shading on its shadow side, inside a panel border
    if (!green) { g.save(); g.globalAlpha = 0.16; g.fillStyle = halftone(g); g.fillRect(0, 0, W, H); g.restore(); }
    const L = offCv('lcomic', W, H), x = L.ctx;
    x.setTransform(1, 0, 0, 1, 0, 0); x.clearRect(0, 0, W, H); drawToonCam(x, W, H, P);
    const hp = headAnchor(W, H), gr = x.createLinearGradient(hp.x - hp.r, hp.y - hp.r, hp.x + hp.r * 2.5, hp.y + hp.r * 4);
    gr.addColorStop(0, 'rgba(0,0,0,0)'); gr.addColorStop(0.5, 'rgba(0,0,0,0)'); gr.addColorStop(0.51, 'rgba(0,0,0,.14)'); gr.addColorStop(1, 'rgba(0,0,0,.22)');
    x.globalCompositeOperation = 'source-atop'; x.fillStyle = gr; x.fillRect(0, 0, W, H);
    x.globalAlpha = 0.18; x.fillStyle = halftone(x); x.fillRect(0, 0, W, H); x.globalAlpha = 1; x.globalCompositeOperation = 'source-over';
    g.drawImage(L, 0, 0);
    g.lineWidth = m * 0.022; g.strokeStyle = '#111'; g.strokeRect(m * 0.025, m * 0.025, W - m * 0.05, H - m * 0.05);
  } else drawToonCam(g, W, H, P);
  STY = 'classic'; LOOK = 'classic';
}
let HALF = null, NOISE = null;
function halftone(g) {
  if (!HALF) { const c = document.createElement('canvas'); c.width = c.height = 9; const x = c.getContext('2d'); x.fillStyle = '#000'; x.beginPath(); x.arc(4.5, 4.5, 2.3, 0, Math.PI * 2); x.fill(); HALF = c; }
  return g.createPattern(HALF, 'repeat');
}
function grain(g, W, H, a) {   // fresh film grain every frame
  if (!NOISE) { NOISE = document.createElement('canvas'); NOISE.width = NOISE.height = 128; }
  const x = NOISE.getContext('2d'), img = x.createImageData(128, 128), d = img.data;
  for (let i = 0; i < d.length; i += 4) { const v = Math.random() * 255; d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255; }
  x.putImageData(img, 0, 0);
  g.save(); g.globalAlpha = a; g.globalCompositeOperation = 'overlay'; g.fillStyle = g.createPattern(NOISE, 'repeat'); g.fillRect(0, 0, W, H); g.restore();
}
function vignette(g, W, H, a) {
  const gr = g.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.3, W / 2, H / 2, Math.max(W, H) * 0.75);
  gr.addColorStop(0, 'rgba(0,0,0,0)'); gr.addColorStop(1, `rgba(0,0,0,${a})`); g.fillStyle = gr; g.fillRect(0, 0, W, H);
}
function blueprintBg(g, W, H, T) {
  g.fillStyle = '#0d3b7a'; g.fillRect(0, 0, W, H);
  const step = Math.min(W, H) / 24;
  g.lineWidth = 1; g.strokeStyle = 'rgba(200,230,255,.13)';
  for (let x = 0; x < W; x += step) { g.beginPath(); g.moveTo(x, 0); g.lineTo(x, H); g.stroke(); }
  for (let y = 0; y < H; y += step) { g.beginPath(); g.moveTo(0, y); g.lineTo(W, y); g.stroke(); }
  g.strokeStyle = 'rgba(200,230,255,.28)';
  for (let x = 0; x < W; x += step * 4) { g.beginPath(); g.moveTo(x, 0); g.lineTo(x, H); g.stroke(); }
  for (let y = 0; y < H; y += step * 4) { g.beginPath(); g.moveTo(0, y); g.lineTo(W, y); g.stroke(); }
  const bw = step * 7, bh = step * 2.2, x0 = W - bw - step, y0 = H - bh - step;
  g.lineWidth = 2; g.strokeStyle = 'rgba(227,242,255,.8)'; g.strokeRect(x0, y0, bw, bh);
  g.fillStyle = 'rgba(227,242,255,.85)'; g.font = `600 ${step * 0.55}px "JetBrains Mono", monospace`; g.textAlign = 'left'; g.textBaseline = 'middle';
  g.fillText(`${(C.name || 'TOON').toUpperCase()}  ·  MK-${String(Math.floor(T) % 100).padStart(2, '0')}`, x0 + step * 0.4, y0 + bh * 0.33);
  g.fillText('SCALE 1:1   REV A', x0 + step * 0.4, y0 + bh * 0.72);
}

// offscreen canvases for the post-processed styles
const OFF = {};
function offCv(name, w, h) {
  let c = OFF[name];
  if (!c) { c = OFF[name] = document.createElement('canvas'); c.ctx = c.getContext('2d', {willReadFrequently: name.startsWith('s')}); }
  if (c.width !== w || c.height !== h) { c.width = w; c.height = h; }
  return c;
}
function drawPixel(g, W, H, T, P) {   // 8-bit: draw small, snap colors to a few levels, blow up without smoothing
  const k = 6, w = Math.ceil(W / k), h = Math.ceil(H / k), cv = offCv('spx', w, h), x = cv.ctx;
  STY = 'classic';
  x.setTransform(1 / k, 0, 0, 1 / k, 0, 0); drawBg(x, W, H, C, T); drawToonCam(x, W, H, P);
  x.setTransform(1, 0, 0, 1, 0, 0);
  const img = x.getImageData(0, 0, w, h), d = img.data, q = v => Math.round(v / 51) * 51;
  for (let i = 0; i < d.length; i += 4) { d[i] = q(d[i]); d[i + 1] = q(d[i + 1]); d[i + 2] = q(d[i + 2]); }
  x.putImageData(img, 0, 0);
  g.imageSmoothingEnabled = false; g.drawImage(cv, 0, 0, w * k, h * k); g.imageSmoothingEnabled = true;
}
const RAMP = ' .:-=+*#%@', BGRAMP = ' .,:;~=+';
let ATLAS = null;
function glyphAtlas(cw, ch) {   // every ramp character, white, one cell each
  const key = cw + 'x' + ch, chars = RAMP + BGRAMP;
  if (ATLAS && ATLAS.key === key) return ATLAS;
  const c = document.createElement('canvas'); c.width = cw * chars.length; c.height = ch;
  const x = c.getContext('2d'); x.fillStyle = '#fff'; x.font = `700 ${Math.round(ch * 0.82)}px "JetBrains Mono", ui-monospace, monospace`;
  x.textAlign = 'center'; x.textBaseline = 'middle';
  [...chars].forEach((t, i) => x.fillText(t, i * cw + cw / 2, ch / 2 + 1));
  return ATLAS = {key, c, n: RAMP.length};
}
function drawAscii(g, W, H, T, P, matrix) {
  // 1. render the scene at one pixel per character cell (2× then halved, for a little averaging)
  const cw = Math.max(7, Math.round(Math.min(W, H) / 80)), ch = Math.round(cw * 1.8), cols = Math.ceil(W / cw), rows = Math.ceil(H / ch);
  const big = offCv('a2', cols * 2, rows * 2), bx = big.ctx, sx = cols * 2 / W, sy = rows * 2 / H;
  const sb = offCv('sbg', cols, rows), st = offCv('stoon', cols, rows);
  STY = 'classic';
  sb.ctx.clearRect(0, 0, cols, rows);
  if (!matrix) { bx.setTransform(sx, 0, 0, sy, 0, 0); drawBg(bx, W, H, C, T); sb.ctx.drawImage(big, 0, 0, cols, rows); }
  // the toon slowly turns (squash in x) — the extrusion below follows the turn, so it reads as 3D
  const turn = Math.sin(T * 0.55) * 0.5;
  bx.setTransform(1, 0, 0, 1, 0, 0); bx.clearRect(0, 0, big.width, big.height);
  bx.setTransform(sx, 0, 0, sy, 0, 0);
  bx.save(); bx.translate(W / 2, 0); bx.scale(0.8 + 0.2 * Math.cos(turn * 2), 1); bx.translate(-W / 2, 0); drawToonCam(bx, W, H, P); bx.restore();
  // light from the upper left, shade to the lower right → depth shows up in the character ramp
  const hp = headAnchor(W, H), lg = bx.createRadialGradient(hp.x - hp.r * 0.8, hp.y - hp.r * 0.9, hp.r * 0.2, hp.x, hp.y + hp.r * 2, hp.r * 5);
  lg.addColorStop(0, 'rgba(255,255,255,.45)'); lg.addColorStop(0.45, 'rgba(255,255,255,0)'); lg.addColorStop(1, 'rgba(0,0,0,.6)');
  bx.globalCompositeOperation = 'source-atop'; bx.fillStyle = lg; bx.fillRect(0, 0, W, H); bx.globalCompositeOperation = 'source-over';
  bx.setTransform(1, 0, 0, 1, 0, 0);
  st.ctx.clearRect(0, 0, cols, rows); st.ctx.drawImage(big, 0, 0, cols, rows);
  const db = sb.ctx.getImageData(0, 0, cols, rows).data, dt = st.ctx.getImageData(0, 0, cols, rows).data;
  // 2. stamp white glyphs per cell, then color them with the cell colors (source-in, no smoothing)
  const A = glyphAtlas(cw, ch), Gt = offCv('gt', W, H), Gb = offCv('gb', W, H), ct = offCv('ct', cols, rows), cb = offCv('cb', cols, rows);
  Gt.ctx.clearRect(0, 0, W, H); Gb.ctx.clearRect(0, 0, W, H);
  const it = ct.ctx.createImageData(cols, rows), ib = cb.ctx.createImageData(cols, rows);
  for (let r = 0, i = 0; r < rows; r++) for (let c = 0; c < cols; c++, i += 4) {
    const a = dt[i + 3] / 255;
    if (a > 0.3) {
      // lift dark colors (black hoodies!) so every part of the toon reads, keeping its hue
      const lum = (dt[i] * 0.3 + dt[i + 1] * 0.59 + dt[i + 2] * 0.11) / 255, L = 0.38 + 0.62 * lum, f = L / Math.max(lum, 0.04);
      const k = 1 + Math.min(A.n - 2, Math.floor(L * (A.n - 1)));
      Gt.ctx.drawImage(A.c, k * cw, 0, cw, ch, c * cw, r * ch, cw, ch);
      if (matrix) { it.data[i] = 30 + L * 110; it.data[i + 1] = 110 + L * 145; it.data[i + 2] = 50 + L * 100; }
      else if (lum < 0.04) { it.data[i] = it.data[i + 1] = it.data[i + 2] = L * 255; }
      else { it.data[i] = Math.min(255, dt[i] * f); it.data[i + 1] = Math.min(255, dt[i + 1] * f); it.data[i + 2] = Math.min(255, dt[i + 2] * f); }
      it.data[i + 3] = 255;
    } else if (!matrix) {
      const lum = (db[i] * 0.3 + db[i + 1] * 0.59 + db[i + 2] * 0.11) / 255, L = Math.min(1, lum * 1.6), k = Math.floor(L * (BGRAMP.length - 0.01));
      if (k > 0) {
        const f = Math.min(3, 0.55 / Math.max(lum, 0.05));   // background stays dimmer than the toon
        Gb.ctx.drawImage(A.c, (A.n + k) * cw, 0, cw, ch, c * cw, r * ch, cw, ch);
        ib.data[i] = Math.min(200, db[i] * f); ib.data[i + 1] = Math.min(200, db[i + 1] * f); ib.data[i + 2] = Math.min(200, db[i + 2] * f); ib.data[i + 3] = 255;
      }
    }
  }
  ct.ctx.putImageData(it, 0, 0); cb.ctx.putImageData(ib, 0, 0);
  for (const [G, Cc] of [[Gt, ct], [Gb, cb]]) {
    G.ctx.globalCompositeOperation = 'source-in'; G.ctx.imageSmoothingEnabled = false;
    G.ctx.drawImage(Cc, 0, 0, W, H);
    G.ctx.globalCompositeOperation = 'source-over'; G.ctx.imageSmoothingEnabled = true;
  }
  // 3. compose: dark bg, bg glyphs, extruded toon glyphs (darkened copies stepping back), glowing toon glyphs, scanlines
  g.fillStyle = matrix ? '#000' : '#05060a'; g.fillRect(0, 0, W, H);
  if (matrix) matrixRain(g, W, H, T, 0.55); else g.drawImage(Gb, 0, 0);
  const D = offCv('gd', W, H);
  D.ctx.clearRect(0, 0, W, H); D.ctx.drawImage(Gt, 0, 0);
  D.ctx.globalCompositeOperation = 'source-atop'; D.ctx.fillStyle = 'rgba(0,0,0,.72)'; D.ctx.fillRect(0, 0, W, H); D.ctx.globalCompositeOperation = 'source-over';
  const depth = Math.max(3, Math.round(cw * 0.5));
  for (let k = depth; k >= 1; k--) g.drawImage(D, Math.round(k * (0.9 - turn * 1.2)), Math.round(k * 0.9));
  g.save(); g.shadowColor = matrix ? 'rgba(0,255,120,.8)' : 'rgba(255,255,255,.35)'; g.shadowBlur = cw * 0.9; g.drawImage(Gt, 0, 0); g.restore();
  g.fillStyle = 'rgba(0,0,0,.18)'; for (let y = 0; y < H; y += 3) g.fillRect(0, y, W, 1);
}

/* ── body ── */
const UA = 78, FA = 72;   // upper arm, forearm
function ik(sd, x, y, tx, ty) {   // shoulder (x, y) → shoulder/elbow angles that put the hand at (tx, ty); picks the lower elbow
  let dx = (tx - x) * sd, dy = ty - y, d = Math.hypot(dx, dy) || 1;
  const mx = UA + FA - 1, mn = Math.abs(UA - FA) + 4;
  if (d > mx) { dx *= mx / d; dy *= mx / d; d = mx; } else if (d < mn) { dx *= mn / d; dy *= mn / d; d = mn; }
  const phi = Math.atan2(dx, dy), al = Math.acos(Math.max(-1, Math.min(1, (UA * UA + d * d - FA * FA) / (2 * UA * d))));
  const sh = Math.cos(phi + al) > Math.cos(phi - al) ? phi + al : phi - al;
  const ex = Math.sin(sh) * UA, ey = Math.cos(sh) * UA;
  return {sh, el: Math.atan2(dx - ex, dy - ey) - sh};
}
const lerpPose = (a, b, w) => ({sh: a.sh + (b.sh - a.sh) * w, el: a.el + (b.el - a.el) * w});
const sleeveOf = C => C.top === 'labcoat' ? '#f4f4f4' : C.topColor;
const LONG_SLEEVE = new Set(['hoodie', 'suit', 'jacket', 'labcoat', 'robe']);

// props: where hands go (hold), what they hold (item), and desks (drawn in two layers around the arms)
const typing = (T, sd) => Math.sin(T * 17 + sd) * 3;
const PROPS = {
  mic:      {lock: 'R', hold: (sd, k) => sd > 0 && {x: k.R * 0.42, y: k.mouthY + k.R * 0.42}},
  coffee:   {hold: (sd, k) => sd < 0 && {x: -k.bw * 0.2, y: k.top + 92 + Math.sin(k.T * 1.3) * 3}},
  phone:    {hold: (sd, k) => sd > 0 && {x: k.bw * 0.08, y: k.top + 72 + Math.sin(k.T * 2) * 2}},
  guitar:   {hold: (sd, k) => sd > 0 ? {x: 26, y: k.top + k.B.h * 0.66 + Math.sin(k.T * 16) * 9 * k.env} : {x: -k.bw * 0.75 - 40, y: k.top + 34}},
  gamepad:  {hold: (sd, k) => ({x: sd * 40, y: k.top + 112 + typing(k.T, sd) * 0.7})},
  computer: {desk: true, hold: (sd, k) => ({ground: true, x: sd * 62 + typing(k.T, sd), y: k.deskY - 6 + typing(k.T * 1.3, -sd)})},
  laptop:   {desk: true, hold: (sd, k) => ({ground: true, x: sd * 58 + typing(k.T, sd), y: k.deskY - 6 + typing(k.T * 1.3, -sd)})},
  newsdesk: {desk: true, hold: (sd, k) => ({ground: true, x: sd * 92, y: k.deskY - 4})},
};
function drawToon(g, W, H, C, P) {
  const s = toonScale(W, H), lw = {anime: 2.6, neon: 3.5, blueprint: 2.4, sketch: 4, clay: 3}[STY] || (LOOK === 'comic' ? 7 : 5);
  const B = BODY[C.body] || BODY.classic, bw = B.w * C.bodyWidth, R = 112 * C.headSize, T = P.T || 0, prop = PROPS[C.prop];
  const acc = new Set(C.acc || []);
  P_T = T;
  g.save();
  g.translate(W / 2 + Math.max(-W * 0.42, Math.min(W * 0.42, (P.dx || 0) * s)), H * 0.93);   // the camera can walk the character around
  g.scale(s, s);
  g.lineCap = 'round'; g.lineJoin = 'round';
  g.fillStyle = 'rgba(0,0,0,.25)'; ell(g, 0, 4, bw * 0.75, 16); g.fill();
  // legs + shoes (a tracked leg bends at the knee; the lower foot stays on the floor)
  let hipY = -B.leg + P.bob;
  const legX = bw * 0.22, LG = (B.leg - 16) / 2, legs = P.legs || {};
  const legPts = sd => {
    const l = legs[sd < 0 ? 'L' : 'R'];
    if (!l) return null;
    const kx = Math.sin(l.hip) * LG * sd, ky = Math.cos(l.hip) * LG, fa = l.hip + l.knee;
    return [kx, ky, kx + Math.sin(fa) * LG * sd, ky + Math.cos(fa) * LG];
  };
  const LP = {'-1': legPts(-1), 1: legPts(1)};
  if (LP[-1] || LP[1]) hipY = -16 - Math.max(...[-1, 1].map(sd => LP[sd] ? LP[sd][3] : B.leg - 16));
  for (const sd of [-1, 1]) {
    const hx = sd * legX, lp = LP[sd], fx = lp ? hx + lp[2] : sd * (legX + 4), fy = lp ? hipY + lp[3] : -16;
    g.beginPath(); g.moveTo(hx, hipY); if (lp) g.lineTo(hx + lp[0], hipY + lp[1]); g.lineTo(fx, fy);
    inkStroke(g, C.pants, 46 + lw * 2);
    g.lineWidth = 46; g.strokeStyle = fillOf(C.pants); g.stroke();
    ell(g, fx + sd * 10, fy + 6, 38, 18); fillStroke(g, C.shoes, lw);
  }
  // torso (everything above the hips leans/squashes together)
  const tf = {y: hipY, a: P.lean, sx: 1 + (1 - P.squash) * 0.5, sy: P.squash};
  const torso = () => { g.translate(0, tf.y); g.rotate(tf.a); g.scale(tf.sx, tf.sy); };
  const toTorso = (x, y) => { const dy = y - tf.y, c = Math.cos(-tf.a), sn = Math.sin(-tf.a); return [(x * c - dy * sn) / tf.sx, (x * sn + dy * c) / tf.sy]; };
  const top = -B.h, headY = top - R * 0.78 + P.headY;
  g.save(); torso();
  drawTorso(g, C, B, bw, R, top, lw, acc);
  if (C.prop === 'guitar') drawGuitar(g, top + B.h * 0.66, bw, lw, C);
  g.save(); g.translate((P.yaw || 0) * R * 0.18, headY); g.rotate(P.tilt); drawHead(g, C, R, P, lw, acc); g.restore();
  g.restore();
  const deskY = Math.min(-B.leg - 18, -B.leg - B.h + 150);
  if (prop && prop.desk) drawDesk(g, C, 'back', deskY, T, lw);
  // arms last so gestures sit in front of everything
  g.save(); torso();
  const shX = bw / 2 - 16, shY = top + 26 - P.shrug;
  const k = {R, bw, B, top, mouthY: headY + R * 0.47, T, env: P.env || 0, deskY};
  for (const sd of [-1, 1]) {
    const a = sd < 0 ? P.armL : P.armR, hold = prop && prop.hold(sd, k);
    let pose = a.rest;
    if (hold) { const [hx, hy] = hold.ground ? toTorso(hold.x, hold.y) : [hold.x, hold.y]; pose = ik(sd, sd * shX, shY, hx, hy); }
    if (a.g && !(prop && prop.lock === (sd < 0 ? 'L' : 'R'))) pose = lerpPose(pose, a.g, a.w);
    drawArm(g, C, sd, sd * shX, shY, pose, lw, acc, top);
  }
  g.restore();
  if (prop && prop.desk) drawDesk(g, C, 'front', deskY, T, lw);
  g.restore();
}

function drawTorso(g, C, B, bw, R, top, lw, acc) {
  const tc = C.topColor, shape = (h = B.h + 12) => rrect(g, -bw / 2, top, bw, h, [B.r, B.r, 26, 26]);
  if (C.top === 'hoodie' && C.hat !== 'hood') { rrect(g, -R * 0.8, top - R * 0.5, R * 1.6, R * 0.85, R * 0.4); fillStroke(g, shade(tc, -0.18), lw); }
  if (C.top === 'dress') {   // skirt flares out over the legs
    g.beginPath(); g.moveTo(-bw * 0.45, top + B.h * 0.55); g.lineTo(bw * 0.45, top + B.h * 0.55); g.lineTo(bw * 0.78, top + B.h + B.leg * 0.6); g.quadraticCurveTo(0, top + B.h + B.leg * 0.72, -bw * 0.78, top + B.h + B.leg * 0.6); g.closePath();
    fillStroke(g, shade(tc, -0.08), lw);
  }
  rrect(g, -R * 0.17, top - R * 0.4, R * 0.34, R * 0.55, 8); fillStroke(g, C.skin, lw);
  if (STY === 'anime') { rrect(g, -R * 0.17, top - R * 0.4, R * 0.34, R * 0.22, 8); g.fillStyle = 'rgba(80,30,40,.22)'; g.fill(); }
  const body = C.top === 'labcoat' ? '#f4f4f4' : tc;
  if (C.top === 'robe') { rrect(g, -bw / 2, top, bw, B.h + B.leg - 6, [B.r, B.r, 18, 18]); fillStroke(g, tc, lw); }
  else if (C.top === 'labcoat') { shape(B.h + 64); fillStroke(g, body, lw); }
  else { shape(); fillStroke(g, body, lw); }
  if (STY === 'anime') {   // cel shading: the side away from the light
    g.save(); C.top === 'robe' ? rrect(g, -bw / 2, top, bw, B.h + B.leg - 6, [B.r, B.r, 18, 18]) : shape(C.top === 'labcoat' ? B.h + 64 : B.h + 12); g.clip();
    g.fillStyle = 'rgba(40,20,60,.16)'; g.fillRect(bw * 0.18, top - 10, bw, B.h + B.leg); g.restore();
  }
  const neckOpen = (w, h) => { ell(g, 0, top + 2, w, h); fillStroke(g, C.skin, lw); rrect(g, -bw / 2 + 4, top - 4, bw - 8, 6, 3); g.fillStyle = fillOf(body); g.fill(); };
  if (C.top === 'tee' || C.top === 'tank' || C.top === 'jersey') {
    neckOpen(C.top === 'tank' ? bw * 0.26 : 30, C.top === 'tank' ? 32 : 17);
    if (C.top === 'jersey') {
      const n = String(10 + Math.floor(hrand((C.name || 'x').length * 7) * 89));
      g.font = `900 ${B.h * 0.38}px "Archivo Black", sans-serif`; g.textAlign = 'center'; g.textBaseline = 'middle';
      const lum = rgb(tc).reduce((s, v, i) => s + v * [0.3, 0.59, 0.11][i], 0) / 255;
      g.lineWidth = 6; g.strokeStyle = inkFor(tc); g.strokeText(n, 0, top + B.h * 0.52);
      g.fillStyle = STY === 'neon' ? neon(tc) : lum > 0.6 ? '#111' : '#fff'; g.fillText(n, 0, top + B.h * 0.52);
      for (const sd of [-1, 1]) { g.fillStyle = fillOf(lum > 0.6 ? '#111' : '#fff'); g.fillRect(sd > 0 ? bw / 2 - 16 : -bw / 2 + 6, top + 30, 10, B.h - 30); }
    }
  } else if (C.top === 'hoodie') {
    rrect(g, -bw * 0.3, top + B.h * 0.55, bw * 0.6, B.h * 0.28, 14); fillStroke(g, shade(tc, -0.1), lw);
    if (C.hat !== 'hood') {
      g.strokeStyle = STY === 'neon' ? neon('#fff') : '#fff'; g.lineWidth = 4;
      for (const sd of [-1, 1]) { g.beginPath(); g.moveTo(sd * 14, top + 6); g.lineTo(sd * 16, top + 58); g.stroke(); ell(g, sd * 16, top + 62, 5, 5); g.fillStyle = g.strokeStyle; g.fill(); }
    }
  } else if (C.top === 'suit' || C.top === 'jacket' || C.top === 'labcoat') {
    const inner = C.top === 'labcoat' ? tc : C.top === 'jacket' ? '#f1f1f1' : '#fff';
    g.beginPath(); g.moveTo(-34, top); g.lineTo(0, top + (C.top === 'jacket' ? B.h : 84)); g.lineTo(34, top); g.closePath();
    if (C.top === 'jacket') { g.beginPath(); g.rect(-bw * 0.16, top, bw * 0.32, B.h + 12); }
    fillStroke(g, inner, lw);
    if (C.top === 'suit' && !acc.has('tie') && !acc.has('bowtie')) drawTie(g, top, C.topColor === '#e63946' ? '#111111' : '#c1121f', lw, B);
    g.strokeStyle = C.top === 'labcoat' ? shade('#f4f4f4', -0.35) : inkFor(shade(tc, -0.4)); g.lineWidth = 4;
    for (const sd of [-1, 1]) { g.beginPath(); g.moveTo(sd * 36, top + 2); g.lineTo(sd * (C.top === 'jacket' ? bw * 0.16 : 6), top + (C.top === 'jacket' ? 60 : 96)); if (C.top === 'jacket') g.lineTo(sd * bw * 0.16, top + B.h + 12); g.stroke(); }
    if (C.top === 'suit') for (const y of [top + 120, top + 150]) { ell(g, 0, y, 5, 5); g.fillStyle = inkFor(tc); g.fill(); }
    if (C.top === 'labcoat') {
      rrect(g, bw * 0.12, top + B.h * 0.3, bw * 0.24, B.h * 0.22, 4); fillStroke(g, '#f4f4f4', lw * 0.7);
      rrect(g, bw * 0.17, top + B.h * 0.3 - 18, 7, 30, 3); fillStroke(g, tc, 2);
    }
  } else if (C.top === 'overalls') {
    neckOpen(30, 17);
    const pc = C.pants;
    rrect(g, -bw * 0.32, top + B.h * 0.32, bw * 0.64, B.h * 0.7, [14, 14, 0, 0]); fillStroke(g, pc, lw);
    for (const sd of [-1, 1]) {
      g.beginPath(); g.moveTo(sd * bw * 0.26, top + B.h * 0.34); g.lineTo(sd * bw * 0.36, top + 4); g.lineWidth = 16; g.strokeStyle = fillOf(pc); g.stroke();
      ell(g, sd * bw * 0.24, top + B.h * 0.38, 7, 7); fillStroke(g, GOLD, 2.5);
    }
    rrect(g, -bw * 0.13, top + B.h * 0.45, bw * 0.26, B.h * 0.18, 6); fillStroke(g, shade(pc, -0.12), lw * 0.7);
  } else if (C.top === 'robe') {
    g.beginPath(); g.moveTo(-30, top); g.lineTo(0, top + 70); g.lineTo(30, top); g.closePath(); fillStroke(g, shade(tc, -0.25), lw);
    rrect(g, -bw / 2 + 2, top + B.h * 0.55, bw - 4, 14, 6); fillStroke(g, GOLD, lw * 0.7);
    g.fillStyle = STY === 'neon' ? '#fff' : 'rgba(255,240,150,.85)';
    for (let i = 0; i < 9; i++) { const x = (hrand(i) - 0.5) * bw * 0.8, y = top + 30 + hrand(i + 20) * (B.h + B.leg - 70); star(g, x, y, 5 + hrand(i + 4) * 5); g.fill(); }
  } else if (C.top === 'dress') {
    neckOpen(26, 15);
  }
  if (acc.has('chain')) {
    for (let i = 0; i <= 18; i++) {
      const u = i / 18, x = (1 - u) * (1 - u) * -44 + u * u * 44, y = top + 2 + 2 * (1 - u) * u * 84;
      ell(g, x, y, 6, 4.5, u * 2 - 1); g.fillStyle = GOLD; g.fill(); g.lineWidth = 2; g.strokeStyle = '#a6780a'; g.stroke();
    }
    ell(g, 0, top + 52, 17, 17); fillStroke(g, GOLD, 3);
    g.fillStyle = '#a6780a'; g.font = 'bold 22px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText('$', 0, top + 53);
  }
  if (acc.has('tie')) drawTie(g, top, C.hatColor, lw, B);
  if (acc.has('bowtie')) {
    for (const sd of [-1, 1]) { g.beginPath(); g.moveTo(0, top + 8); g.lineTo(sd * 30, top - 6); g.lineTo(sd * 30, top + 22); g.closePath(); fillStroke(g, C.hatColor, lw * 0.8); }
    ell(g, 0, top + 8, 8, 8); fillStroke(g, shade(C.hatColor, -0.2), lw * 0.7);
  }
  if (acc.has('scarf')) {
    rrect(g, R * 0.1, top + 4, 30, 96, 10); fillStroke(g, shade(C.hatColor, -0.1), lw);
    rrect(g, -R * 0.42, top - 18, R * 0.84, 34, 16); fillStroke(g, C.hatColor, lw);
  }
}
function drawTie(g, top, col, lw, B) {
  g.beginPath(); g.moveTo(0, top + 8); g.lineTo(-11, top + 18); g.lineTo(-8, top + B.h * 0.55); g.lineTo(0, top + B.h * 0.62); g.lineTo(8, top + B.h * 0.55); g.lineTo(11, top + 18); g.closePath();
  fillStroke(g, col, 3);
  ell(g, 0, top + 10, 9, 7); fillStroke(g, shade(col, -0.15), 3);
}
function star(g, x, y, r) {
  g.beginPath();
  for (let i = 0; i < 10; i++) { const a = -Math.PI / 2 + i * Math.PI / 5, rr = i % 2 ? r * 0.45 : r; g.lineTo(x + Math.cos(a) * rr, y + Math.sin(a) * rr); }
  g.closePath();
}

function drawArm(g, C, sd, x, y, a, lw, acc, top) {
  const ex = x + Math.sin(a.sh) * UA * sd, ey = y + Math.cos(a.sh) * UA;
  const fa = a.sh + a.el, hx = ex + Math.sin(fa) * FA * sd, hy = ey + Math.cos(fa) * FA;
  const longSleeve = LONG_SLEEVE.has(C.top), sl = sleeveOf(C), sw = C.top === 'robe' ? 40 : 34;
  const seg = (x0, y0, x1, y1, col, w) => { g.beginPath(); g.moveTo(x0, y0); g.lineTo(x1, y1); g.lineWidth = w; g.strokeStyle = fillOf(col); g.stroke(); };
  g.beginPath(); g.moveTo(x, y); g.lineTo(ex, ey); g.lineTo(hx, hy); inkStroke(g, longSleeve ? sl : C.skin, (longSleeve ? sw - 4 : 30) + lw * 2);
  seg(ex, ey, hx, hy, longSleeve ? sl : C.skin, longSleeve ? sw - 4 : 30);
  seg(x, y, ex, ey, C.skin, 30);
  if (C.top !== 'tank') seg(x, y, longSleeve ? ex : x + (ex - x) * 0.6, longSleeve ? ey : y + (ey - y) * 0.6, sl, sw);
  if (STY === 'anime' && longSleeve) seg(ex, ey, ex + (hx - ex) * 0.08, ey + (hy - ey) * 0.08, shade(sl, -0.2), sw - 4);
  const px = hx + Math.sin(fa) * 8 * sd, py = hy + Math.cos(fa) * 8;
  holdItem(g, C, sd, px, py, lw, top);
  ell(g, px, py, 21, 21); fillStroke(g, acc.has('gloves') ? '#ffffff' : C.skin, lw);
}
function holdItem(g, C, sd, x, y, lw, top) {   // drawn just before the hand, so the fingers wrap it
  const p = C.prop;
  if (p === 'mic' && sd > 0) {
    g.save(); g.translate(x, y); g.rotate(-0.5);
    rrect(g, -9, -18, 18, 74, 7); fillStroke(g, '#222', lw);
    rrect(g, -12, -30, 24, 16, 4); fillStroke(g, '#555', lw * 0.8);
    ell(g, 0, -50, 23, 23); fillStroke(g, '#9a9a9a', lw);
    g.strokeStyle = 'rgba(0,0,0,.35)'; g.lineWidth = 2;
    for (let i = -2; i <= 2; i++) { g.beginPath(); g.moveTo(i * 8, -70); g.lineTo(i * 8, -30); g.stroke(); g.beginPath(); g.moveTo(-22, -50 + i * 8); g.lineTo(22, -50 + i * 8); g.stroke(); }
    g.restore();
  } else if (p === 'coffee' && sd < 0) {
    const mx = x + 24, my = y - 6;
    g.beginPath(); g.arc(mx + 22, my, 13, -Math.PI / 2, Math.PI / 2); inkStroke(g, '#ffffff', 9);
    rrect(g, mx - 22, my - 28, 44, 52, [4, 4, 12, 12]); fillStroke(g, '#ffffff', lw);
    rrect(g, mx - 22, my - 8, 44, 14, 0); g.fillStyle = fillOf(C.hatColor); g.fill();
    g.strokeStyle = 'rgba(255,255,255,.55)'; g.lineWidth = 3;
    for (let i = 0; i < 2; i++) { const t = (P_T * 0.8 + i * 0.5) % 1; g.globalAlpha = 1 - t; g.beginPath(); g.moveTo(mx - 6 + i * 12, my - 32 - t * 30); g.bezierCurveTo(mx + 6 + i * 12, my - 42 - t * 30, mx - 14 + i * 12, my - 52 - t * 30, mx - 2 + i * 12, my - 62 - t * 30); g.stroke(); }
    g.globalAlpha = 1;
  } else if (p === 'phone' && sd > 0) {
    g.save(); g.translate(x - 6, y - 16); g.rotate(-0.15);
    rrect(g, -18, -38, 36, 66, 7); fillStroke(g, '#222', lw);
    rrect(g, -13, -32, 26, 52, 3); g.fillStyle = STY === 'neon' ? '#00e5ff' : '#7fd1ff'; g.fill();
    g.restore();
  } else if (p === 'gamepad' && sd > 0) {
    const cx = 0, cy = top + 112;
    g.beginPath(); g.moveTo(cx - 70, cy - 14); g.quadraticCurveTo(cx, cy - 30, cx + 70, cy - 14); g.quadraticCurveTo(cx + 92, cy + 30, cx + 62, cy + 30); g.quadraticCurveTo(cx + 40, cy + 30, cx + 30, cy + 12);
    g.lineTo(cx - 30, cy + 12); g.quadraticCurveTo(cx - 40, cy + 30, cx - 62, cy + 30); g.quadraticCurveTo(cx - 92, cy + 30, cx - 70, cy - 14); g.closePath();
    fillStroke(g, '#2b2d42', lw);
    [['#e63946', 40, -6], ['#06d6a0', 52, -16], ['#ffd23f', 28, -16], ['#3a86ff', 40, -26]].forEach(([c, dx, dy]) => { ell(g, cx + dx, cy + dy + 10, 5, 5); g.fillStyle = c; g.fill(); });
    g.fillStyle = '#999'; g.fillRect(cx - 50, cy - 8, 22, 7); g.fillRect(cx - 42.5, cy - 15.5, 7, 22);
  }
}
let P_T = 0;   // current time for small prop animations (steam)
function drawGuitar(g, y, bw, lw, C) {
  g.save(); g.translate(-6, y); g.rotate(0.42);
  rrect(g, -bw * 0.95 - 120, -9, bw * 0.95 + 80, 18, 4); fillStroke(g, '#6b4226', lw);
  rrect(g, -bw * 0.95 - 150, -14, 36, 28, 6); fillStroke(g, '#3d2614', lw);
  const body = shade(C.hatColor, -0.05);
  ell(g, 30, 0, 66, 52); fillStroke(g, body, lw); ell(g, -26, 0, 46, 40); fillStroke(g, body, lw);
  ell(g, -8, 0, 15, 15); g.fillStyle = '#1b1b1b'; g.fill();
  g.strokeStyle = 'rgba(240,240,240,.8)'; g.lineWidth = 1.5;
  for (let i = -2; i <= 2; i++) { g.beginPath(); g.moveTo(-bw * 0.95 - 130, i * 3); g.lineTo(60, i * 3); g.stroke(); }
  g.restore();
}
function drawDesk(g, C, layer, deskY, T, lw) {
  const dw = 300, wood = C.prop === 'newsdesk' ? shade(C.bgColor, -0.25) : '#3b2a20', topC = C.prop === 'newsdesk' ? shade(C.bgColor, 0.25) : '#5a4030';
  if (layer === 'back') {
    // desktop surface, then what sits on it
    g.beginPath(); g.moveTo(-dw * 0.9, deskY - 30); g.lineTo(dw * 0.9, deskY - 30); g.lineTo(dw, deskY + 6); g.lineTo(-dw, deskY + 6); g.closePath();
    fillStroke(g, topC, lw);
    if (C.prop === 'computer') {
      const mx = -dw * 0.86, my = deskY - 190, mw = 170, mh = 118;
      rrect(g, mx + mw / 2 - 10, my + mh, 20, 50, 4); fillStroke(g, '#222', lw);
      ell(g, mx + mw / 2, deskY - 20, 40, 8); fillStroke(g, '#222', lw);
      rrect(g, mx - 8, my - 8, mw + 16, mh + 16, 8); fillStroke(g, '#151515', lw);
      codeScreen(g, mx, my, mw, mh, T, 7);
      const gr = g.createRadialGradient(mx + mw / 2, my + mh / 2, 10, mx + mw / 2, my + mh / 2, 180);
      gr.addColorStop(0, 'rgba(0,255,140,.12)'); gr.addColorStop(1, 'rgba(0,255,140,0)'); g.fillStyle = gr; g.fillRect(mx - 180, my - 180, mw + 360, mh + 360);
    }
    if (C.prop !== 'newsdesk') {
      g.beginPath(); g.moveTo(-120, deskY - 20); g.lineTo(120, deskY - 20); g.lineTo(132, deskY + 2); g.lineTo(-132, deskY + 2); g.closePath(); fillStroke(g, '#2a2a2a', lw * 0.8);
      g.fillStyle = 'rgba(255,255,255,.28)';
      for (let r = 0; r < 3; r++) for (let i = 0; i < 14; i++) g.fillRect(-110 + i * 16 + r * 3, deskY - 16 + r * 6, 12, 4);
    } else {
      g.save(); g.translate(150, deskY - 14); g.rotate(-0.08); rrect(g, -50, -10, 100, 20, 2); fillStroke(g, '#ffffff', lw * 0.6); g.restore();
      g.beginPath(); g.moveTo(-170, deskY - 20); g.lineTo(-170, deskY - 70); inkStroke(g, '#444', 5);
      ell(g, -170, deskY - 78, 10, 14); fillStroke(g, '#444', lw * 0.7);
    }
    return;
  }
  // front panel hides the legs; the laptop lid faces the camera
  if (C.prop === 'laptop') {
    g.beginPath(); g.moveTo(-118, deskY - 4); g.lineTo(118, deskY - 4); g.lineTo(108, deskY - 150); g.lineTo(-108, deskY - 150); g.closePath();
    fillStroke(g, '#b8bcc6', lw);
    g.save(); g.shadowColor = STY === 'neon' ? '#ff2fd0' : 'rgba(255,255,255,.9)'; g.shadowBlur = 16;
    ell(g, 0, deskY - 80, 22, 22); g.fillStyle = '#ffffff'; g.fill(); g.restore();
    g.fillStyle = '#b8bcc6'; g.font = '900 26px "Archivo Black", sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText('λ', 0, deskY - 79);
  }
  rrect(g, -dw, deskY + 6, dw * 2, -deskY + 30, [0, 0, 8, 8]); fillStroke(g, wood, lw);
  if (C.prop === 'newsdesk') {
    rrect(g, -dw, deskY + 30, dw * 2, 28, 0); g.fillStyle = fillOf(shade(C.bgColor, 0.35)); g.fill();
    g.font = `900 ${-deskY * 0.24}px "Archivo Black", sans-serif`; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillStyle = STY === 'neon' ? neon(C.bgColor) : '#ffffff'; g.fillText(((C.name || 'Toon').split(' ')[0] + ' NEWS').toUpperCase(), 0, deskY * 0.42);
  } else {
    g.strokeStyle = 'rgba(0,0,0,.25)'; g.lineWidth = 3;
    for (let i = 1; i < 4; i++) { g.beginPath(); g.moveTo(-dw, deskY + 6 + (-deskY) * i / 4); g.lineTo(dw, deskY + 6 + (-deskY) * i / 4); g.stroke(); }
  }
}

/* ── head ── */
// a slightly enlarged copy of the head's own outline, so hair and hats hug every head shape (square corners included)
function headShell(g, shape, R, sx, sy, dy) { g.save(); g.translate(0, dy); g.scale(sx, sy); headPath(g, shape, R); g.restore(); }
function hairCap(g, C, R, hw, color, lw) {
  const fringe = () => {   // right sideburn → scalloped bangs → left sideburn
    g.lineTo(R * 3, 0.12 * R); g.lineTo(hw * 1.02, 0.12 * R); g.lineTo(hw * 0.82, -0.3 * R);
    const n = 4, step = hw * 1.64 / n;
    for (let i = 0; i < n; i++) { const x0 = hw * 0.82 - i * step; g.quadraticCurveTo(x0 - step / 2, -0.12 * R, x0 - step, -0.3 * R); }
    g.lineTo(-hw * 1.02, 0.12 * R); g.lineTo(-R * 3, 0.12 * R);
  };
  if (STY === 'anime') {   // the bangs cast a soft shadow on the forehead
    g.save(); headPath(g, C.head, R); g.clip(); g.translate(0, 0.08 * R);
    g.beginPath(); g.moveTo(-R * 3, -R * 3); g.lineTo(R * 3, -R * 3); fringe(); g.closePath(); g.fillStyle = 'rgba(90,40,60,.2)'; g.fill(); g.restore();
  }
  g.save();
  g.beginPath(); g.moveTo(-R * 3, -R * 3); g.lineTo(R * 3, -R * 3); fringe(); g.closePath(); g.clip();
  headShell(g, C.head, R, 1.07, 1.06, -0.02 * R); fillStroke(g, color, lw);
  if (STY === 'anime') {   // glossy shine band
    headShell(g, C.head, R, 1.07, 1.06, -0.02 * R); g.clip();
    g.beginPath();
    for (let i = 0; i <= 8; i++) { const x = -hw * 0.75 + i * hw * 0.19, y = -0.66 * R + (i % 2 ? 0.07 : -0.03) * R - Math.sin(i / 8 * Math.PI) * 0.12 * R; i ? g.lineTo(x, y) : g.moveTo(x, y); }
    g.lineWidth = 0.07 * R; g.strokeStyle = 'rgba(255,255,255,.42)'; g.stroke();
  }
  g.restore();
  g.save();   // outline along the fringe, only where it crosses the hair
  headShell(g, C.head, R, 1.07, 1.06, -0.02 * R); g.clip();
  g.beginPath(); g.moveTo(R * 3, 0.12 * R); fringe(); inkStroke(g, color, lw);
  g.restore();
}
function hatDome(g, C, R, cutY, sx, sy, dy, color, lw) {
  g.save(); g.beginPath(); g.rect(-R * 3, -R * 3, R * 6, R * 3 + cutY); g.clip();
  headShell(g, C.head, R, sx, sy, dy); fillStroke(g, color, lw);
  g.restore();
}
const HAIR_TOP = new Set(['short', 'spiky', 'flat', 'swoop', 'afro', 'long', 'ponytail', 'pigtails', 'bun', 'dreads']);

function drawHead(g, C, R, P, lw, acc) {
  const HS = HEAD[C.head] || HEAD.round, hw = HS.w * R, ht = HS.t * R;
  const hairC = C.hairColor, hat = C.hatColor, ex = 0.38 * hw, ey = -0.08 * R, hatOn = C.hat && C.hat !== 'none', hood = C.hat === 'hood';
  // behind the head
  if (hood) headShell(g, C.head, R, 1.36, 1.3, 0.06 * R), fillStroke(g, shade(C.topColor, -0.22), lw);
  if (!hood) {
    if (C.hair === 'long') { rrect(g, -hw * 1.13, -ht * 0.95, hw * 2.26, R * 2.05, [R * 0.9, R * 0.9, R * 0.25, R * 0.25]); fillStroke(g, hairC, lw); }
    if (C.hair === 'afro') {
      const puffs = [];
      for (let i = 0; i < 16; i++) { const a = i / 16 * Math.PI * 2; puffs.push([Math.cos(a) * hw * 1.18, -0.3 * R + Math.sin(a) * ht * 0.92]); }
      puffs.forEach(([x, y]) => { ell(g, x, y, R * 0.34, R * 0.34); inkStroke(g, hairC, lw * 2); });
      g.fillStyle = fillOf(hairC);
      puffs.forEach(([x, y]) => { ell(g, x, y, R * 0.34, R * 0.34); g.fill(); });
      ell(g, 0, -0.3 * R, hw * 1.2, ht * 0.95); g.fill();
    }
    if (C.hair === 'bun' && !hatOn) { ell(g, 0, -ht * 1.12, R * 0.32, R * 0.3); fillStroke(g, hairC, lw); }
    if (C.hair === 'ponytail') {
      g.beginPath(); g.moveTo(hw * 0.55, -ht * 0.7); g.bezierCurveTo(hw * 1.6, -ht * 0.6, hw * 1.5, R * 0.6, hw * 1.05, R * 1.05); g.bezierCurveTo(hw * 1.1, R * 0.4, hw * 0.9, -ht * 0.2, hw * 0.55, -ht * 0.7); g.closePath();
      fillStroke(g, hairC, lw); ell(g, hw * 0.95, -ht * 0.55, 0.08 * R, 0.1 * R); fillStroke(g, hat, lw * 0.7);
    }
    if (C.hair === 'pigtails') for (const sd of [-1, 1]) {
      ell(g, sd * hw * 1.22, 0.15 * R, 0.24 * R, 0.42 * R, sd * 0.3); fillStroke(g, hairC, lw);
      ell(g, sd * hw * 1.02, -0.22 * R, 0.08 * R, 0.08 * R); fillStroke(g, hat, lw * 0.7);
    }
    if (C.hair === 'dreads') for (let i = 0; i < 10; i++) {
      const u = i / 9, x = (u * 2 - 1) * hw * 1.08, len = R * (0.75 + 0.25 * hrand(i)) * (Math.abs(u - 0.5) > 0.2 ? 1 : 0.4);
      g.beginPath(); g.moveTo(x, -0.35 * R); g.lineTo(x * 1.06, -0.35 * R + len + Math.abs(x) * 0.3);
      inkStroke(g, hairC, 0.15 * R + lw * 2); g.lineWidth = 0.15 * R; g.strokeStyle = fillOf(hairC); g.stroke();
    }
  }
  if (C.ears === 'cat') for (const sd of [-1, 1]) {
    g.beginPath(); g.moveTo(sd * hw * 0.2, -ht * 0.8); g.lineTo(sd * hw * 0.82, -ht * 1.3); g.lineTo(sd * hw * 0.92, -ht * 0.4); g.closePath();
    fillStroke(g, C.hair === 'none' ? C.skin : hairC, lw);
    g.beginPath(); g.moveTo(sd * hw * 0.42, -ht * 0.82); g.lineTo(sd * hw * 0.78, -ht * 1.12); g.lineTo(sd * hw * 0.82, -ht * 0.6); g.closePath(); g.fillStyle = fillOf('#ff9fb2'); g.fill();
  } else if (C.ears === 'round') for (const sd of [-1, 1]) {
    ell(g, sd * hw * 0.98, 0.05 * R, 0.17 * R, 0.23 * R); fillStroke(g, C.skin, lw);
    ell(g, sd * hw * 1.0, 0.06 * R, 0.08 * R, 0.13 * R); g.fillStyle = fillOf(shade(C.skin, -0.18)); g.fill();
  } else if (C.ears === 'pointy') for (const sd of [-1, 1]) {
    g.beginPath(); g.moveTo(sd * hw * 0.9, -0.12 * R); g.lineTo(sd * hw * 1.48, -0.6 * R); g.lineTo(sd * hw * 0.92, 0.26 * R); g.closePath(); fillStroke(g, C.skin, lw);
  }
  // face
  headPath(g, C.head, R); fillStroke(g, C.skin, lw);
  if (STY === 'anime') {   // shade under the jaw line and side of the face
    g.save(); headPath(g, C.head, R); g.clip(); g.fillStyle = 'rgba(120,50,70,.08)'; ell(g, hw * 0.95, 0.25 * R, hw * 0.42, R * 1.2); g.fill(); g.restore();
  }
  if (acc.has('blush') || STY === 'anime') {
    g.fillStyle = STY === 'anime' && !acc.has('blush') ? 'rgba(255,90,120,.18)' : 'rgba(255,90,120,.3)';
    for (const sd of [-1, 1]) { ell(g, sd * hw * 0.58, 0.3 * R, 0.16 * R, 0.1 * R); g.fill(); }
    if (STY === 'anime') { g.strokeStyle = 'rgba(220,60,90,.45)'; g.lineWidth = 2; for (const sd of [-1, 1]) for (let i = 0; i < 3; i++) { const x = sd * hw * 0.5 + i * 0.07 * R; g.beginPath(); g.moveTo(x, 0.26 * R); g.lineTo(x - 0.04 * R, 0.34 * R); g.stroke(); } }
  }
  if (acc.has('freckles')) { g.fillStyle = fillOf(shade(C.skin, -0.35)); for (const sd of [-1, 1]) for (let i = 0; i < 5; i++) { ell(g, sd * hw * (0.42 + hrand(i) * 0.3), 0.18 * R + hrand(i + 9) * 0.14 * R, 0.018 * R, 0.018 * R); g.fill(); } }
  const jaw = R * 0.46 * P.mouth.open * 0.72;   // how far the lower lip has dropped
  if (C.facial === 'beard' || C.facial === 'stubble') {
    g.save(); headPath(g, C.head, R); g.clip();
    g.globalAlpha = C.facial === 'stubble' ? 0.28 : 1;
    ell(g, 0, 0.66 * R + jaw * 0.4, hw * 1.05, 0.55 * R + jaw * 0.4); g.fillStyle = fillOf(hairC); g.fill();
    g.globalAlpha = 1; g.restore();
    if (C.facial === 'beard') { ell(g, 0, 0.92 * R + jaw * 0.5, hw * 0.55, 0.26 * R); fillStroke(g, hairC, lw); ell(g, 0, 0.47 * R + jaw * 0.3, R * 0.3, R * 0.14 + jaw * 0.6); g.fillStyle = fillOf(C.skin); g.fill(); }
  }
  if (!acc.has('visor')) for (const sd of [-1, 1]) drawEye(g, C, sd, sd * ex, ey, R, P, lw);
  const ny = 0.14 * R;
  if (STY === 'anime' && C.nose !== 'none') { g.beginPath(); g.moveTo(0.02 * R, ny - 0.05 * R); g.lineTo(0.05 * R, ny + 0.02 * R); g.lineTo(0, ny + 0.03 * R); g.lineWidth = 2; g.strokeStyle = shade(C.skin, -0.35); g.stroke(); }
  else if (C.nose === 'button') { ell(g, 0, ny, 0.07 * R, 0.06 * R); g.fillStyle = STY === 'neon' ? neon(C.skin) : shade(C.skin, -0.25); g.fill(); }
  else if (C.nose === 'round') { ell(g, 0, ny, 0.13 * R, 0.11 * R); fillStroke(g, shade(C.skin, -0.08), lw * 0.8); ell(g, -0.04 * R, ny - 0.04 * R, 0.035 * R, 0.03 * R); g.fillStyle = 'rgba(255,255,255,.5)'; g.fill(); }
  else if (C.nose === 'long') { g.beginPath(); g.moveTo(-0.02 * R, -0.02 * R); g.quadraticCurveTo(0.34 * R, 0.1 * R, 0.27 * R, 0.21 * R); g.lineTo(-0.04 * R, 0.23 * R); g.closePath(); fillStroke(g, C.skin, lw * 0.8); }
  const lipBottom = drawMouth(g, C, R, P.mouth, lw);
  if (C.facial === 'mustache') for (const sd of [-1, 1]) { ell(g, sd * 0.13 * R, 0.34 * R - P.mouth.open * 0.03 * R, 0.16 * R, 0.065 * R, sd * -0.25); fillStroke(g, hairC, lw * 0.7); }
  if (C.facial === 'goatee') { ell(g, 0, 0.47 * R + lipBottom + 0.15 * R, 0.12 * R, 0.1 * R); fillStroke(g, hairC, lw * 0.8); }
  // hair (under any hat: only the fringe)
  if (!hood || HAIR_TOP.has(C.hair)) {
    if (!hatOn) {
      if (C.hair === 'spiky') for (let i = 0; i < 7; i++) {
        const a0 = Math.PI * (1.08 + i * 0.12), a1 = a0 + Math.PI * 0.12, am = (a0 + a1) / 2;
        g.beginPath(); g.moveTo(Math.cos(a0) * hw, Math.sin(a0) * ht); g.lineTo(Math.cos(am) * hw * 1.4, Math.sin(am) * ht * 1.4); g.lineTo(Math.cos(a1) * hw, Math.sin(a1) * ht); g.closePath();
        fillStroke(g, hairC, lw);
      }
      if (C.hair === 'flat') { rrect(g, -hw * 0.92, -ht * 1.42, hw * 1.84, ht * 0.75, 0.12 * R); fillStroke(g, hairC, lw); }
      if (C.hair === 'mohawk') for (let i = 0; i < 5; i++) {
        const a0 = Math.PI * (1.32 + i * 0.07), a1 = a0 + Math.PI * 0.07, am = (a0 + a1) / 2;
        g.beginPath(); g.moveTo(Math.cos(a0) * hw * 0.95, Math.sin(a0) * ht * 0.95); g.lineTo(Math.cos(am) * hw * 1.55, Math.sin(am) * ht * 1.55); g.lineTo(Math.cos(a1) * hw * 0.95, Math.sin(a1) * ht * 0.95); g.closePath();
        fillStroke(g, hairC, lw);
      }
    }
    if (HAIR_TOP.has(C.hair)) hairCap(g, C, R, hw, hairC, lw);
    if (C.hair === 'buzz') { g.save(); g.globalAlpha = 0.5; hairCap(g, C, R, hw, hairC, 0); g.restore(); }
    if (C.hair === 'swoop') {   // a big side-swept lock across the forehead
      g.beginPath(); g.moveTo(hw * 0.75, -ht * 0.78); g.bezierCurveTo(hw * 0.1, -ht * 0.55, -hw * 0.55, -0.4 * R, -hw * 0.82, -0.02 * R);
      g.bezierCurveTo(-hw * 0.5, -0.32 * R, -hw * 0.05, -0.36 * R, hw * 0.35, -0.3 * R); g.closePath(); fillStroke(g, hairC, lw);
    }
  }
  // hats
  if (C.hat === 'cap' || C.hat === 'backcap') {
    if (C.hat === 'backcap') { ell(g, -hw * 0.75, -0.32 * R, hw * 0.55, 0.09 * R, 0.25); fillStroke(g, shade(hat, -0.2), lw); }
    hatDome(g, C, R, -0.2 * R, 1.1, 1.1, -0.05 * R, hat, lw);
    rrect(g, -hw * 1.1, -0.28 * R, hw * 2.2, 0.1 * R, 0.05 * R); fillStroke(g, shade(hat, -0.1), lw);
    if (C.hat === 'cap') { ell(g, hw * 0.55, -0.2 * R, hw * 0.85, 0.1 * R); fillStroke(g, shade(hat, -0.2), lw); }
    else { rrect(g, -0.12 * R, -0.36 * R, 0.24 * R, 0.1 * R, 0.03 * R); g.fillStyle = fillOf(shade(hat, -0.35)); g.fill(); }
    ell(g, 0, -0.05 * R - ht * 1.1, 0.07 * R, 0.05 * R); fillStroke(g, shade(hat, -0.2), 3);
  } else if (C.hat === 'beanie') {
    ell(g, 0, -0.06 * R - ht * 1.14 - 0.08 * R, 0.17 * R, 0.17 * R); fillStroke(g, shade(hat, 0.45), lw);
    hatDome(g, C, R, -0.24 * R, 1.12, 1.14, -0.06 * R, hat, lw);
    rrect(g, -hw * 1.15, -0.36 * R, hw * 2.3, 0.24 * R, 0.1 * R); fillStroke(g, shade(hat, -0.15), lw);
  } else if (C.hat === 'tophat') {
    g.save(); g.translate(0, -ht * 0.82); g.rotate(0.08);
    rrect(g, -0.6 * R, -1.05 * R, 1.2 * R, 1.05 * R, 6); fillStroke(g, hat, lw);
    rrect(g, -0.6 * R, -0.28 * R, 1.2 * R, 0.18 * R, 2); fillStroke(g, shade(hat, -0.45), lw * 0.6);
    ell(g, 0, 0, R * 1.0, 0.13 * R); fillStroke(g, shade(hat, -0.1), lw);
    g.restore();
  } else if (C.hat === 'cowboy') {
    g.save(); g.translate(0, -ht * 0.62); g.rotate(-0.05);
    g.beginPath(); g.moveTo(-hw * 1.65, -0.02 * R); g.quadraticCurveTo(-hw * 1.5, 0.2 * R, 0, 0.16 * R); g.quadraticCurveTo(hw * 1.5, 0.2 * R, hw * 1.65, -0.02 * R);
    g.quadraticCurveTo(hw * 1.3, -0.16 * R, 0, -0.08 * R); g.quadraticCurveTo(-hw * 1.3, -0.16 * R, -hw * 1.65, -0.02 * R); g.closePath(); fillStroke(g, shade(hat, -0.1), lw);
    g.beginPath(); g.moveTo(-hw * 0.72, 0); g.bezierCurveTo(-hw * 0.8, -0.7 * R, -hw * 0.3, -0.62 * R, 0, -0.5 * R); g.bezierCurveTo(hw * 0.3, -0.62 * R, hw * 0.8, -0.7 * R, hw * 0.72, 0); g.closePath();
    fillStroke(g, hat, lw);
    rrect(g, -hw * 0.74, -0.16 * R, hw * 1.48, 0.12 * R, 0.03 * R); fillStroke(g, shade(hat, -0.45), lw * 0.7);
    g.restore();
  } else if (C.hat === 'crown') {
    const y0 = -ht * 0.78, cw = hw * 0.78;
    g.beginPath(); g.moveTo(-cw, y0);
    for (let i = 0; i <= 4; i++) { const x = -cw + i * cw / 2; g.lineTo(x, y0 - 0.48 * R); if (i < 4) g.lineTo(x + cw / 4, y0 - 0.22 * R); }
    g.lineTo(cw, y0); g.closePath(); fillStroke(g, GOLD, lw);
    for (let i = 0; i <= 4; i++) { ell(g, -cw + i * cw / 2, y0 - 0.5 * R, 0.05 * R, 0.05 * R); fillStroke(g, GOLD, lw * 0.6); }
    [['#e63946', 0], ['#3a86ff', -0.5], ['#06d6a0', 0.5]].forEach(([c, k]) => { ell(g, k * cw, y0 - 0.1 * R, 0.06 * R, 0.07 * R); fillStroke(g, c, lw * 0.6); });
  } else if (C.hat === 'wizard') {
    g.save(); g.translate(0, -ht * 0.66);
    g.beginPath(); g.moveTo(-hw * 0.85, 0); g.quadraticCurveTo(-hw * 0.2, -0.9 * R, hw * 0.55, -1.75 * R); g.quadraticCurveTo(hw * 0.25, -0.8 * R, hw * 0.85, 0); g.closePath(); fillStroke(g, hat, lw);
    g.fillStyle = STY === 'neon' ? '#fff' : 'rgba(255,240,150,.9)';
    [[-0.2, -0.35, 0.09], [0.15, -0.7, 0.07], [0.32, -1.15, 0.05]].forEach(([x, y, r]) => { star(g, x * R, y * R, r * R); g.fill(); });
    ell(g, 0, 0, hw * 1.45, 0.16 * R); fillStroke(g, shade(hat, -0.15), lw);
    g.restore();
  }
  // brows sit on top of the bangs, cartoon style
  if (C.brows !== 'none' && !acc.has('visor')) {
    const by = -0.36 * R - P.brow * 0.08 * R - (C.eyes === 'wide' ? 0.05 * R : 0);
    const inner = C.mood === 'grumpy' || C.eyes === 'angry' ? 0.09 * R : C.mood === 'happy' ? -0.02 * R : 0;
    const bw = (C.brows === 'thick' ? 0.075 : C.brows === 'uni' ? 0.07 : 0.035) * R * (STY === 'anime' ? 0.6 : 1);
    const bc = C.hair === 'none' ? shade(C.skin, -0.55) : shade(hairC, -0.1);
    g.beginPath();
    if (C.brows === 'uni') { g.moveTo(-ex - 0.17 * R, by); g.quadraticCurveTo(0, by - 0.03 * R + inner, ex + 0.17 * R, by); }
    else for (const sd of [-1, 1]) { g.moveTo(sd * (ex - 0.15 * R), by + inner); g.quadraticCurveTo(sd * ex, by - (C.mood === 'happy' ? 0.06 : 0.02) * R, sd * (ex + 0.16 * R), by + 0.01 * R); }
    if (STY !== 'anime') inkStroke(g, bc, bw + 4);
    g.lineWidth = bw; g.strokeStyle = STY === 'neon' ? neon(bc) : bc; g.stroke();
  }
  // hood front: the rim around the face opening (covers ears + the top of the hair)
  if (hood) {
    g.beginPath(); headShell(g, C.head, R, 1.36, 1.3, 0.06 * R); g.ellipse(0, 0.1 * R, hw * 0.98, ht * 1.0, 0, 0, Math.PI * 2);
    g.fillStyle = fillOf(C.topColor); g.fill('evenodd');
    ell(g, 0, 0.1 * R, hw * 0.98, ht * 1.0); inkStroke(g, C.topColor, lw);
    headShell(g, C.head, R, 1.36, 1.3, 0.06 * R); inkStroke(g, C.topColor, lw);
    g.beginPath(); g.ellipse(0, 0.1 * R, hw * 1.08, ht * 1.1, 0, Math.PI * 1.15, Math.PI * 1.85); g.lineWidth = 3; g.strokeStyle = 'rgba(0,0,0,.25)'; g.stroke();
  }
  // accessories on the head
  if (acc.has('glasses') && !acc.has('shades') && !acc.has('visor')) {
    for (const sd of [-1, 1]) { ell(g, sd * ex, ey, 0.22 * R, 0.2 * R); g.fillStyle = 'rgba(255,255,255,.15)'; g.fill(); inkStroke(g, '#333', lw * 1.1); }
    g.beginPath(); g.moveTo(-ex + 0.22 * R, ey); g.quadraticCurveTo(0, ey - 0.06 * R, ex - 0.22 * R, ey);
    g.moveTo(-ex - 0.22 * R, ey); g.lineTo(-hw * 0.97, ey - 0.04 * R); g.moveTo(ex + 0.22 * R, ey); g.lineTo(hw * 0.97, ey - 0.04 * R); inkStroke(g, '#333', lw * 1.1);
  }
  if (acc.has('shades') && !acc.has('visor')) {
    for (const sd of [-1, 1]) {
      rrect(g, sd * ex - 0.24 * R, ey - 0.15 * R, 0.48 * R, 0.3 * R, [0.04 * R, 0.04 * R, 0.14 * R, 0.14 * R]); fillStroke(g, '#111', lw);
      g.strokeStyle = 'rgba(255,255,255,.55)'; g.lineWidth = 4; g.beginPath(); g.moveTo(sd * ex - 0.14 * R, ey - 0.08 * R); g.lineTo(sd * ex - 0.04 * R, ey - 0.08 * R); g.stroke();
    }
    g.beginPath(); g.moveTo(-ex + 0.24 * R, ey - 0.08 * R); g.lineTo(ex - 0.24 * R, ey - 0.08 * R); inkStroke(g, '#111', lw * 1.2);
  }
  if (acc.has('visor')) {   // cyber band across the eyes with a scanning light
    rrect(g, -hw * 1.02, ey - 0.15 * R, hw * 2.04, 0.3 * R, 0.12 * R); fillStroke(g, '#111', lw);
    const vc = C.eyeColor, sx = Math.sin((P.T || 0) * 2.4) * hw * 0.7;
    g.save(); rrect(g, -hw * 0.9, ey - 0.08 * R, hw * 1.8, 0.16 * R, 0.08 * R); g.clip();
    g.fillStyle = shade(vc, -0.5); g.fillRect(-hw, ey - 0.1 * R, hw * 2, 0.2 * R);
    const gr = g.createRadialGradient(sx, ey, 0, sx, ey, 0.45 * R); gr.addColorStop(0, neon(vc)); gr.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = gr; g.fillRect(-hw, ey - 0.1 * R, hw * 2, 0.2 * R);
    g.restore();
  }
  if (acc.has('eyepatch')) {
    g.beginPath(); g.moveTo(-hw * 0.98, -0.42 * R); g.lineTo(hw * 0.98, 0.12 * R); inkStroke(g, '#111', lw * 1.2);
    ell(g, -ex, ey + 0.01 * R, 0.21 * R, 0.19 * R); fillStroke(g, '#111', lw);
  }
  if (acc.has('earring') && C.ears !== 'cat' && !hood) { g.beginPath(); g.arc(-hw * 0.99, 0.32 * R, 0.06 * R, 0, Math.PI * 2); g.lineWidth = 5; g.strokeStyle = GOLD; g.stroke(); }
  if (acc.has('headphones')) {
    g.beginPath(); g.ellipse(0, -0.05 * R, hw * (hood ? 1.38 : 1.12), ht * (hood ? 1.3 : 1.14), 0, Math.PI * 1.04, Math.PI * 1.96);
    inkStroke(g, hat, 0.11 * R + lw * 2); g.lineWidth = 0.11 * R; g.strokeStyle = fillOf(shade(hat, -0.3)); g.stroke();
    for (const sd of [-1, 1]) { rrect(g, sd * hw * (hood ? 1.2 : 1.02) - 0.15 * R, -0.22 * R, 0.3 * R, 0.5 * R, 0.13 * R); fillStroke(g, hat, lw); }
  }
}

function drawEye(g, C, sd, x, y, R, P, lw) {
  const open = Math.max(0.06, 1 - (P.blinkLR ? P.blinkLR[sd < 0 ? 0 : 1] : P.blink)), lx = P.look.x * R * 0.06, ly = P.look.y * R * 0.05;   // the camera can wink
  g.save(); g.translate(x, y);
  if (C.eyes === 'dot') {
    g.scale(1, open);
    ell(g, lx, ly, R * 0.075, R * 0.1); g.fillStyle = STY === 'neon' ? neon(C.eyeColor) : INK; g.fill();
    ell(g, lx + R * 0.025, ly - R * 0.035, R * 0.025, R * 0.025); g.fillStyle = '#fff'; g.fill();
    g.restore(); return;
  }
  if (C.eyes === 'robot') {   // glowing LED panels
    rrect(g, -0.18 * R, -0.12 * R, 0.36 * R, 0.24 * R, 0.05 * R); fillStroke(g, '#111', lw);
    g.save(); g.shadowColor = neon(C.eyeColor); g.shadowBlur = 16; g.fillStyle = neon(C.eyeColor);
    rrect(g, -0.13 * R + lx * 0.5, -0.07 * R * open, 0.26 * R, 0.14 * R * open, 0.03 * R); g.fill(); g.restore();
    g.fillStyle = 'rgba(0,0,0,.35)'; for (let i = -2; i <= 2; i++) g.fillRect(-0.13 * R, i * 0.03 * R, 0.26 * R, 0.01 * R);
    g.restore(); return;
  }
  if (C.eyes === 'sparkle' || STY === 'anime') { animeEye(g, C, sd, R, P, lw, open, lx, ly); g.restore(); return; }
  const wide = C.eyes === 'wide', rx = (wide ? 0.22 : 0.19) * R, ry = (wide ? 0.27 : 0.23) * R, pr = (wide ? 0.075 : 0.11) * R;
  g.scale(1, open * (1 + P.brow * 0.08));
  ell(g, 0, 0, rx, ry); fillStroke(g, '#fff', lw * 0.8);
  g.save(); ell(g, 0, 0, rx, ry); g.clip();
  ell(g, lx, ly + R * 0.02, pr, pr * 1.08); g.fillStyle = C.eyeColor; g.fill();
  ell(g, lx, ly + R * 0.02, pr * 0.55, pr * 0.6); g.fillStyle = '#111'; g.fill();
  ell(g, lx + pr * 0.35, ly - pr * 0.25, pr * 0.3, pr * 0.3); g.fillStyle = '#fff'; g.fill();
  if (C.eyes === 'sleepy') { g.fillStyle = fillOf(C.skin); g.fillRect(-rx - 4, -ry - 4, rx * 2 + 8, ry * 0.95 + 4); }
  if (C.eyes === 'angry') { g.fillStyle = fillOf(C.skin); g.beginPath(); g.moveTo(-rx * 1.2 * sd, -ry * 1.2); g.lineTo(rx * 1.2 * sd, -ry * 1.2); g.lineTo(-rx * 1.2 * sd, -ry * 0.05); g.closePath(); g.fill(); }
  g.restore();
  if (C.eyes === 'sleepy') { g.beginPath(); g.moveTo(-rx, -ry * 0.05); g.quadraticCurveTo(0, ry * 0.05, rx, -ry * 0.05); inkStroke(g, C.skin, lw * 0.9); }
  if (C.eyes === 'angry') { g.beginPath(); g.moveTo(-rx * 1.05 * sd, -ry * 0.1); g.lineTo(rx * 1.05 * sd, -ry * 0.95); inkStroke(g, C.skin, lw); }
  g.restore();
}
function animeEye(g, C, sd, R, P, lw, open, lx, ly) {
  // tall eyes, gradient iris, two highlights, a heavy upper lash line; blinks into a curve
  const rx = 0.16 * R, ry = 0.23 * R, lash = '#21121c';
  if (open < 0.25) {
    g.beginPath(); g.moveTo(-rx * 1.1, -ry * 0.05); g.quadraticCurveTo(0, ry * 0.3, rx * 1.1, -ry * 0.05);
    g.lineWidth = 0.035 * R; g.strokeStyle = STY === 'neon' ? neon(C.eyeColor) : lash; g.stroke(); return;
  }
  g.save(); g.scale(1, open * (1 + P.brow * 0.06));
  ell(g, 0, 0, rx, ry); g.fillStyle = STY === 'neon' ? '#0b0b14' : '#fff'; g.fill();
  g.save(); ell(g, 0, 0, rx, ry); g.clip();
  const ix = lx * 0.8, iy = ly * 0.8 + 0.03 * R, gr = g.createLinearGradient(0, iy - ry, 0, iy + ry);
  gr.addColorStop(0, shade(C.eyeColor, -0.55)); gr.addColorStop(0.6, C.eyeColor); gr.addColorStop(1, shade(C.eyeColor, 0.45));
  ell(g, ix, iy, rx * 0.84, ry * 0.8); g.fillStyle = gr; g.fill();
  ell(g, ix, iy + 0.01 * R, rx * 0.38, ry * 0.4); g.fillStyle = '#120a10'; g.fill();
  g.fillStyle = 'rgba(0,0,0,.18)'; g.fillRect(-rx, -ry, rx * 2, ry * 0.45);
  if (C.eyes === 'sleepy') { g.fillStyle = fillOf(C.skin); g.fillRect(-rx - 4, -ry - 4, rx * 2 + 8, ry * 0.9 + 4); }
  if (C.eyes === 'angry') { g.fillStyle = fillOf(C.skin); g.beginPath(); g.moveTo(-rx * 1.2 * sd, -ry * 1.2); g.lineTo(rx * 1.2 * sd, -ry * 1.2); g.lineTo(-rx * 1.2 * sd, -ry * 0.1); g.closePath(); g.fill(); }
  ell(g, ix - rx * 0.3, iy - ry * 0.32, rx * 0.3, ry * 0.2, -0.4); g.fillStyle = '#fff'; g.fill();
  ell(g, ix + rx * 0.3, iy + ry * 0.36, rx * 0.12, ry * 0.08); g.fill();
  g.restore();
  g.beginPath(); g.moveTo(-rx * 1.15 * sd, -ry * 0.05); g.quadraticCurveTo(-rx * 0.3 * sd, -ry * 1.2, rx * 1.2 * sd, -ry * 0.55); g.lineTo(rx * 1.38 * sd, -ry * 0.7);
  g.lineWidth = 0.05 * R; g.strokeStyle = STY === 'neon' ? neon(C.eyeColor) : lash; glow(g, g.strokeStyle); g.stroke(); glow(g, '');
  g.beginPath(); g.moveTo(-rx * 0.6, ry * 0.95); g.quadraticCurveTo(0, ry * 1.05, rx * 0.6, ry * 0.92); g.lineWidth = 1.5; g.stroke();
  g.restore();
}

/* mouth shapes: open = jaw drop, wide = corner spread, round = pucker, tu/tl = upper/lower teeth,
   tongue (low), tongueUp (L), tongueOut (TH), bite (F/V: teeth on lip), press (M/B/P) */
const VIS = {
  X:   {},
  MBP: {wide:0.92, press:1},
  FV:  {open:0.1, bite:1},
  TH:  {open:0.16, tu:1, tongueOut:1},
  L:   {open:0.42, tu:0.8, tongueUp:1},
  S:   {open:0.2, wide:1.12, tu:1, tl:1},
  CH:  {open:0.26, wide:0.72, round:0.5, tu:1, tl:1},
  R:   {open:0.28, wide:0.78, round:0.45, tu:0.6},
  EE:  {open:0.3, wide:1.3, tu:1, tl:0.8},
  E:   {open:0.45, wide:1.15, tu:0.8, tongue:0.3},
  AA:  {open:0.88, wide:1.05, tu:0.6, tongue:0.6},
  O:   {open:0.58, wide:0.7, round:0.75, tongue:0.3},
  OO:  {open:0.24, wide:0.5, round:1},
};
const MOUTH0 = {open:0, wide:1, round:0, tu:0, tl:0, tongue:0, tongueUp:0, tongueOut:0, bite:0, press:0};
for (const k in VIS) VIS[k] = {...MOUTH0, ...VIS[k]};
const VNAME = {X:'·', MBP:'mm', FV:'ff', TH:'th', L:'ll', S:'ss', CH:'sh', R:'rr', EE:'eee', E:'eh', AA:'ahh', O:'oh', OO:'ooo'};
const VCOL = {X:'#888', MBP:'#e63946', FV:'#8338ec', TH:'#b5179e', L:'#06d6a0', S:'#8d99ae', CH:'#9d4edd', R:'#f15bb5', EE:'#3a86ff', E:'#2ec4b6', AA:'#ff5c39', O:'#ff9f1c', OO:'#ffd23f'};
const VOWELS = new Set(['EE', 'E', 'AA', 'O', 'OO']);

function drawMouth(g, C, R, m, lw) {
  const lip = C.lips === 'auto' ? shade(C.skin, -0.45) : C.lips;
  const smile = C.mood === 'happy' ? 0.07 : C.mood === 'grumpy' ? -0.05 : 0.01;
  const a = R * 0.31 * m.wide * (1 - 0.42 * m.round) * (1 - 0.08 * m.press);
  const h = R * 0.52 * m.open, bt = h * 0.28, bb = h * 0.72;
  const c = smile * R * (1 - m.round) * (1 - 0.6 * Math.min(1, m.open));   // corners lift when smiling
  const kx = a * (0.42 + 0.55 * m.round);                                   // pointy lens → round O
  const yc = mid => (8 * mid + 2 * c) / 6;                                  // cubic control y so the midpoint lands on `mid`
  const path = () => {
    g.beginPath(); g.moveTo(-a, -c);
    g.bezierCurveTo(-kx, yc(-bt), kx, yc(-bt), a, -c);
    g.bezierCurveTo(kx, yc(bb), -kx, yc(bb), -a, -c);
    g.closePath();
  };
  g.save(); g.translate(0, R * 0.47);
  if (h > 1.5) {
    path(); g.fillStyle = MOUTH_DARK; g.fill();
    g.save(); path(); g.clip();
    if (m.tongue > 0.02) { ell(g, 0, bb - h * 0.05, a * 0.62, h * 0.42 * m.tongue + 2); g.fillStyle = TONGUE; g.fill(); }
    if (m.tongueUp > 0.02) { ell(g, 0, -bt + h * 0.28, a * 0.42, h * 0.3 * m.tongueUp); g.fillStyle = TONGUE; g.fill(); }
    g.fillStyle = '#fff';
    if (m.tu > 0.02) g.fillRect(-a, -bt - 6, 2 * a, 6 + Math.min(h * 0.5, R * 0.09) * m.tu);
    if (m.tl > 0.02) { const th = Math.min(h * 0.4, R * 0.07) * m.tl; g.fillRect(-a, bb - th, 2 * a, th + 6); }
    g.restore();
  }
  path(); g.lineWidth = lw + 2 + m.round * 4 + m.press * 3; g.strokeStyle = STY === 'neon' ? neon(lip) : lip; glow(g, g.strokeStyle); g.stroke(); glow(g, '');
  if (m.bite > 0.05) { rrect(g, -a * 0.55, -bt - 2, a * 1.1, R * 0.075 * m.bite, 3); fillStroke(g, '#fff', 2.5); }
  if (m.tongueOut > 0.05) { ell(g, 0, bb * 0.6, a * 0.35, R * 0.06 * m.tongueOut); fillStroke(g, TONGUE, 2.5); }
  g.restore();
  return bb;
}

</script>
<?php endif; ?>
<?php if ($page === 'toon'): ?>
<script>
/* ═══════════════ subtitles + captions (drawn into the video) ═══════════════ */
const FONTS = <?= json_encode(TOON_FONTS) ?>;
const fontStr = (name, px) => `${(FONTS.find(f => f[0] === name) || FONTS[0])[1]} ${Math.round(px)}px "${name}", sans-serif`;
const SUB = {style: 'karaoke', font: 'Luckiest Guy', pos: 'bottom', size: 1, color: '#ffffff', hi: '#ffd23f', box: false};
try { Object.assign(SUB, JSON.parse(localStorage.toonSubs || '{}')); } catch (e) {}
let CAPS = [];
function textY(pos, W, H) { return pos === 'middle' ? H * 0.5 : pos === 'bottom' ? H * (H > W ? 0.8 : 0.88) : H * (H > W ? 0.2 : 0.12); }
function outlined(g, text, x, y, fill, fs) { g.lineJoin = 'round'; g.lineWidth = fs * 0.18; g.strokeStyle = '#000'; g.strokeText(text, x, y); g.fillStyle = fill; g.fillText(text, x, y); }
function fitFont(g, font, fs, text, maxW) {   // shrink until it fits on one line
  g.font = fontStr(font, fs);
  const w = g.measureText(text).width;
  if (w > maxW) { fs *= maxW / w; g.font = fontStr(font, fs); }
  return fs;
}
function wrapText(g, text, maxW) {
  const lines = []; let cur = '';
  for (const w of text.split(/\s+/).filter(Boolean)) { const s = cur ? cur + ' ' + w : w; if (cur && g.measureText(s).width > maxW) { lines.push(cur); cur = w; } else cur = s; }
  if (cur) lines.push(cur);
  return lines;
}
function drawSubtitles(g, W, H, t) {
  if (SUB.style === 'off' || !TL || !TL.lines.length) return;
  const ln = TL.lines.find(l => l.words.length && t >= l.start - 0.15 && t <= l.end + 0.5);
  if (!ln) return;
  const y = textY(SUB.pos, W, H), base = Math.min(W, H) * 0.06 * SUB.size;
  g.textBaseline = 'middle';
  if (SUB.style === 'pop') {   // one word at a time, punching in
    const cur = [...ln.words].reverse().find(w => w[0] <= t + 0.02) || ln.words[0];
    const fs = fitFont(g, SUB.font, base * 1.6, cur[2], W * 0.9), k = 1 + 0.3 * Math.max(0, 1 - (t - cur[0]) / 0.15);
    g.save(); g.translate(W / 2, y); g.scale(k, k); g.textAlign = 'center';
    if (SUB.box) { const tw = g.measureText(cur[2]).width; g.fillStyle = 'rgba(0,0,0,.6)'; rrect(g, -tw / 2 - fs * 0.4, -fs * 0.75, tw + fs * 0.8, fs * 1.5, fs * 0.25); g.fill(); }
    outlined(g, cur[2], 0, 0, SUB.hi, fs);
    g.restore(); return;
  }
  const text = ln.words.map(w => w[2]).join(' '), fs = fitFont(g, SUB.font, base, text, W * 0.9), tw = g.measureText(text).width, sp = g.measureText(' ').width;
  if (SUB.box) { g.fillStyle = 'rgba(0,0,0,.6)'; rrect(g, (W - tw) / 2 - fs * 0.4, y - fs * 0.75, tw + fs * 0.8, fs * 1.5, fs * 0.25); g.fill(); }
  g.textAlign = 'left';
  let x = (W - tw) / 2;
  for (const w of ln.words) {
    outlined(g, w[2], x, y, SUB.style === 'karaoke' && t >= w[0] && t <= w[1] + 0.05 ? SUB.hi : SUB.color, fs);
    x += g.measureText(w[2]).width + sp;
  }
}
function drawCaptions(g, W, H, t) {
  const base = Math.min(W, H);
  for (const c of CAPS) {
    if (t < c.start || t > c.end || !c.text.trim()) continue;
    const ease = smoothstep(Math.min(1, (t - c.start) / 0.3));
    g.save();
    g.globalAlpha = Math.max(0, Math.min(1, (t - c.start) / 0.12, (c.end - t) / 0.2));
    g.textBaseline = 'middle';
    if (c.kind === 'title') {
      const fs = fitFont(g, c.font, base * 0.1 * c.size, c.text, W * 0.9);
      g.translate(W / 2, H * (H > W ? 0.12 : 0.13)); g.scale(0.6 + 0.4 * ease, 0.6 + 0.4 * ease); g.textAlign = 'center';
      outlined(g, c.text, 0, 0, c.color, fs);
    } else if (c.kind === 'lower') {
      const fs = fitFont(g, c.font, base * 0.055 * c.size, c.text, W * 0.8), tw = g.measureText(c.text).width, pad = fs * 0.5;
      const x = W * 0.05 - (1 - ease) * (tw + W * 0.15), y = H * (H > W ? 0.7 : 0.78);
      g.fillStyle = 'rgba(0,0,0,.35)'; g.fillRect(x + 8, y - fs * 0.75 + 8, tw + pad * 2 + 12, fs * 1.5);
      g.fillStyle = c.color; g.fillRect(x, y - fs * 0.75, tw + pad * 2 + 12, fs * 1.5);
      g.fillStyle = '#fff'; g.fillRect(x, y - fs * 0.75, 12, fs * 1.5);
      const n = parseInt(c.color.slice(1), 16), lum = ((n >> 16) * 0.3 + (n >> 8 & 255) * 0.59 + (n & 255) * 0.11) / 255;
      g.textAlign = 'left'; g.fillStyle = lum > 0.6 ? '#111' : '#fff'; g.fillText(c.text, x + 12 + pad, y);
    } else {   // speech bubble next to the head, tail pointing at the mouth
      const fs = base * 0.045 * c.size;
      g.font = fontStr(c.font, fs);
      const lines = wrapText(g, c.text, W * (H > W ? 0.6 : 0.36)), tw = Math.max(...lines.map(l => g.measureText(l).width));
      const pad = fs * 0.6, bw = tw + pad * 2, bh = lines.length * fs * 1.25 + pad * 1.4, hp = headAnchor(W, H);
      const bx = Math.max(16, Math.min(W - bw - 16, hp.x + hp.r * 0.9)), by = Math.max(16, Math.min(H - bh - 16, hp.y - hp.r * 1.1 - bh));
      const tipX = hp.x + hp.r * 0.4, tipY = hp.y + hp.r * 0.3, baseX = Math.max(bx + 20, Math.min(bx + bw - 60, tipX + 10));
      const k = 0.5 + 0.5 * ease;
      g.translate(tipX, tipY); g.scale(k, k); g.translate(-tipX, -tipY);
      rrect(g, bx, by, bw, bh, Math.min(bh / 2, fs)); fillStroke(g, '#fff', 4);
      g.beginPath(); g.moveTo(baseX, by + bh - 4); g.lineTo(tipX, tipY); g.lineTo(baseX + 36, by + bh - 4); g.closePath(); g.fillStyle = '#fff'; g.fill();
      g.beginPath(); g.moveTo(baseX, by + bh); g.lineTo(tipX, tipY); g.lineTo(baseX + 36, by + bh); g.lineWidth = 4; g.strokeStyle = INK; g.stroke();
      g.textAlign = 'center'; g.fillStyle = c.color;
      lines.forEach((l, i) => g.fillText(l, bx + bw / 2, by + pad * 0.7 + fs * 1.25 * (i + 0.5)));
    }
    g.restore();
  }
}

/* ═══════════════ audio → mouth shapes ═══════════════ */
// IPA characters → mouth shape. A phoneme token can be a diphthong ("aɪ", "oʊ"); we keep its first two shapes.
const PH = [['mbpɱ', 'MBP'], ['fvɸβ', 'FV'], ['θð', 'TH'], ['lɫɭʎʟ', 'L'], ['wʍɥ', 'OO'], ['ʃʒɕʑʂʐ', 'CH'], ['ɹrɾɻʁɽʀ', 'R'], ['jʝ', 'EE'],
  ['aɑæɐʌɶä', 'AA'], ['oɔɒøœɵ', 'O'], ['uʊɯyʏ', 'OO'], ['eɛəɜɚɘᵻɞ', 'E'], ['iɪɨ', 'EE']];
function phoneVis(p) {
  if (/^(tʃ|dʒ)/.test(p)) return ['CH'];
  const out = [];
  for (const ch of p) {
    const hit = PH.find(([set]) => set.includes(ch));
    const v = hit ? hit[1] : /[\p{Ll}\p{Lu}\p{Lo}]/u.test(ch) ? 'S' : null;   // skips length marks/stress (modifier letters)
    if (v && out[out.length - 1] !== v) out.push(v);
  }
  return out.length ? out.slice(0, 2) : ['S'];
}
function eventsFromPhones(phones, dur) {
  const ev = [];
  phones.forEach(([t, p], i) => {
    const next = i + 1 < phones.length ? phones[i + 1][0] : dur, vis = phoneVis(p);
    const end = VOWELS.has(vis[vis.length - 1]) ? next : Math.min(next, t + 0.25);
    if (vis.length > 1) { const mid = t + (end - t) * 0.5; ev.push({t, end: mid, v: vis[0], label: p}, {t: mid, end, v: vis[1], label: ''}); }
    else ev.push({t, end, v: vis[0], label: p});
  });
  return ev;
}

function toMono(buf) {
  const n = buf.length, out = new Float32Array(n);
  for (let c = 0; c < buf.numberOfChannels; c++) { const d = buf.getChannelData(c); for (let i = 0; i < n; i++) out[i] += d[i] / buf.numberOfChannels; }
  return out;
}
function rmsEnv(x, sr) {   // RMS every 10ms, scaled so loud parts are ~1
  const hop = Math.round(sr * 0.01), n = Math.floor(x.length / hop), e = new Float32Array(n);
  for (let i = 0; i < n; i++) { let s = 0; for (let j = i * hop, k = j + hop; j < k; j++) s += x[j] * x[j]; e[i] = Math.sqrt(s / hop); }
  const v = Array.from(e).filter(a => a > 1e-4).sort((a, b) => a - b), ref = v.length ? v[Math.floor(v.length * 0.95)] : 1;
  for (let i = 0; i < n; i++) e[i] = Math.min(1.5, e[i] / ref);
  return e;
}

// Instant mode: zero-crossings spot hissy consonants; LPC formants (F1 = jaw, F2 = tongue front/back) pick the vowel
let TRIG = null, FR = new Float64Array(0);
function formants(fr, dsr) {
  const p = 12, n = fr.length;
  if (!TRIG || TRIG.sr !== dsr) {
    TRIG = {sr: dsr, f: [], c: [], s: []};
    for (let f = 150; f <= 4000; f += 25) {
      const w = 2 * Math.PI * f / dsr;
      TRIG.f.push(f); TRIG.c.push(Float64Array.from({length: p + 1}, (_, j) => Math.cos(w * j))); TRIG.s.push(Float64Array.from({length: p + 1}, (_, j) => Math.sin(w * j)));
    }
  }
  const w = new Float64Array(n);
  for (let i = 0; i < n; i++) w[i] = (fr[i] - (i ? 0.97 * fr[i - 1] : 0)) * (0.54 - 0.46 * Math.cos(2 * Math.PI * i / (n - 1)));
  const r = new Float64Array(p + 1);
  for (let k = 0; k <= p; k++) { let s = 0; for (let i = k; i < n; i++) s += w[i] * w[i - k]; r[k] = s; }
  if (r[0] < 1e-10) return null;
  r[0] *= 1.0001;
  const a = new Float64Array(p + 1); a[0] = 1; let err = r[0];   // Levinson-Durbin
  for (let i = 1; i <= p; i++) {
    let acc = r[i]; for (let j = 1; j < i; j++) acc += a[j] * r[i - j];
    const k = -acc / err, prev = a.slice();
    for (let j = 1; j < i; j++) a[j] = prev[j] + k * prev[i - j];
    a[i] = k; err *= 1 - k * k; if (err <= 0) break;
  }
  const peaks = []; let m1 = 0, m2 = 0;
  for (let q = 0; q < TRIG.f.length; q++) {
    let re = 0, im = 0; const c = TRIG.c[q], s = TRIG.s[q];
    for (let j = 0; j <= p; j++) { re += a[j] * c[j]; im -= a[j] * s[j]; }
    const mag = 1 / (re * re + im * im);
    if (q > 1 && m1 > m2 && m1 > mag && TRIG.f[q - 1] >= 220) peaks.push(TRIG.f[q - 1]);
    m2 = m1; m1 = mag;
  }
  return peaks;
}
function vowelOf(pk) {
  if (!pk || !pk.length) return 'E';
  const F1 = pk[0], F2 = pk[1] || 0;
  if (F1 >= 650) return 'AA';
  if (!F2 || F2 < 1150) return F1 < 420 ? 'OO' : 'O';
  if (F2 > 1850) return F1 < 400 ? 'EE' : 'E';
  return F1 > 520 ? 'AA' : 'E';
}
function classifyFrame(x, o, win, sr, env) {
  if (env < 0.05) return 'X';
  let z = 0; for (let j = o + 1; j < o + win; j++) if ((x[j] >= 0) !== (x[j - 1] >= 0)) z++;
  if (z / win * sr / 2 > 2500) return 'S';
  const dec = Math.max(1, Math.round(sr / 11025)), n = Math.floor(win / dec);
  if (FR.length !== n) FR = new Float64Array(n);
  for (let k = 0; k < n; k++) { let s = 0; for (let d = 0; d < dec; d++) s += x[o + k * dec + d]; FR[k] = s / dec; }
  return vowelOf(formants(FR, sr / dec));
}
async function analyzeInstant(x, sr) {
  const hop = Math.round(sr * 0.01), win = Math.round(sr * 0.03), env = rmsEnv(x, sr), n = env.length, cls = new Array(n);
  for (let i = 0; i < n; i++) {
    const o = Math.min(i * hop, x.length - win);
    cls[i] = o < 0 ? 'X' : classifyFrame(x, o, win, sr, env[i]);
    if (i % 3000 === 2999) await new Promise(r => setTimeout(r));   // keep the page responsive on long songs
  }
  const sm = cls.map((c, i) => {   // majority vote over ±30ms
    const cnt = {}; let best = c, bc = 0;
    for (let j = Math.max(0, i - 3); j <= Math.min(n - 1, i + 3); j++) { const k = cls[j]; cnt[k] = (cnt[k] || 0) + 1; if (cnt[k] > bc || (cnt[k] === bc && k === c)) { bc = cnt[k]; best = k; } }
    return best;
  });
  const quiet = i => sm[i] === 'X' || (env[i] < 0.12 && sm[i] !== 'S');
  for (let i = 1; i < n;) {   // short dips between vowels are lip closures (p/b/m)
    if (!quiet(i)) { i++; continue; }
    let j = i; while (j < n && quiet(j)) j++;
    if (j - i >= 3 && j - i <= 14 && VOWELS.has(sm[i - 1]) && j < n && VOWELS.has(sm[j])) for (let k = i; k < j; k++) sm[k] = 'MBP';
    i = j;
  }
  const ev = [];
  for (let i = 0; i < n;) {
    let j = i; while (j < n && sm[j] === sm[i]) j++;
    const last = ev[ev.length - 1];
    if (last && j - i < 4 && sm[i] !== 'MBP' && last.end === i * 0.01) last.end = j * 0.01;
    else ev.push({t: i * 0.01, end: j * 0.01, v: sm[i], label: ''});
    i = j;
  }
  return {dur: x.length / sr, env, events: ev.filter(e => e.v !== 'X'), words: []};
}

function findOnsets(env) {   // syllable punches → nods and gestures
  const out = []; let last = -99;
  const sm = Float32Array.from(env, (_, i) => (env[i - 1] ?? env[i]) * 0.25 + env[i] * 0.5 + (env[i + 1] ?? env[i]) * 0.25);
  for (let i = 8; i < sm.length - 1; i++) {
    const d = sm[i] - sm[i - 8];
    if (d > 0.22 && sm[i] > 0.35 && d >= sm[i + 1] - sm[i - 7] && i - last > 28) { out.push([i * 0.01, Math.min(1, d * 1.6)]); last = i; }
  }
  return out;
}
function findBeats(env) {   // tempo by autocorrelating energy rises, phase by best alignment
  const n = env.length, o = new Float32Array(n);
  for (let i = 1; i < n; i++) o[i] = Math.max(0, env[i] - env[i - 1]);
  let best = 0, L = 0;
  for (let lag = 33; lag <= 100; lag++) {
    let s = 0; for (let i = lag; i < n; i++) s += o[i] * o[i - lag];
    s /= 1 + Math.log2(lag / 50) ** 2;   // lean toward ~120 BPM
    if (s > best) { best = s; L = lag; }
  }
  if (!L) return [];
  let bp = 0, bs = -1;
  for (let p = 0; p < L; p++) { let s = 0; for (let i = p + 1; i < n - 1; i += L) s += o[i - 1] + o[i] + o[i + 1]; if (s > bs) { bs = s; bp = p; } }
  const beats = []; for (let i = bp; i < n; i += L) beats.push(i * 0.01);
  return beats;
}

let TL = null;   // timeline: {dur, env, events, starts, words, lines, onsets, mode}
function setTimeline(r, mode) {
  const env = r.env instanceof Float32Array ? r.env : Float32Array.from(r.env);
  const events = r.events || eventsFromPhones(r.phones || [], r.duration);
  const words = r.words || [], lines = [];
  let cur = null;
  for (const w of words) {
    if (!cur || w[0] - cur.end > 0.7 || cur.chars + w[2].length > 32) { cur = {start: w[0], end: w[1], words: [], chars: 0}; lines.push(cur); }
    cur.words.push(w); cur.end = w[1]; cur.chars += w[2].length + 1;
  }
  TL = {dur: r.dur || r.duration, env, events, starts: events.map(e => e.t), words, lines, onsets: findOnsets(env), mode};
  resetMotion(); renderSubLines();
}
function visAt(t) {
  if (!TL) return 'X';
  const st = TL.starts; let lo = 0, hi = st.length - 1, k = -1;
  while (lo <= hi) { const mid = (lo + hi) >> 1; if (st[mid] <= t) { k = mid; lo = mid + 1; } else hi = mid - 1; }
  if (k < 0 || t > TL.events[k].end || envAt(t) < 0.04) return 'X';
  return TL.events[k].v;
}
function envAt(t) {
  const e = TL ? TL.env : A.mixEnv;
  return e && t >= 0 ? e[Math.min(e.length - 1, Math.floor(t / 0.01))] || 0 : 0;
}

/* ═══════════════ audio loading + playback ═══════════════ */
const A = {file: null, job: '', name: '', buf: null, ana: null, sr: 0, mixEnv: null, beats: []};
const P = {ctx: null, out: null, src: null, startAt: 0, offset: 0, playing: false};
let REC = null, loadToken = 0, ENGINE_READY = false;
const CAMX = {   // 🎥 camera motion capture state (code further down)
  on: false, busy: false, stream: null, pose: null, face: null, lastVT: -1, lastTs: 0, rig: null, raw: null, fps: 0, nFr: 0, fpsT: 0,
  parts: new Set(['face', 'arms', 'body']), mouth: 'face', take: null, taking: null};
function ensureCtx() {
  if (!P.ctx) { P.ctx = new AudioContext(); P.out = P.ctx.createGain(); P.out.connect(P.ctx.destination); }
  if (P.ctx.state === 'suspended') P.ctx.resume();
}
function curTime() {
  if (!P.playing) return P.offset;
  const lat = REC ? 0 : (P.ctx.outputLatency || 0) + (P.ctx.baseLatency || 0);   // recordings tap the graph before the speakers
  return Math.max(0, P.ctx.currentTime - P.startAt - lat);
}
function stopSrc() { bgmStop(); if (P.src) { const s = P.src; P.src = null; try { s.stop(); } catch (e) {} } }
function play(from = P.offset) {
  if (!A.buf) return;
  ensureCtx(); stopSrc();
  if (from >= A.buf.duration - 0.05) from = 0;
  const s = P.ctx.createBufferSource(); s.buffer = A.buf; s.connect(P.out);
  s.onended = () => { if (P.src !== s) return; P.src = null; P.playing = false; P.offset = 0; resetMotion(); updButtons(); if (REC) REC.stop(); if (CAMX.taking && CAMX.taking.audio) takeStop(); };
  s.start(0, from); P.src = s; P.startAt = P.ctx.currentTime - from; P.playing = true; bgmStart(from);
  resetMotion(); updButtons();
}
function pause() { if (!P.playing) return; P.offset = curTime(); stopSrc(); P.playing = false; updButtons(); }
function seek(t) { if (P.playing) play(t); else { P.offset = t; resetMotion(); } }

async function loadAudio(blob, name, job = '', vocals = null) {
  const token = ++loadToken;
  ensureCtx(); stopSrc(); P.playing = false; P.offset = 0; stopMic();
  showAnErr(''); $('#anInfo').textContent = 'Loading audio…';
  try {
    const buf = await P.ctx.decodeAudioData(await blob.arrayBuffer());
    const ana = vocals ? toMono(await P.ctx.decodeAudioData(await vocals.arrayBuffer())) : null;
    if (token !== loadToken) return;
    Object.assign(A, {file: blob, job, name, buf, sr: buf.sampleRate, ana});
    const mono = toMono(buf);
    A.mixEnv = rmsEnv(mono, A.sr); A.beats = findBeats(A.mixEnv);
    if (!A.ana) A.ana = mono;
    $('#audioDrop').classList.add('has'); $('#audioDrop').firstChild.textContent = '✓ ' + name + ' ';
    TL = null; updButtons(); loadCaps();
    await runInstant(token);
    if (token === loadToken) $('#anInfo').textContent = `⚡ Quick sync ready (${fmtTime(buf.duration)}). ` + ($('input[name=amode]:checked').value === 'precise' ? 'Hit "Sync the mouth" for the Precise version.' : '');
  } catch (e) {
    if (token === loadToken) { $('#anInfo').textContent = ''; showAnErr('Could not read that audio: ' + e.message); }
  }
}
async function runInstant(token) {
  const r = await analyzeInstant(A.ana, A.sr);
  if (token === loadToken) setTimeline(r, 'instant');
}
async function runPrecise(token) {
  const fd = new FormData();
  if (A.job) fd.append('job', A.job); else fd.append('audio', A.file, A.name);
  if ($('#isolate').checked) fd.append('isolate', '1');
  if ($('#words').checked) fd.append('words', '1');
  const {id} = await api('toon_analyze', fd);
  $('#anProg').hidden = false;
  for (;;) {
    await new Promise(r => setTimeout(r, 1000));
    if (token !== loadToken) return;
    const s = await api('toon_status&id=' + id);
    $('#anProg i').style.width = Math.round((s.progress || 0) * 100) + '%';
    $('#anStage').textContent = s.stage || '';
    if (s.state === 'done') { setTimeline(s.result, 'precise'); return s; }
    if (s.state === 'error') throw new Error((s.error || 'Analysis failed') + (s.log ? '\n\n' + s.log.trim().split('\n').slice(-6).join('\n') : ''));
  }
}
$('#analyzeBtn').onclick = () => syncMouth();
async function syncMouth() {
  if (!A.buf) return;
  const btn = $('#analyzeBtn'), token = loadToken, precise = $('input[name=amode]:checked').value === 'precise';
  btn.disabled = true; btn.textContent = 'Syncing…'; showAnErr('');
  try {
    if (precise) {
      const s = await runPrecise(token);
      if (s) $('#anInfo').textContent = `🔬 Precise sync: ${s.result.phones.length} speech sounds` + (s.result.words.length ? `, ${s.result.words.length} words` : '') + ` · took ${s.seconds}s`;
    } else {
      await runInstant(token);
      $('#anInfo').textContent = '⚡ Instant sync done.';
    }
  } catch (e) { showAnErr(e.message); }
  $('#anProg').hidden = true; btn.textContent = 'Sync the mouth'; updButtons();
}
function showAnErr(m) { $('#anErr').textContent = m; $('#anErr').hidden = !m; }

// sources: file drop, Voice Swap results, live mic
const drop = $('#audioDrop'), fileIn = $('#audioFile');
drop.onclick = () => fileIn.click();
fileIn.onchange = () => fileIn.files.length && (($('#swapJob').value = ''), loadAudio(fileIn.files[0], fileIn.files[0].name));
drop.ondragover = e => { e.preventDefault(); drop.classList.add('over'); };
drop.ondragleave = () => drop.classList.remove('over');
drop.ondrop = e => { e.preventDefault(); drop.classList.remove('over'); const f = e.dataTransfer.files[0]; if (f) { $('#swapJob').value = ''; loadAudio(f, f.name); } };

let swapJobs = [];
async function loadSwapJobs(select) {   // resolves once the selected result's audio is loaded
  swapJobs = (await api('jobs').catch(() => [])).filter(j => j.status.state === 'done' && j.status.files && j.status.files.vocals);
  $('#swapJob').innerHTML = '<option value="">…or use a Voice Swap result</option>' + swapJobs.map(j => `<option value="${j.id}">🎤 ${esc(j.job.title)}${j.job.voice_name ? ' · ' + esc(j.job.voice_name) : ''}</option>`).join('');
  if (select && swapJobs.some(j => j.id === select)) { $('#swapJob').value = select; await $('#swapJob').onchange(); }
}
$('#swapJob').onchange = async () => {
  const j = swapJobs.find(x => x.id === $('#swapJob').value);
  if (!j) return;
  const f = j.status.files, url = n => `?a=file&id=${j.id}&f=${encodeURIComponent(n)}`;
  $('#anInfo').textContent = 'Loading Voice Swap result…';
  try {
    const mix = await (await fetch(url(f.mix || f.vocals))).blob();
    const voc = f.mix ? await (await fetch(url(f.vocals))).blob() : null;
    await loadAudio(mix, j.job.title, j.id, voc);   // play the full mix, read the mouth off the clean vocals
  } catch (e) { showAnErr(e.message); }
};

const LIVE = {stream: null, src: null, an: null, buf: null, peak: 0.02, cls: [], hist: [], lastOnset: -9};
async function startMic() {
  ensureCtx(); pause();
  try { LIVE.stream = await navigator.mediaDevices.getUserMedia({audio: {echoCancellation: true, noiseSuppression: true}}); }
  catch (e) { showAnErr('Microphone not available: ' + e.message); return; }
  LIVE.src = P.ctx.createMediaStreamSource(LIVE.stream);
  LIVE.an = P.ctx.createAnalyser(); LIVE.an.fftSize = 2048; LIVE.src.connect(LIVE.an);
  LIVE.buf = new Float32Array(2048); LIVE.peak = 0.02; LIVE.cls = []; LIVE.hist = [];
  resetMotion(); updButtons();
}
function stopMic() {
  if (!LIVE.stream) return;
  if (REC) REC.stop();
  LIVE.stream.getTracks().forEach(t => t.stop());
  LIVE.stream = LIVE.src = LIVE.an = null; resetMotion(); updButtons();
}
$('#micBtn').onclick = () => LIVE.an ? stopMic() : startMic();
function liveFrame(dt, T) {
  LIVE.an.getFloatTimeDomainData(LIVE.buf);
  const sr = P.ctx.sampleRate, win = Math.round(sr * 0.03), o = LIVE.buf.length - win;
  let s = 0; for (let j = o; j < LIVE.buf.length; j++) s += LIVE.buf[j] * LIVE.buf[j];
  const rms = Math.sqrt(s / win);
  LIVE.peak = Math.max(rms, LIVE.peak * Math.pow(0.5, dt / 4), 0.01);   // auto-gain, 4s half-life
  const env = Math.min(1.5, rms / (LIVE.peak * 0.8));
  LIVE.cls.push(classifyFrame(LIVE.buf, o, win, sr, env)); if (LIVE.cls.length > 4) LIVE.cls.shift();
  const cnt = {}; let vis = 'X', bc = 0;
  for (const c of LIVE.cls) { cnt[c] = (cnt[c] || 0) + 1; if (cnt[c] >= bc) { bc = cnt[c]; vis = c; } }
  LIVE.hist.push([T, env]); while (LIVE.hist.length && T - LIVE.hist[0][0] > 0.08) LIVE.hist.shift();
  let onset = 0;
  const d = env - LIVE.hist[0][1];
  if (d > 0.22 && env > 0.35 && T - LIVE.lastOnset > 0.28) { onset = Math.min(1, d * 1.6); LIVE.lastOnset = T; }
  return {env, vis, onset};
}

/* ═══════════════ motion ═══════════════ */
const GESTURES = {   // sh = shoulder swing out from the body, el = elbow bend (radians)
  palms: {w: 3, L: {sh: 0.55, el: 1.15}, R: {sh: 0.55, el: 1.15}},
  point: {w: 2, R: {sh: 1.45, el: 0.08}},
  chest: {w: 1.5, R: {sh: 0.35, el: -2.1}},
  shrug: {w: 1, L: {sh: 0.35, el: 1.7}, R: {sh: 0.35, el: 1.7}, shrug: 14},
  raise: {w: 1, R: {sh: 2.5, el: 0.35}, loud: true},
  fist:  {w: 0.8, R: {sh: 2.0, el: 1.9}, loud: true},
  hips:  {w: 0.6, L: {sh: 0.75, el: -1.9}, R: {sh: 0.75, el: -1.9}},
  wave:  {w: 0.4, R: {sh: 2.3, el: 0.5}, wave: true},
};
const M = {};
function resetMotion() { Object.assign(M, {env: 0, mouth: {...MOUTH0}, nods: [], gest: null, gestEnd: -9, nextBlink: 0, blinkT: -9, look: {x: 0, y: 0}, lookT: {x: 0, y: 0}, nextLook: 0, brow: 0, lastT: null}); }
resetMotion();
const noise = t => (Math.sin(t * 1.7) + Math.sin(t * 2.9 + 1.3) * 0.6 + Math.sin(t * 4.3 + 2.1) * 0.3) / 1.9;
const smoothstep = x => x <= 0 ? 0 : x >= 1 ? 1 : x * x * (3 - 2 * x);
function startGesture(T, strength) {
  const pool = Object.entries(GESTURES).filter(([, g]) => !g.loud || strength > 0.55);
  let r = Math.random() * pool.reduce((s, [, g]) => s + g.w, 0), kind = pool[0][0];
  for (const [k, g] of pool) { r -= g.w; if (r <= 0) { kind = k; break; } }
  const g = GESTURES[kind], lockR = (PROPS[C.prop] || {}).lock === 'R';   // a hand holding a mic gestures with the other one
  M.gest = {kind, t0: T, t1: T + 0.7 + Math.random() * 0.9 + strength * 0.4, flip: lockR ? !!(g.R && !g.L) : Math.random() < 0.4};
}
function stepMotion(dt, T, env, vis, onset, beat) {
  const E = +$('#energy').value, G = +$('#gestures').value, MA = +$('#mouthAmt').value;
  const k = rate => 1 - Math.exp(-dt * rate);
  M.env += (env - M.env) * k(env > M.env ? 30 : 10);
  // mouth: ease toward the target shape; louder = wider jaw
  const tgt = VIS[vis] || VIS.X, loud = Math.min(1.25, 0.5 + 0.7 * M.env), rate = vis === 'MBP' ? 45 : 26;
  for (const key in tgt) {
    let v = tgt[key];
    if (key === 'open') v = Math.min(1.1, v * loud * MA);
    if (key === 'wide') v = 1 + (v - 1) * Math.min(1.3, MA);
    M.mouth[key] += (v - M.mouth[key]) * k(rate);
  }
  // syllable punches → head nods, sometimes a hand gesture
  if (onset) {
    M.nods.push([T, onset]);
    if (G > 0 && !M.gest && T - M.gestEnd > 0.6 && Math.random() < G * (0.3 + onset * 0.8)) startGesture(T, onset);
  }
  M.nods = M.nods.filter(([t0]) => T - t0 < 0.4);
  let nod = 0; for (const [t0, s] of M.nods) nod += s * Math.sin(Math.min(1, (T - t0) / 0.3) * Math.PI);
  let gw = 0, gp = null;
  if (M.gest) {
    gw = Math.min(smoothstep((T - M.gest.t0) / 0.22), smoothstep((M.gest.t1 - T) / 0.3));
    if (T >= M.gest.t1) { M.gest = null; M.gestEnd = T; } else gp = GESTURES[M.gest.kind];
  }
  const arm = side => {
    const ph = side === 'L' ? 0 : 2;
    const rest = {sh: 0.13 + 0.04 * Math.sin(T * 1.3 + ph), el: 0.18 + 0.06 * Math.sin(T * 1.9 + ph) + M.env * 0.3 * E * (side === 'R' ? 1 : 0.5)};
    if (!gp) return {rest};
    const key = M.gest.flip ? (side === 'L' ? 'R' : 'L') : side, g = gp[key];
    if (!g) return {rest};
    return {rest, g: {sh: g.sh, el: g.el + (gp.wave ? Math.sin(T * 14) * 0.5 : 0)}, w: gw};
  };
  // body
  let bob = M.env * 7 * E, lean = (noise(T * 0.35) * 0.035 + M.env * 0.02) * E, tilt = noise(T * 0.5 + 7) * 0.06 * E + nod * 0.03 * E, headY = nod * 9 * E;
  if (beat) { const dip = Math.exp(-beat.ph * 5); bob += dip * 12 * E; headY += dip * 6 * E; lean += Math.sin(Math.PI * (beat.i + beat.ph)) * 0.04 * E; }
  // eyes
  if (T >= M.nextBlink) { M.blinkT = T; M.nextBlink = T + 1.8 + Math.random() * 3.5; }
  const blink = T - M.blinkT < 0.15 ? Math.sin(Math.PI * (T - M.blinkT) / 0.15) : 0;
  if (T >= M.nextLook) { M.lookT = {x: (Math.random() * 2 - 1) * 0.8, y: (Math.random() * 2 - 1) * 0.4}; M.nextLook = T + 0.8 + Math.random() * 2.5; }
  M.look.x += (M.lookT.x - M.look.x) * k(12); M.look.y += (M.lookT.y - M.look.y) * k(12);
  M.brow += (Math.min(1, Math.max(0, (M.env - 0.55) * 1.8)) * Math.min(1, E) + (M.gest ? 0.3 * gw : 0) - M.brow) * k(6);
  return {bob, lean, tilt, headY, squash: 1 + Math.sin(T * 1.7) * 0.008 - bob * 0.0015, shrug: gp && gp.shrug ? gp.shrug * gw : 0,
    armL: arm('L'), armR: arm('R'), blink, look: M.look, brow: M.brow, mouth: M.mouth, env: M.env, T};
}
function beatAt(t) {
  const b = A.beats; if (!$('#dance').checked || b.length < 2 || t < b[0]) return null;
  let lo = 0, hi = b.length - 1; while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (b[mid] <= t) lo = mid; else hi = mid - 1; }
  const len = (b[lo + 1] ?? b[lo] + (b[1] - b[0])) - b[lo];
  return {i: lo, ph: Math.min(1, (t - b[lo]) / len)};
}

/* ═══════════════ Realistic 3D: Three.js + a rigged GLB person (Mixamo skeleton, ARKit + Oculus face shapes) ═══════════════ */
// The 2D canvas still draws the background, subtitles and captions; the person is rendered by WebGL on a transparent canvas and composited in.
const R3 = {state: 'idle', av: null, want: '', err: '', cache: {}, vw: {}, lastT: null, head: null, tint: ''};
const OCULUS = {MBP: 'PP', FV: 'FF', TH: 'TH', L: 'nn', S: 'SS', CH: 'CH', R: 'RR', EE: 'I', E: 'E', AA: 'aa', O: 'O', OO: 'U'};
const R3FACE = ['eyeBlinkLeft', 'eyeBlinkRight', 'eyeLookInLeft', 'eyeLookOutLeft', 'eyeLookInRight', 'eyeLookOutRight', 'eyeLookUpLeft', 'eyeLookUpRight',
  'eyeLookDownLeft', 'eyeLookDownRight', 'eyeWideLeft', 'eyeWideRight', 'eyeSquintLeft', 'eyeSquintRight', 'browInnerUp', 'browOuterUpLeft', 'browOuterUpRight',
  'browDownLeft', 'browDownRight', 'mouthSmileLeft', 'mouthSmileRight', 'mouthFrownLeft', 'mouthFrownRight', 'cheekSquintLeft', 'cheekSquintRight',
  ...Object.values(OCULUS).map(v => 'viseme_' + v),
  // only the camera drives these (it reads all 52 ARKit shapes off your face)
  'jawOpen', 'jawForward', 'jawLeft', 'jawRight', 'mouthClose', 'mouthFunnel', 'mouthPucker', 'mouthLeft', 'mouthRight', 'mouthRollLower', 'mouthRollUpper',
  'mouthShrugLower', 'mouthShrugUpper', 'mouthPressLeft', 'mouthPressRight', 'mouthLowerDownLeft', 'mouthLowerDownRight', 'mouthUpperUpLeft', 'mouthUpperUpRight',
  'mouthDimpleLeft', 'mouthDimpleRight', 'mouthStretchLeft', 'mouthStretchRight', 'cheekPuff', 'noseSneerLeft', 'noseSneerRight'];
const R3BONES = ['Hips', 'Spine', 'Spine1', 'Spine2', 'Neck', 'Head', 'LeftShoulder', 'RightShoulder', 'LeftArm', 'RightArm', 'LeftForeArm', 'RightForeArm', 'LeftHand', 'RightHand',
  'LeftUpLeg', 'RightUpLeg', 'LeftLeg', 'RightLeg', 'LeftFoot', 'RightFoot'];
const avatarName = () => (SCHEMA.find(r => r[0] === 'avatar')[2].find(a => a[0] === C.avatar) || SCHEMA.find(r => r[0] === 'avatar')[2][0] || ['mpfb'])[0];

async function r3init() {
  const THREE = await import('./vendor/three/three.module.js');
  const [{GLTFLoader}, {RoomEnvironment}] = await Promise.all([import('./vendor/three/addons/loaders/GLTFLoader.js'), import('./vendor/three/addons/environments/RoomEnvironment.js')]);
  const cv = document.createElement('canvas');
  const ren = new THREE.WebGLRenderer({canvas: cv, alpha: true, antialias: true, preserveDrawingBuffer: true});
  ren.outputColorSpace = THREE.SRGBColorSpace; ren.toneMapping = THREE.ACESFilmicToneMapping; ren.toneMappingExposure = 0.9;
  ren.setClearColor(0x000000, 0); ren.shadowMap.enabled = true; ren.shadowMap.type = THREE.PCFSoftShadowMap;
  cv.addEventListener('webglcontextlost', e => { e.preventDefault(); R3.state = 'error'; R3.err = 'graphics memory ran out — reloading the 3D person'; R3.errAt = 0;
    for (const k in R3.cache) delete R3.cache[k]; R3.av = null; R3.THREE = null; });
  const scene = new THREE.Scene(), pm = new THREE.PMREMGenerator(ren);
  scene.environment = pm.fromScene(new RoomEnvironment(), 0.04).texture; scene.environmentIntensity = 0.4;
  const key = new THREE.DirectionalLight(0xfff1e0, 2.0); key.position.set(-1.6, 3.2, 2.6); key.castShadow = true;
  Object.assign(key.shadow.camera, {left: -1.2, right: 1.2, top: 2.2, bottom: -0.2, near: 0.5, far: 8});
  key.shadow.mapSize.set(2048, 2048); key.shadow.bias = -0.0004; key.shadow.normalBias = 0.02;
  const rim = new THREE.DirectionalLight(0xbcd6ff, 1.8); rim.position.set(1.8, 2.6, -2.2);
  const fill = new THREE.DirectionalLight(0xffffff, 0.45); fill.position.set(2, 1.2, 3);
  const floor = new THREE.Mesh(new THREE.PlaneGeometry(8, 8), new THREE.ShadowMaterial({opacity: 0.32}));
  floor.rotation.x = -Math.PI / 2; floor.receiveShadow = true;
  scene.add(key, key.target, rim, fill, floor);
  const cam = new THREE.PerspectiveCamera(24, 16 / 9, 0.05, 50);
  Object.assign(R3, {THREE, GLTFLoader, ren, cv, scene, cam, floor,
    q: new THREE.Quaternion(), p: new THREE.Quaternion(), a: new THREE.Vector3(), b: new THREE.Vector3(), d: new THREE.Vector3(), e: new THREE.Euler()});
}
async function r3load(name) {
  if (R3.cache[name]) { const av = R3.cache[name]; delete R3.cache[name]; return R3.cache[name] = av; }
  const {THREE} = R3, gltf = await new R3.GLTFLoader().loadAsync('avatars/' + encodeURIComponent(name) + '.glb');
  const root = gltf.scene, bones = {}, faces = [], mats = new Set();
  root.traverse(o => {
    if (o.isBone) bones[o.name.replace(/^mixamorig:?/, '')] = o;
    if (o.isMesh) {
      o.frustumCulled = false; o.castShadow = true;
      [].concat(o.material).forEach(m => mats.add(m));
      if (o.morphTargetDictionary) faces.push({mesh: o, idx: R3FACE.map(k => o.morphTargetDictionary[k] ?? -1), k: /tongue/i.test(o.name) ? +(AVATAR_META[name] || {}).tongue || 1 : 1,
        tongue: /tongue/i.test(o.name)});   // some tongue shapes poke out past the lips
    }
  });
  if (!bones.Head || !bones.LeftArm) throw new Error(`${name}.glb has no Mixamo-style skeleton (Hips, Spine, Head, LeftArm…).`);
  root.updateWorldMatrix(true, true);
  if (bones.LeftArm.getWorldPosition(new THREE.Vector3()).x < 0) { root.rotation.y = Math.PI; root.updateWorldMatrix(true, true); }   // face the camera (+Z)
  const box = new THREE.Box3().setFromObject(root);
  const av = {name, root, bones, faces, mats: [...mats], rest: {}, restW: {}, height: box.max.y - box.min.y, floor: box.min.y,
    headY: bones.Head.getWorldPosition(new THREE.Vector3()).y};
  for (const b of R3BONES) if (bones[b]) { av.rest[b] = bones[b].quaternion.clone(); av.restW[b] = bones[b].getWorldQuaternion(new THREE.Quaternion()); }
  av.rootY = root.position.y - box.min.y; root.position.y = av.rootY;
  for (const m of av.mats) {
    m.userData.r3 = r3cat(m.name);
    // Blender marks every MakeHuman material as blended, which sorts badly in WebGL: draw them solid, cut out hair/brows/lashes/eyes
    const cut = /hair|brow|lash|eye|high-poly|low-poly|ponytail|braid|afro|bob\d|short\d|long\d/i.test(m.name);
    if (m.transparent || m.alphaTest) { m.transparent = false; m.depthWrite = true; m.alphaTest = cut ? 0.5 : 0; m.alphaToCoverage = cut; m.needsUpdate = true; }
  }
  return R3.cache[name] = av;
}
function r3start() {
  const name = avatarName();
  if (R3.state === 'loading' || (R3.av && R3.av.name === name && R3.state === 'ready')) return;
  if (R3.state === 'error' && R3.want === name && performance.now() - R3.errAt < 5000) return;   // a failed load retries every few seconds
  R3.state = 'loading'; R3.want = name; R3.err = '';
  (async () => {
    if (!Object.keys(AVATAR_META).length) {   // fresh install: fetch the ready-made people first
      R3.note = 'Downloading 3D people (~40 MB, first time only)…';
      await api('avatars_fetch'); await refreshPeople(); R3.note = '';
      R3.state = 'idle'; return r3start();
    }
    if (!R3.THREE) await r3init();
    const av = await r3load(name);
    if (R3.av) R3.scene.remove(R3.av.root);
    R3.scene.add(av.root); R3.av = av; R3.tint = ''; R3.state = 'ready'; syncOpts();
    for (const k of Object.keys(R3.cache).filter(k => k !== name).slice(0, -2)) r3evict(k);   // keep the last 3 people in GPU memory
  })().catch(e => { console.error(e); R3.state = 'error'; R3.errAt = performance.now(); R3.err = /404/.test(e && e.message) ? `avatars/${name}.glb is missing — pick another person or build it again` : (e && e.message || String(e)); });
}
function r3evict(k) {   // free a person's meshes and textures
  const av = R3.cache[k]; delete R3.cache[k];
  if (!av) return;
  if (R3.av === av) { R3.scene && R3.scene.remove(av.root); R3.av = null; }
  av.root.traverse(o => { if (o.isMesh) { o.geometry.dispose(); [].concat(o.material).forEach(m => { for (const v of Object.values(m)) if (v && v.isTexture) v.dispose(); m.dispose(); }); } });
}

// ── colors: shift each texture so its average lands on the chosen color, keeping the detail ──
function r3cat(n) {
  n = (n || '').toLowerCase().replace(/^.*\./, '');   // Blender-built people name materials "<Person>.<asset>": only the asset part counts (a "Brownie" isn't all eyebrows)
  if (/lash|teeth|tongue|eye(?!brow)|cornea|iris/.test(n)) return '';
  if (/brow/.test(n)) return 'brow';
  if (/hair|ponytail|braid|afro|\bbun\b|bob\d|short\d|long\d|curl/.test(n)) return 'hair';
  if (/glass|shade|spectacle/.test(n)) return 'glasses';
  if (/foot|shoe|boot|sneaker/.test(n)) return 'shoes';
  if (/suit|outfit(?!_)|cloth/.test(n) && !/top|bottom/.test(n)) return '';   // one texture for the whole outfit (MakeHuman suits): recoloring it would paint the jeans the shirt color
  if (/bottom|pant|jean|trouser|skirt|shorts/.test(n)) return 'pants';
  if (/top|shirt|jacket|hoodie|tee|dress|sweater/.test(n)) return 'top';
  if (/skin|body|head|human\.body/.test(n)) return 'skin';
  return '';
}
function texAvg(m) {
  if (m.userData.avg !== undefined) return m.userData.avg;
  const img = m.map && m.map.image;
  if (!img) return m.userData.avg = null;
  const c = document.createElement('canvas'); c.width = c.height = 24;
  const x = c.getContext('2d', {willReadFrequently: true}); x.drawImage(img, 0, 0, 24, 24);
  const d = x.getImageData(0, 0, 24, 24).data, lin = v => (v /= 255) <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  let r = 0, g = 0, b = 0, n = 0;
  for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 128) { r += lin(d[i]); g += lin(d[i + 1]); b += lin(d[i + 2]); n++; }
  return m.userData.avg = n ? [r / n, g / n, b / n] : null;
}
function r3colors(av) {
  const acc = new Set(C.acc || []), sig = [av.name, C.skin, C.hairColor, C.topColor, C.pants, C.shoes, [...acc].join()].join('|');
  if (R3.tint === sig) return;
  R3.tint = sig;
  const want = {skin: C.skin !== DEFAULT_CHAR.skin ? C.skin : '', hair: C.hairColor, brow: C.hairColor, top: C.topColor, pants: C.pants, shoes: C.shoes !== DEFAULT_CHAR.shoes ? C.shoes : '', glasses: acc.has('shades') ? '#111111' : ''};
  for (const m of av.mats) {
    const cat = m.userData.r3;
    if (!cat) continue;
    if (m.userData.base === undefined) m.userData.base = m.color.clone();
    if ((cat === 'skin' || cat === 'shoes') && !want[cat]) { m.color.copy(m.userData.base); continue; }   // the person's own skin and shoes, as built
    if (cat === 'glasses') { av.root.traverse(o => o.isMesh && [].concat(o.material).includes(m) && (o.visible = acc.has('glasses') || acc.has('shades'))); if (want.glasses) m.color.set(want.glasses); else m.color.copy(m.userData.base); continue; }
    const t = new R3.THREE.Color(cat === 'brow' ? shade(want[cat], -0.25) : want[cat]), avg = texAvg(m);
    if (!avg) { m.color.copy(t); continue; }
    const mix = cat === 'skin' ? 0.7 : 1, f = (c, a) => Math.min(cat === 'skin' ? 2.2 : 8, c / Math.max(a, 0.004));
    m.color.setRGB(1 + (f(t.r, avg[0]) - 1) * mix, 1 + (f(t.g, avg[1]) - 1) * mix, 1 + (f(t.b, avg[2]) - 1) * mix);
  }
}

// ── body: rotate bones about world axes so it works whatever each rig's local bone axes are ──
function r3rotW(bone, qw) {   // local' = P⁻¹ · qw · P · local
  const {q, p} = R3;
  bone.parent.getWorldQuaternion(p);
  q.copy(p).invert().multiply(qw).multiply(p);
  bone.quaternion.premultiply(q); bone.updateWorldMatrix(false, true);
}
function r3turn(bone, x, y, z) { if (!bone) return; R3.e.set(x, y, z, 'YXZ'); r3rotW(bone, new R3.THREE.Quaternion().setFromEuler(R3.e)); }
function r3aim(bone, child, dir) {   // swing a bone so it points along dir (world space)
  if (!bone || !child) return;
  const {a, b} = R3;
  bone.getWorldPosition(a); child.getWorldPosition(b);
  r3rotW(bone, new R3.THREE.Quaternion().setFromUnitVectors(b.sub(a).normalize(), dir));
}
function r3arm(av, side, A, chest) {   // A = {rest:{sh,el}, g?, w}: the same shoulder-swing / elbow-bend numbers the cartoon rig uses
  const {THREE} = R3, s = side === 'L' ? 1 : -1, S = side === 'L' ? 'Left' : 'Right', B = av.bones, w = A.g ? A.w : 0;
  const sh = A.rest.sh + ((A.g ? A.g.sh : 0) - A.rest.sh) * w, el = A.rest.el + ((A.g ? A.g.el : 0) - A.rest.el) * w;
  const up = new THREE.Vector3(s * Math.sin(sh) * 0.85, -Math.cos(sh), 0.16 + Math.max(0, Math.sin(sh)) * 0.4).normalize();
  const bend = Math.abs(el) + 0.3, c = el >= -0.05 ? new THREE.Vector3(s * 0.12, 0.3, 1) : new THREE.Vector3(-s, 0.25, 0.75);
  c.sub(up.clone().multiplyScalar(c.dot(up))).normalize();
  const fore = up.clone().multiplyScalar(Math.cos(bend)).add(c.multiplyScalar(Math.sin(bend))).normalize();
  r3aim(B[S + 'Arm'], B[S + 'ForeArm'], up.applyQuaternion(chest));
  r3aim(B[S + 'ForeArm'], B[S + 'Hand'], fore.applyQuaternion(chest));
}
function r3pose(av, P) {
  const B = av.bones, E = Math.min(1.5, +$('#energy').value || 1), T = P.T;
  for (const k in av.rest) B[k].quaternion.copy(av.rest[k]);
  av.root.position.y = av.rootY - Math.max(0, P.bob) * 0.0009;
  av.root.position.x = P.cam ? (P.dxT || 0) * 0.42 : 0;   // stepping around in front of the camera
  av.root.updateWorldMatrix(true, true);
  if (P.cam) { r3camPose(av, P); return; }
  r3turn(B.Hips, 0, noise(T * 0.21 + 3) * 0.05 * E, 0);
  r3turn(B.Spine, P.lean * 0.4, 0, -P.lean * 0.9);
  r3turn(B.Spine1, Math.sin(T * 1.6) * 0.008, 0, -P.lean * 0.4);
  r3turn(B.Spine2, Math.sin(T * 1.6 + 0.6) * 0.012, 0, 0);
  const nod = P.headY * 0.011, tilt = P.tilt * 0.9, yaw = P.look.x * 0.14 + noise(T * 0.3 + 11) * 0.05 * E;
  r3turn(B.Neck, nod * 0.4, yaw * 0.4, tilt * 0.4);
  r3turn(B.Head, nod * 0.6 + P.look.y * 0.05, yaw * 0.6, tilt * 0.6);
  if (P.shrug) { r3turn(B.LeftShoulder, 0, 0, P.shrug * 0.012); r3turn(B.RightShoulder, 0, 0, -P.shrug * 0.012); }
  const chest = B.Spine2.getWorldQuaternion(new R3.THREE.Quaternion()).multiply(av.restW.Spine2.clone().invert());
  r3arm(av, 'L', P.armL, chest); r3arm(av, 'R', P.armR, chest);
}
function r3camPose(av, P) {   // the camera's numbers, mirrored: the avatar's LEFT side is on screen-right
  const B = av.bones, V = (x, y, z) => new R3.THREE.Vector3(x, y, z).normalize();
  r3turn(B.Spine, 0, 0, -(P.lean || 0) * 0.6);
  r3turn(B.Spine1, 0, 0, -(P.lean || 0) * 0.3);
  const yaw = P.yaw || 0, pitch = P.pitch || 0, roll = -(P.tilt || 0);
  r3turn(B.Neck, pitch * 0.35, yaw * 0.35, roll * 0.35);
  r3turn(B.Head, pitch * 0.65, yaw * 0.65, roll * 0.65);
  const chest = B.Spine2.getWorldQuaternion(new R3.THREE.Quaternion()).multiply(av.restW.Spine2.clone().invert());
  r3arm(av, 'L', P.armR, chest); r3arm(av, 'R', P.armL, chest);
  for (const [k, S, sd] of [['R', 'Left', 1], ['L', 'Right', -1]]) {
    const l = P.legs && P.legs[k];
    if (!l || !B[S + 'UpLeg'] || !B[S + 'Leg']) continue;
    const fa = l.hip + l.knee;
    r3aim(B[S + 'UpLeg'], B[S + 'Leg'], V(sd * Math.sin(l.hip), -Math.cos(l.hip), 0.04));
    if (B[S + 'Foot']) r3aim(B[S + 'Leg'], B[S + 'Foot'], V(sd * Math.sin(fa), -Math.cos(fa), -0.02));
  }
}
function r3face(av, P, dt) {
  const k = rate => 1 - Math.exp(-dt * rate), MA = +$('#mouthAmt').value || 1, vw = R3.vw;
  const on = OCULUS[P.vis], amt = Math.min(0.9, MA * (0.5 + 0.6 * P.env)) * (+(AVATAR_META[av.name] || {}).mouth || 1);
  for (const v of Object.values(OCULUS)) { const tgt = v === on ? amt : 0; vw[v] = (vw[v] || 0) + (tgt - (vw[v] || 0)) * k(P.vis === 'MBP' ? 45 : 28); }
  const W = {}, talk = on ? 0.55 : 1, lx = P.look.x * 0.7, ly = P.look.y * 0.8;
  for (const v in vw) W['viseme_' + v] = vw[v];
  const base = C.eyes === 'sleepy' ? 0.4 : 0;
  W.eyeBlinkLeft = W.eyeBlinkRight = Math.min(1, base + P.blink * (1 - base));
  if (lx > 0) { W.eyeLookOutLeft = lx; W.eyeLookInRight = lx; } else { W.eyeLookInLeft = -lx; W.eyeLookOutRight = -lx; }
  if (ly > 0) W.eyeLookDownLeft = W.eyeLookDownRight = ly; else W.eyeLookUpLeft = W.eyeLookUpRight = -ly;
  if (C.eyes === 'wide') W.eyeWideLeft = W.eyeWideRight = 0.4;
  const grump = C.mood === 'grumpy' || C.eyes === 'angry';
  W.browInnerUp = P.brow * 0.55; W.browOuterUpLeft = W.browOuterUpRight = P.brow * 0.4;
  if (grump) { W.browDownLeft = W.browDownRight = 0.5; W.eyeSquintLeft = W.eyeSquintRight = 0.25; }
  if (C.mood === 'happy') { W.mouthSmileLeft = W.mouthSmileRight = 0.4 * talk; W.cheekSquintLeft = W.cheekSquintRight = 0.2; }
  else if (C.mood === 'grumpy') W.mouthFrownLeft = W.mouthFrownRight = 0.4 * talk;
  else W.mouthSmileLeft = W.mouthSmileRight = 0.1 * talk;
  if (P.bs) {   // the camera read your face: copy every shape, mirrored (your left eye is the avatar's right)
    for (const n in P.bs) if (/^jaw/.test(n) || (!P.faceMouth && /^(mouth|cheekPuff)/.test(n))) continue; else W[MIRROR_LR(n)] = P.bs[n];
    if (P.faceMouth) {   // the jaw goes through the avatar's own viseme shapes, which keep the tongue and teeth in place
      const g = n => P.bs[n] || 0, sc = Math.min(0.85, 0.6 * MA * (+(AVATAR_META[av.name] || {}).mouth || 1)), jaw = Math.min(1, g('jawOpen') * 1.25);   // same range the audio lip-sync uses
      const pk = g('mouthPucker'), fu = g('mouthFunnel');
      W.viseme_aa = jaw * (1 - Math.max(pk, fu)) * sc; W.viseme_O = jaw * fu * sc; W.viseme_U = Math.max(0, pk - jaw * 0.5) * sc;
      W.viseme_PP = Math.min(1, g('mouthClose') * 1.5 + g('mouthPressLeft') * 0.5 + g('mouthPressRight') * 0.5) * (1 - jaw) * sc;
      W.mouthPucker = W.mouthFunnel = 0;
    }
  }
  for (const {mesh, idx, k, tongue} of av.faces) {
    const inf = mesh.morphTargetInfluences;
    idx.forEach((i, j) => { if (i >= 0) inf[i] = tongue && /^mouth/.test(R3FACE[j]) ? 0 : (W[R3FACE[j]] || 0) * k; });
  }
}
function r3frame(av, W, H, T) {
  const cam = R3.cam, aspect = W / H, h = av.height, hy = av.headY - av.floor;
  let [ty, vh, minW] = {full: [h * 0.5, h * 1.14, 0.95], medium: [hy - 0.13, 0.86, 0.72], close: [hy + 0.04, 0.42, 0.34]}[CAMERA] || [h * 0.5, h * 1.14, 0.95];
  if (vh * aspect < minW) vh = minW / aspect;
  cam.aspect = aspect; cam.fov = 24; cam.updateProjectionMatrix();
  const dist = vh / 2 / Math.tan(cam.fov * Math.PI / 360);
  cam.position.set(Math.sin(T * 0.17) * 0.03 * dist, ty + 0.04 + Math.sin(T * 0.23) * 0.01 * dist, dist); cam.lookAt(0, ty, 0);
}
function drawReal(g, W, H, T, P) {
  drawBg(g, W, H, C, T);
  r3start();
  if (R3.state !== 'ready' || !R3.av) {
    const m = Math.min(W, H), err = R3.state === 'error';
    g.fillStyle = 'rgba(0,0,0,.55)'; g.fillRect(0, H / 2 - m * 0.07, W, m * 0.14);
    g.fillStyle = '#fff'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.font = `600 ${Math.round(m * 0.032)}px "JetBrains Mono", monospace`;
    g.fillText(err ? '3D person failed to load: ' + R3.err.slice(0, 70) : R3.note || 'Loading 3D person…', W / 2, H / 2);
    return;
  }
  const av = R3.av, dt = R3.lastT === null ? 1 / 60 : Math.min(0.1, Math.max(0, T - R3.lastT)); R3.lastT = T;
  if (R3.cv.width !== W || R3.cv.height !== H) R3.ren.setSize(W, H, false);
  r3colors(av); r3pose(av, P); r3face(av, P, dt); r3frame(av, W, H, T);
  R3.floor.visible = C.bg !== 'green' && CAMERA === 'full';
  R3.ren.render(R3.scene, R3.cam);
  g.drawImage(R3.cv, 0, 0, W, H);
  const c = av.bones.Head.getWorldPosition(R3.a), hp = c.clone().setY(c.y + 0.09).project(R3.cam), tp = c.clone().setY(c.y + 0.2).project(R3.cam);   // bone sits at the skull base
  R3.head = {x: (hp.x + 1) / 2 * W, y: (1 - hp.y) / 2 * H, r: Math.abs(tp.y - hp.y) / 2 * H};
}

/* ═══════════════ render loop ═══════════════ */
const stage = $('#stage'), sg = stage.getContext('2d');
let lastMs = performance.now(), seeking = false, shownVis = '', shownClock = '';
const fmtTime = s => Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');
function frame(ms) {
  const dt = Math.min(0.1, (ms - lastMs) / 1000), T = ms / 1000; lastMs = ms;
  const t = curTime(), off = +$('#offset').value / 1000;
  bgmTick();
  let env = 0, vis = 'X', onset = 0, beat = null;
  if (LIVE.an) ({env, vis, onset} = liveFrame(dt, T));
  else if (P.playing) {
    const ts = t - off;   // negative offset = mouth leads the sound
    env = envAt(ts); vis = visAt(ts); beat = beatAt(t);
    if (TL && M.lastT !== null && ts > M.lastT && ts - M.lastT < 0.5) for (const [ot, s] of TL.onsets) if (ot > M.lastT && ot <= ts) onset = Math.max(onset, s);
    M.lastT = ts;
  }
  const pose = stepMotion(dt, T, env, vis, onset, beat); pose.vis = vis;
  camTick(dt);
  const cr = CAMX.on ? CAMX.rig : CAMX.take && !CAMX.taking ? takeAt(P.playing ? t : P.offset) : null;
  if (cr) camApply(pose, cr);
  const W = stage.width, H = stage.height;
  drawScene(sg, W, H, T, pose);
  if (A.buf) { drawSubtitles(sg, W, H, t); drawCaptions(sg, W, H, t); }
  drawStrip(t);
  if (vis !== shownVis) { shownVis = vis; $('#visLabel').textContent = VNAME[vis]; $('#visLabel').style.background = vis === 'X' ? '' : VCOL[vis]; }
  if (A.buf) {
    const c = fmtTime(t) + ' / ' + fmtTime(A.buf.duration);
    if (c !== shownClock) { shownClock = c; $('#clock').textContent = c; }
    if (!seeking) $('#seek').value = Math.round(t / A.buf.duration * 1000);
  }
  requestAnimationFrame(frame);
}
function drawStrip(t) {
  const cv = $('#strip'), g = cv.getContext('2d'), W = cv.width, H = cv.height, cs = getComputedStyle(document.documentElement);
  g.clearRect(0, 0, W, H);
  if (!TL) {
    g.fillStyle = cs.getPropertyValue('--muted'); g.font = '15px "JetBrains Mono", monospace'; g.textBaseline = 'middle'; g.textAlign = 'left';
    g.fillText(LIVE.an ? 'Live mic — talk and watch the mouth.' : 'Mouth shapes over time show up here once audio is loaded.', 14, H / 2);
    return;
  }
  const span = 6, t0 = t - 2, X = s => (s - t0) / span * W;
  g.fillStyle = 'rgba(0,0,0,.18)';
  for (let x = 0; x < W; x += 4) { const v = Math.min(1, envAt(t0 + x / W * span)); g.fillRect(x, H - v * 24, 3, v * 24); }
  g.font = '13px "JetBrains Mono", monospace'; g.textBaseline = 'middle'; g.textAlign = 'left';
  for (const e of TL.events) {
    if (e.end < t0 || e.t > t0 + span) continue;
    const x0 = X(e.t), x1 = X(e.end);
    g.fillStyle = VCOL[e.v]; g.fillRect(x0, 26, Math.max(1, x1 - x0 - 1), 30);
    if (x1 - x0 > 24) { g.fillStyle = '#000'; g.fillText(e.label || VNAME[e.v], x0 + 3, 41); }
  }
  g.fillStyle = cs.getPropertyValue('--ink'); g.font = '600 14px "JetBrains Mono", monospace';
  for (const w of TL.words) if (w[1] >= t0 && w[0] <= t0 + span) g.fillText(w[2], X(w[0]) + 2, 13);
  g.fillStyle = cs.getPropertyValue('--hi'); g.fillRect(X(t) - 1.5, 0, 3, H);
}
$('#strip').onclick = e => { if (!TL || !A.buf) return; const r = e.target.getBoundingClientRect(); seek(Math.max(0, curTime() - 2 + (e.clientX - r.left) / r.width * 6)); };

/* ═══════════════ transport, recording, settings ═══════════════ */
function updButtons() {
  const has = !!A.buf, busy = $('#analyzeBtn').textContent !== 'Sync the mouth';
  $('#playBtn').disabled = !has || !!LIVE.an || !!REC; $('#seek').disabled = !has || !!REC;
  $('#playBtn').textContent = P.playing ? '❚❚ Pause' : '▶ Play';
  $('#analyzeBtn').disabled = !has || busy || ($('input[name=amode]:checked').value === 'precise' && !ENGINE_READY);
  $('#recordBtn').disabled = !(has || LIVE.an || CAMX.on);
  $('#recordBtn').textContent = REC ? '■ Stop recording' : LIVE.an ? (CAMX.on ? '● Record me live (camera + mic)' : '● Record live mic') : CAMX.on && !has ? '● Record (camera, no sound)' : CAMX.on ? '● Record me acting to the clip' : '● Record video';
  $('#takeBtn').disabled = !CAMX.on || !!REC;
  $('#micBtn').classList.toggle('on', !!LIVE.an); $('#micBtn').textContent = LIVE.an ? '■ Stop mic' : '🎙 Live mic';
  document.querySelectorAll('#aspect button').forEach(b => b.disabled = !!REC);
}
$('#playBtn').onclick = () => P.playing ? pause() : play();
$('#seek').addEventListener('pointerdown', () => seeking = true);
$('#seek').addEventListener('pointerup', () => seeking = false);
$('#seek').oninput = () => A.buf && seek($('#seek').value / 1000 * A.buf.duration);
document.addEventListener('keydown', e => { if (e.code === 'Space' && e.target === document.body && A.buf && !REC) { e.preventDefault(); P.playing ? pause() : play(); } });

const SIZES = {'16:9': [1280, 720], '9:16': [720, 1280], '1:1': [1080, 1080]};
document.querySelectorAll('#aspect button').forEach(b => b.onclick = () => {
  [stage.width, stage.height] = SIZES[b.dataset.a];
  document.querySelectorAll('#aspect button').forEach(x => x.classList.toggle('on', x === b));
  try { localStorage.toonAspect = b.dataset.a; } catch (e) {}
});
try { const a = localStorage.toonAspect; if (SIZES[a]) document.querySelector(`#aspect button[data-a="${a}"]`).click(); } catch (e) {}
const tfmt = {camSmooth: v => Math.round(v * 100) + '%', bgmVol: v => Math.round(v * 100) + '%', bgmSecs: v => v + ' s', mouthAmt: v => (+v).toFixed(2) + '×', energy: v => (+v).toFixed(1) + '×', gestures: v => Math.round(v * 100) + '%', offset: v => (v > 0 ? '+' : '') + v + 'ms', subSize: v => (+v).toFixed(2) + '×', ttsSpeed: v => (+v).toFixed(2) + '×'};
document.querySelectorAll('main.toon .field input[type=range]:not([data-k])').forEach(r => { const o = r.nextElementSibling, u = () => o.textContent = tfmt[r.id](r.value); r.addEventListener('input', u); u(); });

$('#recordBtn').onclick = () => {
  if (REC) { REC.stop(); return; }
  const live = !!LIVE.an || (CAMX.on && !A.buf);   // live = runs until you press stop (mic, or the camera with no clip)
  if (!live && !A.buf) return;
  ensureCtx();
  const dest = P.ctx.createMediaStreamDestination(), from = LIVE.an ? LIVE.src : P.out;
  from.connect(dest);
  const vs = stage.captureStream(30);
  const stream = new MediaStream([...vs.getVideoTracks(), ...dest.stream.getAudioTracks()]);
  const mime = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'].find(m => MediaRecorder.isTypeSupported(m)) || '';
  const rec = new MediaRecorder(stream, mime ? {mimeType: mime, videoBitsPerSecond: 8e6, audioBitsPerSecond: 192000} : {});
  const chunks = [];
  rec.ondataavailable = e => e.data.size && chunks.push(e.data);
  rec.onstop = () => {
    try { from.disconnect(dest); } catch (e) {}
    vs.getTracks().forEach(t => t.stop());
    REC = null; if (!live) pause(); updButtons();
    finishRecording(new Blob(chunks, {type: rec.mimeType || mime || 'video/webm'}));
  };
  REC = rec; rec.start(500);
  if (!live) play(0);
  updButtons();
};
async function finishRecording(blob, box) {   // upload → MP4; on failure the recording stays here so it can be retried
  const name = (C.name || '').trim() || 'toon', ext = blob.type.includes('mp4') ? 'mp4' : 'webm', raw = URL.createObjectURL(blob);
  if (!box) { box = document.createElement('div'); box.className = 'job'; $('#renders').prepend(box); }
  const head = state => `<div class="top"><span class="t">🎬 ${esc(name)}</span><span class="state ${state === 'done' ? 'done' : state === 'error' ? 'error' : ''}">${state}</span></div>`;
  box.innerHTML = head('converting') + `<div class="meta">Making an MP4 from ${(blob.size / 1048576).toFixed(1)} MB of recording…</div>`;
  try {
    const fd = new FormData(); fd.append('video', blob, 'recording.' + ext); fd.append('name', name);
    fd.append('title', name); fd.append('style', C.style || 'classic');
    if (MAKE_ID && !MANUAL) fd.append('make', MAKE_ID);
    const r = await api('toon_mp4', fd);
    box.innerHTML = head('done') + `<div class="meta">✓ ${esc(r.file)} — saved to the gallery below</div>`;
    await loadGallery(r.file);
    setTimeout(() => box.remove(), 8000);
  } catch (e) {
    if (MAKE_ID && !MANUAL) makeReport(e.message);
    box.innerHTML = head('error') + `<div class="err">${esc(e.message)}</div><video controls src="${raw}"></video>
      <div class="dl"><a class="main" href="#" data-retry>↻ Try again</a><a href="${raw}" download="${esc(name)}.${ext}">⬇ ${ext.toUpperCase()} (unconverted)</a><a href="#" data-x>Dismiss</a></div>`;
    box.querySelector('[data-retry]').onclick = ev => { ev.preventDefault(); finishRecording(blob, box); };
    box.querySelector('[data-x]').onclick = ev => { ev.preventDefault(); box.remove(); };
  }
}

/* ═══════════════ 🎥 camera motion capture: MediaPipe reads your face + body, the character copies it (like a mirror) ═══════════════ */
const MIRROR_LR = n => n.includes('Left') ? n.replace('Left', 'Right') : n.replace('Right', 'Left');
async function camLoad() {   // the MediaPipe models (vendored in vendor/mediapipe, so this works offline)
  if (CAMX.pose) return;
  const V = await import('./vendor/mediapipe/vision_bundle.mjs');
  const fs = await V.FilesetResolver.forVisionTasks('vendor/mediapipe/wasm');
  const make = async (Cls, model, extra, delegate = 'GPU') => {
    try { return await Cls.createFromOptions(fs, {baseOptions: {modelAssetPath: 'vendor/mediapipe/models/' + model, delegate}, runningMode: 'VIDEO', ...extra}); }
    catch (e) { if (delegate === 'GPU') return make(Cls, model, extra, 'CPU'); throw e; }
  };
  [CAMX.pose, CAMX.face] = await Promise.all([
    make(V.PoseLandmarker, 'pose_landmarker_full.task', {numPoses: 1}),
    make(V.FaceLandmarker, 'face_landmarker.task', {numFaces: 1, outputFaceBlendshapes: true, outputFacialTransformationMatrixes: true})]);
}
async function camStart() {
  if (CAMX.on || CAMX.busy) return;
  CAMX.busy = true; $('#camBtn').disabled = true; $('#camMsg').textContent = 'Starting the camera…';
  try {
    CAMX.stream = await navigator.mediaDevices.getUserMedia({video: {width: {ideal: 960}, height: {ideal: 720}, facingMode: 'user'}, audio: false});
    const v = $('#camVid'); v.srcObject = CAMX.stream; await v.play();
    $('#camPv').hidden = false; $('#camMsg').textContent = 'Loading the body and face trackers (first time: a few seconds)…';
    await camLoad();
    Object.assign(CAMX, {on: true, rig: null, raw: null, lastVT: -1});
    $('#camOpts').hidden = false;
    $('#camMsg').textContent = 'Step back until your shoulders and hands are in view (and your legs, if you want them to move). The character mirrors you.';
  } catch (e) {
    camStop();
    $('#camMsg').textContent = /Permission|NotAllowed/i.test(e.name + e.message) ? 'The browser blocked the camera. Allow it in the address bar (camera icon), then try again.'
      : /NotFound|Overconstrained/i.test(e.name) ? 'No camera found. Plug one in, then try again.' : "The camera didn't start: " + e.message;
  }
  CAMX.busy = false; $('#camBtn').disabled = false; camUI();
}
function camStop() {
  if (CAMX.taking) takeStop();
  if (CAMX.stream) CAMX.stream.getTracks().forEach(t => t.stop());
  Object.assign(CAMX, {on: false, stream: null, rig: null, raw: null});
  $('#camVid').srcObject = null; $('#camPv').hidden = true; $('#camOpts').hidden = true; camUI();
}
function camUI() {
  $('#camBtn').textContent = CAMX.on ? '■ Stop camera' : '📷 Start camera';
  $('#camBtn').classList.toggle('on', CAMX.on);
  $('#takeBtn').textContent = CAMX.taking ? '■ Stop recording my moves' : '● Record my moves';
  $('#takeBtn').classList.toggle('on', !!CAMX.taking);
  $('#takeRow').hidden = !CAMX.take;
  if (CAMX.take) $('#takeInfo').innerHTML = `Use my recorded moves <small>${CAMX.take.frames.length ? fmtTime(CAMX.take.dur) : ''} take${CAMX.take.audio ? ', acted to "' + esc(CAMX.take.audio) + '"' : ''}. They play when the camera is off.</small>`;
  updButtons();
}
$('#camBtn').onclick = () => CAMX.on ? camStop() : camStart();
document.querySelectorAll('#camParts button').forEach(b => b.onclick = () => { CAMX.parts.has(b.dataset.v) ? CAMX.parts.delete(b.dataset.v) : CAMX.parts.add(b.dataset.v); b.classList.toggle('on'); });
document.querySelectorAll('#camMouth button').forEach(b => b.onclick = () => { CAMX.mouth = b.dataset.v; document.querySelectorAll('#camMouth button').forEach(x => x.classList.toggle('on', x === b)); });

// landmarks → the rig's own numbers (the same ones the cartoon and the 3D person already understand)
const wrapA = a => Math.atan2(Math.sin(a), Math.cos(a));
function camRig(pr, fr, vw, vh) {
  const r = {};
  const f = fr && fr.faceBlendshapes && fr.faceBlendshapes[0];
  if (f) {
    const bs = {}; for (const c of f.categories) if (c.categoryName !== '_neutral') bs[c.categoryName] = c.score;
    r.bs = bs;
    const m = fr.facialTransformationMatrixes && fr.facialTransformationMatrixes[0] && fr.facialTransformationMatrixes[0].data;
    if (m) {   // rotation part of the face's 4×4 (column-major); mirrored, so turning to your left turns the character to screen-left
      r.yaw = -Math.atan2(m[8], m[10]); r.pitch = Math.atan2(-m[9], Math.hypot(m[8], m[10])); r.roll = Math.atan2(m[1], m[5]);   // pitch + = looking down, roll + = clockwise
    }
    const g = k => bs[k] || 0;
    r.blinkLR = [g('eyeBlinkLeft'), g('eyeBlinkRight')];   // MediaPipe names are as seen in the image, so in the mirror "Left" is screen-left
    r.look = {x: Math.max(-1, Math.min(1, (g('eyeLookOutRight') + g('eyeLookInLeft') - g('eyeLookInRight') - g('eyeLookOutLeft')) * 1.2)),
      y: Math.max(-1, Math.min(1, (g('eyeLookDownLeft') + g('eyeLookDownRight') - g('eyeLookUpLeft') - g('eyeLookUpRight')) * 0.9))};
    const round = Math.max(g('mouthFunnel'), g('mouthPucker')), smile = (g('mouthSmileLeft') + g('mouthSmileRight')) / 2;
    r.mouth = {...MOUTH0, open: Math.min(1.1, g('jawOpen') * 1.7), round, wide: Math.max(0.5, 1 + smile * 0.35 + (g('mouthStretchLeft') + g('mouthStretchRight')) * 0.2 - round * 0.5),
      press: Math.min(1, (g('mouthPressLeft') + g('mouthPressRight') + g('mouthRollLower')) * 0.6), tu: Math.min(1, g('jawOpen') * 0.8)};
    r.brow = Math.max(0, Math.min(1, g('browInnerUp') * 0.9 + (g('browOuterUpLeft') + g('browOuterUpRight')) * 0.35 - (g('browDownLeft') + g('browDownRight')) * 0.25));
  }
  const L = pr && pr.landmarks && pr.landmarks[0];
  if (L) {
    const Q = i => ({x: (1 - L[i].x) * vw, y: L[i].y * vh, v: L[i].visibility ?? 1});   // mirrored pixels: your right side is on screen-right
    const sl = Q(11), sr = Q(12), hl = Q(23), hr = Q(24), ok = (...p) => p.every(q => q.v > 0.55);
    if (ok(sl, sr)) {
      const lean = Math.atan2(sl.y - sr.y, sl.x - sr.x) + Math.PI;   // shoulder line tilt (screen-left → screen-right)
      r.lean = Math.max(-0.5, Math.min(0.5, wrapA(lean)));
      const c = Math.cos(-r.lean), s = Math.sin(-r.lean), rot = (a, b) => ({x: (b.x - a.x) * c - (b.y - a.y) * s, y: (b.x - a.x) * s + (b.y - a.y) * c});
      const swing = (sd, v) => Math.atan2(sd * v.x, v.y);
      const shW = Math.hypot(sr.x - sl.x, sr.y - sl.y), mid = {x: (sl.x + sr.x) / 2, y: (sl.y + sr.y) / 2};
      const torso = ok(hl, hr) ? Math.hypot((hl.x + hr.x) / 2 - mid.x, (hl.y + hr.y) / 2 - mid.y) : shW * 1.35;
      r.arm = {};
      for (const [k, sd, S, E, W] of [['L', -1, sl, Q(13), Q(15)], ['R', 1, sr, Q(14), Q(16)]]) {
        if (!ok(E, W)) continue;
        const sh = swing(sd, rot(S, E)), fa = swing(sd, rot(E, W));
        r.arm[k] = {sh, el: wrapA(fa - sh)};
      }
      r.dxT = (mid.x - vw / 2) / torso;   // in torso lengths from the middle of the frame
      if (ok(hl, hr)) {
        r.hipY = ((hl.y + hr.y) / 2 - mid.y) / torso;
        const legs = {};
        for (const [k, sd, H, K, A] of [['L', -1, hl, Q(25), Q(27)], ['R', 1, hr, Q(26), Q(28)]]) {
          if (!ok(K, A)) continue;
          const hip = swing(sd, {x: K.x - H.x, y: K.y - H.y}), knee = wrapA(swing(sd, {x: A.x - K.x, y: A.y - K.y}) - hip);
          legs[k] = {hip, knee};
        }
        if (legs.L || legs.R) { r.legs = legs; r.ankY = (Math.max(Q(27).y, Q(28).y) - (hl.y + hr.y) / 2) / torso; }
      }
    }
  }
  r.lm = L; r.fl = fr && fr.faceLandmarks && fr.faceLandmarks[0];
  return r;
}
function camSmoothInto(prev, cur, a) {   // exponential smoothing of every number in the rig (missing parts fade back to automatic motion)
  if (!prev) return cur;
  const out = Array.isArray(cur) ? [] : {};
  for (const k in cur) {
    const v = cur[k], p = prev[k];
    if (k === 'lm' || k === 'fl') out[k] = v;
    else if (typeof v === 'number') out[k] = typeof p === 'number' ? p + (v - p) * a : v;
    else if (v && typeof v === 'object') out[k] = camSmoothInto(p && typeof p === 'object' ? p : null, v, a);
  }
  return out;
}
function camTick(dt) {
  if (!CAMX.on || !CAMX.pose) return;
  const v = $('#camVid');
  if (v.readyState < 2 || v.currentTime === CAMX.lastVT) return;
  CAMX.lastVT = v.currentTime;
  const ts = Math.max(CAMX.lastTs + 1, performance.now()); CAMX.lastTs = ts;
  let pr = null, fr = null;
  try { if (CAMX.parts.has('arms') || CAMX.parts.has('body')) pr = CAMX.pose.detectForVideo(v, ts); if (CAMX.parts.has('face')) fr = CAMX.face.detectForVideo(v, ts); } catch (e) { return; }
  const raw = camRig(pr, fr, v.videoWidth, v.videoHeight);
  const S = +$('#camSmooth').value, a = 1 - Math.pow(S, Math.max(0.3, dt * 30));
  CAMX.rig = camSmoothInto(CAMX.rig, raw, a);
  if (!CAMX.rig.arm) CAMX.rig.arm = {};
  for (const k of ['L', 'R']) if (!raw.arm || !raw.arm[k]) delete CAMX.rig.arm[k];   // a hand out of view goes back to automatic
  if (!raw.legs) delete CAMX.rig.legs; else for (const k of ['L', 'R']) if (!raw.legs[k]) delete CAMX.rig.legs[k];
  if (!raw.bs) { delete CAMX.rig.bs; delete CAMX.rig.mouth; delete CAMX.rig.yaw; }
  if (CAMX.taking) {
    const t = CAMX.taking.audio ? curTime() : (performance.now() - CAMX.taking.t0) / 1000;
    const {lm, fl, ...keep} = CAMX.rig;
    CAMX.taking.frames.push([t, JSON.parse(JSON.stringify(keep))]);
  }
  CAMX.nFr++;
  if (performance.now() - CAMX.fpsT > 1000) { CAMX.fps = CAMX.nFr; CAMX.nFr = 0; CAMX.fpsT = performance.now(); }
  camSketch(v);
}
function camSketch(v) {   // the skeleton over the camera preview, so you can see what it tracks
  const cv = $('#camSk'), W = cv.width = cv.clientWidth * devicePixelRatio, H = cv.height = cv.clientHeight * devicePixelRatio, g = cv.getContext('2d');
  const r = CAMX.rig, sc = Math.max(W / v.videoWidth, H / v.videoHeight), ox = (W - v.videoWidth * sc) / 2, oy = (H - v.videoHeight * sc) / 2;
  const P = p => [ox + (1 - p.x) * v.videoWidth * sc, oy + p.y * v.videoHeight * sc];
  g.clearRect(0, 0, W, H); g.lineWidth = 3 * devicePixelRatio; g.lineCap = 'round';
  if (r.lm) {
    for (const [a, b] of [[11, 12], [11, 13], [13, 15], [12, 14], [14, 16], [11, 23], [12, 24], [23, 24], [23, 25], [25, 27], [24, 26], [26, 28], [15, 19], [16, 20]]) {
      const A = r.lm[a], B = r.lm[b]; if ((A.visibility ?? 1) < 0.55 || (B.visibility ?? 1) < 0.55) continue;
      g.strokeStyle = a >= 23 || b >= 23 ? '#ffd23f' : '#7fe3ff'; g.beginPath(); g.moveTo(...P(A)); g.lineTo(...P(B)); g.stroke();
    }
  }
  if (r.fl) { g.fillStyle = 'rgba(180,167,255,.9)'; for (let i = 0; i < r.fl.length; i += 6) { const [x, y] = P(r.fl[i]); g.fillRect(x - 1, y - 1, 2.2, 2.2); } }
  $('#camTag').textContent = `${CAMX.fps} fps · ${r.lm ? 'body ✓' : 'no body'} · ${r.bs ? 'face ✓' : 'no face'}${r.legs ? ' · legs ✓' : ''}`;
}

// recorded moves: a take is just the rig, timestamped; it plays back in step with the audio
function takeStart() {
  if (!CAMX.on) return;
  const audio = A.buf && !LIVE.an ? A.name || 'the clip' : '';
  CAMX.taking = {frames: [], t0: performance.now(), audio};
  if (audio) play(0);   // act along to the clip
  camUI();
}
function takeStop() {
  const tk = CAMX.taking; CAMX.taking = null;
  if (tk && tk.frames.length > 5) CAMX.take = {frames: tk.frames, dur: tk.frames[tk.frames.length - 1][0], audio: tk.audio};
  if (tk && tk.audio && P.playing) pause();
  camUI();
}
$('#takeBtn').onclick = () => CAMX.taking ? takeStop() : takeStart();
$('#takeClear').onclick = () => { CAMX.take = null; camUI(); };
function takeAt(t) {
  const tk = CAMX.take; if (!tk || !$('#takeUse').checked || t > tk.dur + 0.4) return null;
  const F = tk.frames; let lo = 0, hi = F.length - 1;
  while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (F[mid][0] <= t) lo = mid; else hi = mid - 1; }
  return F[lo][1];
}
function camApply(pose, r) {   // the camera (or a take) overrides the automatic motion, part by part
  const P = CAMX.parts, B = BODY[C.body] || BODY.classic;
  pose.cam = true;
  if (P.has('face') && r.bs) {
    if (typeof r.roll === 'number') { pose.tilt = r.roll * 0.9; pose.headY = r.pitch * 70; pose.yaw = r.yaw; pose.pitch = r.pitch; }
    if (r.look) pose.look = {x: Math.max(-1, Math.min(1, r.look.x + (r.yaw || 0) * 0.8)), y: r.look.y};
    if (r.blinkLR) { pose.blinkLR = r.blinkLR; pose.blink = (r.blinkLR[0] + r.blinkLR[1]) / 2; }
    if (typeof r.brow === 'number') pose.brow = r.brow;
    pose.bs = r.bs;
    if (CAMX.mouth === 'face' && r.mouth) { pose.mouth = {...MOUTH0, ...r.mouth}; pose.vis = 'X'; pose.faceMouth = true; }
  }
  if (P.has('arms') && r.arm) for (const k of ['L', 'R']) if (r.arm[k]) { const a = {sh: r.arm[k].sh, el: r.arm[k].el}; pose['arm' + k] = {rest: a, g: a, w: 1}; }
  if (P.has('body')) {
    if (typeof r.lean === 'number') pose.lean = r.lean * 0.8;
    if ($('#camWalk').checked && typeof r.dxT === 'number') { pose.dxT = Math.max(-2.2, Math.min(2.2, r.dxT)); pose.dx = pose.dxT * B.h * 0.75; }
    if (r.legs) pose.legs = r.legs;
    pose.shrug = 0;
  }
}

/* ═══════════════ 🖼 scenes: background pictures (uploaded, or generated with FLUX) ═══════════════ */
function mountScenes(host) {
  const box = document.createElement('div'); box.className = 'scenes';
  box.innerHTML = `<div class="scgrid"></div>
    <div class="row"><input type="text" class="scPrompt" placeholder="Describe a scene, e.g. rainy neon city street at night"><button type="button" class="shrink scGo">🎨 Make it</button></div>
    <div class="row"><select class="scProv"><option value="hf">FLUX on Hugging Face (your HF login, best)</option><option value="free">Free, no account (lower quality, watermark)</option></select><button type="button" class="shrink scUp">⬆ Upload</button></div>
    <input type="file" class="scFile" accept="image/jpeg,image/png,image/webp" hidden>
    <div class="hint scMsg">Pictures made in ChatGPT work too: save one and upload it (or drop it here).</div>`;
  host.appendChild(box);
  const q = c => box.querySelector(c), msg = (t, err) => { q('.scMsg').className = (err ? 'err' : 'hint') + ' scMsg'; q('.scMsg').textContent = t; };
  try { q('.scProv').value = localStorage.toonSceneProv || 'hf'; } catch (e) {}
  q('.scProv').onchange = () => { try { localStorage.toonSceneProv = q('.scProv').value; } catch (e) {} };
  const use = f => { C.bg = 'image'; C.bgImage = f; charChanged(); };
  const upload = async file => {
    msg('Uploading…');
    try { const fd = new FormData(); fd.append('image', file); const r = await api('scene_upload', fd); await loadScenes(); use(r.file); msg('✓ Added ' + r.title); }
    catch (e) { msg(e.message, true); }
  };
  q('.scUp').onclick = () => q('.scFile').click();
  q('.scFile').onchange = () => { if (q('.scFile').files[0]) upload(q('.scFile').files[0]); q('.scFile').value = ''; };
  box.ondragover = e => { e.preventDefault(); box.classList.add('over'); };
  box.ondragleave = () => box.classList.remove('over');
  box.ondrop = e => { e.preventDefault(); box.classList.remove('over'); const f = [...e.dataTransfer.files].find(f => /^image\//.test(f.type)); if (f) upload(f); };
  q('.scPrompt').onkeydown = e => { if (e.key === 'Enter') q('.scGo').click(); };
  q('.scGo').onclick = async () => {
    const prompt = q('.scPrompt').value.trim();
    if (!prompt) { q('.scPrompt').focus(); return; }
    q('.scGo').disabled = true; msg('Painting the scene… (5–20 s)');
    try {
      const fd = new FormData(); fd.append('prompt', prompt); fd.append('style', C.style); fd.append('provider', q('.scProv').value);
      fd.append('aspect', (document.querySelector('#aspect button.on') || {}).dataset?.a || '16:9');
      const r = await api('scene_gen', fd); await loadScenes(); use(r.file); msg(`✓ Made with ${r.source}. It matches the ${C.style} style; make it again for another take.`);
    } catch (e) { msg(e.message, true); }
    q('.scGo').disabled = false;
  };
  drawScenes();
  if (!SCN.list) loadScenes();
}
async function loadScenes() { SCN.list = await api('scenes').catch(() => []); drawScenes(); }
function drawScenes() {
  const grid = document.querySelector('#opts .scgrid');
  if (!grid) return;
  const L = SCN.list || [];
  grid.innerHTML = L.length ? L.map(m => `<div class="scthumb${C.bg === 'image' && C.bgImage === m.file ? ' on' : ''}" data-f="${esc(m.file)}" title="${esc(m.prompt || m.title)}">
      <img loading="lazy" src="scenes/${encodeURIComponent(m.file)}" alt=""><button type="button" class="scdel" title="Delete this picture">✕</button></div>`).join('')
    : '<div class="hint">No pictures yet. Make one or upload one below.</div>';
  grid.querySelectorAll('.scthumb').forEach(t => t.onclick = async e => {
    if (e.target.classList.contains('scdel')) {
      const fd = new FormData(); fd.append('kind', 'scene'); fd.append('file', t.dataset.f);
      try { await api('media_delete', fd); if (C.bgImage === t.dataset.f) { C.bgImage = ''; if (C.bg === 'image') C.bg = 'stage'; charChanged(); } await loadScenes(); } catch (err) { alertMsg(err.message); }
      return;
    }
    C.bg = 'image'; C.bgImage = t.dataset.f; charChanged();
  });
}
const alertMsg = t => charMsg(t, true);

/* ═══════════════ 🎵 background music: loops under the voice, dips while the toon talks ═══════════════ */
const BGM = {file: '', buf: null, src: null, gain: null, prev: null, tracks: []};
async function loadMusicList(pick) {
  const r = await api('music').catch(() => ({tracks: []}));
  BGM.tracks = r.tracks || [];
  const cur = pick !== undefined ? pick : BGM.file;
  $('#bgmSel').innerHTML = '<option value="">No music</option>' + BGM.tracks.map(t => `<option value="${esc(t.file)}">🎵 ${esc(t.title)}</option>`).join('');
  $('#bgmSel').value = BGM.tracks.some(t => t.file === cur) ? cur : '';
  if ($('#bgmSel').value !== BGM.file) await setBgm($('#bgmSel').value);
  $('#bgmDel').disabled = !$('#bgmSel').value;
  if (!r.musicgen) $('#bgmMsg').textContent = `MusicGen runs on this Mac. The first song downloads the model (2.4 GB; ${r.free_gb} GB free now). Tracks loop to fit the video.`;
}
async function setBgm(file) {
  BGM.file = file; BGM.buf = null; bgmPreviewStop();
  try { localStorage.toonBgm = file; } catch (e) {}
  $('#bgmDel').disabled = !file;
  if (!file) { bgmStop(); return; }
  ensureCtx();
  try {
    const r = await fetch('music/' + encodeURIComponent(file));
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const b = await P.ctx.decodeAudioData(await r.arrayBuffer());
    if (BGM.file === file) { BGM.buf = b; if (P.playing) bgmStart(curTime()); }
  } catch (e) { $('#bgmMsg').textContent = "Couldn't load that track: " + e.message; }
}
function bgmLevel(t) {
  let k = 1;
  if ($('#bgmDuck').checked && !LIVE.an) for (let d = -0.25; d <= 0.15; d += 0.05) if (envAt(t + d) > 0.15) { k = 0.35; break; }   // hold the dip across short gaps between words
  const end = A.buf ? A.buf.duration : 0;
  if (end && t > end - 1.5) k *= Math.max(0, (end - t) / 1.5);   // fade out with the clip
  return +$('#bgmVol').value * k;
}
function bgmStart(from) {
  bgmStop();
  if (!BGM.buf || !P.ctx) return;
  BGM.gain = P.ctx.createGain(); BGM.gain.gain.value = bgmLevel(from); BGM.gain.connect(P.out);   // P.out also feeds the recorder
  const s = P.ctx.createBufferSource(); s.buffer = BGM.buf; s.loop = true; s.connect(BGM.gain);
  s.start(0, from % BGM.buf.duration); BGM.src = s;
}
function bgmStop() {
  if (BGM.src) { try { BGM.src.stop(); } catch (e) {} BGM.src.disconnect(); BGM.src = null; }
  if (BGM.gain) { BGM.gain.disconnect(); BGM.gain = null; }
}
function bgmTick() { if (BGM.gain && P.playing) BGM.gain.gain.setTargetAtTime(bgmLevel(curTime()), P.ctx.currentTime, 0.15); }
function bgmPreviewStop() { if (BGM.prev) { try { BGM.prev.stop(); } catch (e) {} BGM.prev = null; } $('#bgmPlay').textContent = '▶'; }
$('#bgmPlay').onclick = () => {
  if (BGM.prev) { bgmPreviewStop(); return; }
  if (!BGM.buf) return;
  ensureCtx();
  const g = P.ctx.createGain(); g.gain.value = Math.max(0.15, +$('#bgmVol').value); g.connect(P.ctx.destination);
  const s = P.ctx.createBufferSource(); s.buffer = BGM.buf; s.connect(g); s.onended = () => { if (BGM.prev === s) bgmPreviewStop(); };
  s.start(); BGM.prev = s; $('#bgmPlay').textContent = '■';
};
$('#bgmSel').onchange = () => setBgm($('#bgmSel').value);
$('#bgmVol').addEventListener('input', () => { try { localStorage.toonBgmVol = $('#bgmVol').value; } catch (e) {} });
$('#bgmDuck').onchange = () => { try { localStorage.toonBgmDuck = $('#bgmDuck').checked ? '1' : '0'; } catch (e) {} };
$('#bgmDel').onclick = async () => {
  const f = $('#bgmSel').value; if (!f) return;
  const fd = new FormData(); fd.append('kind', 'music'); fd.append('file', f);
  try { await api('media_delete', fd); await loadMusicList(''); } catch (e) { $('#bgmMsg').textContent = e.message; }
};
$('#bgmUp').onclick = () => $('#bgmFile').click();
$('#bgmFile').onchange = async () => {
  const f = $('#bgmFile').files[0]; $('#bgmFile').value = ''; if (!f) return;
  $('#bgmMsg').textContent = 'Uploading…';
  try { const fd = new FormData(); fd.append('audio', f); const r = await api('music_upload', fd); await loadMusicList(r.file); $('#bgmMsg').textContent = '✓ Added ' + r.title; }
  catch (e) { $('#bgmMsg').textContent = e.message; }
};
$('#bgmGo').onclick = async () => {
  const prompt = $('#bgmPrompt').value.trim();
  if (!prompt) { $('#bgmPrompt').focus(); return; }
  $('#bgmGo').disabled = true; $('#bgmProg').hidden = false; $('#bgmStage').textContent = 'Starting…'; $('#bgmProg').querySelector('i').style.width = '2%';
  try {
    const fd = new FormData(); fd.append('prompt', prompt); fd.append('seconds', $('#bgmSecs').value);
    const {id, file} = await api('music_gen', fd);
    for (;;) {
      await sleep(1000);
      const st = await api('music_status&id=' + id);
      $('#bgmStage').textContent = st.stage || ''; $('#bgmProg').querySelector('i').style.width = Math.round((st.progress || 0) * 100) + '%';
      if (st.state === 'error') throw new Error(st.error || 'MusicGen failed.');
      if (st.state === 'done') break;
    }
    await loadMusicList(file); $('#bgmMsg').textContent = `✓ Composed "${prompt}". It's selected: hit ▶ to hear it.`;
  } catch (e) { $('#bgmMsg').textContent = e.message; }
  $('#bgmGo').disabled = false; $('#bgmProg').hidden = true;
};
try {
  if (localStorage.toonBgmVol) setSlider('bgmVol', localStorage.toonBgmVol);
  if (localStorage.toonBgmDuck === '0') $('#bgmDuck').checked = false;
} catch (e) {}
loadMusicList((() => { try { return localStorage.toonBgm || ''; } catch (e) { return ''; } })());

/* ═══════════════ gallery ═══════════════ */
let GLIST = [];
const GAL = {q: '', aspect: '', style: '', sort: 'new'};
async function loadGallery(flash) {
  GLIST = await api('renders').catch(() => []);
  const styles = [...new Set(GLIST.map(m => m.style).filter(Boolean))].sort(), cur = $('#galStyle').value;
  $('#galStyle').innerHTML = '<option value="">Any style</option>' + styles.map(st => `<option value="${esc(st)}">${esc(st)}</option>`).join('');
  $('#galStyle').value = styles.includes(cur) ? cur : '';
  renderGallery(flash);
}
function renderGallery(flash) {
  const q = GAL.q.toLowerCase();
  const L = GLIST.filter(m => (!q || (m.title + ' ' + m.file + ' ' + m.style).toLowerCase().includes(q)) && (!GAL.aspect || m.aspect === GAL.aspect) && (!GAL.style || m.style === GAL.style));
  L.sort({new: (a, b) => b.time - a.time, old: (a, b) => a.time - b.time, long: (a, b) => b.duration - a.duration, name: (a, b) => a.title.localeCompare(b.title)}[GAL.sort]);
  $('#galCount').textContent = GLIST.length ? `${L.length} of ${GLIST.length}` : '';
  $('#gallery').innerHTML = L.map(m => {
    const u = encodeURIComponent(m.file).replace(/'/g, '%27'), d = new Date(m.time * 1000);
    return `<div class="gcard${m.file === flash ? ' flash' : ''}" data-f="${esc(m.file)}" title="${esc(m.file)}">
      <div class="gthumb" style="background-image:url('?a=render_thumb&f=${u}&t=${m.time}')"><span>${fmtTime(m.duration)}</span></div>
      <div class="gt">${esc(m.title)}</div>
      <div class="meta">${[m.aspect, m.style, d.toLocaleDateString(undefined, {month: 'short', day: 'numeric'}) + ' ' + d.toLocaleTimeString(undefined, {hour: 'numeric', minute: '2-digit'})].filter(Boolean).map(esc).join(' · ')}</div></div>`;
  }).join('') || `<div class="hint">${GLIST.length ? 'Nothing matches those filters.' : 'No videos yet — record one above and it lands here.'}</div>`;
  $('#gallery').querySelectorAll('.gcard').forEach(c => c.onclick = () => openRender(GLIST.find(m => m.file === c.dataset.f)));
}
$('#galSearch').oninput = () => { GAL.q = $('#galSearch').value.trim(); renderGallery(); };
$('#galStyle').onchange = () => { GAL.style = $('#galStyle').value; renderGallery(); };
$('#galSort').onchange = () => { GAL.sort = $('#galSort').value; renderGallery(); };
document.querySelectorAll('#galAspect button').forEach(b => b.onclick = () => {
  GAL.aspect = b.dataset.v; document.querySelectorAll('#galAspect button').forEach(x => x.classList.toggle('on', x === b)); renderGallery(); });
function openRender(m) {
  if (!m) return;
  const url = '?a=render&f=' + encodeURIComponent(m.file);
  $('#mTitle').textContent = m.title; $('#mVideo').src = url; $('#mDl').href = url + '&dl=1';
  $('#mMeta').textContent = [m.file, m.width && m.height ? m.width + '×' + m.height : '', fmtTime(m.duration), (m.size / 1048576).toFixed(1) + ' MB', m.style].filter(Boolean).join(' · ');
  $('#mReveal').onclick = e => { e.preventDefault(); const f = new FormData(); f.append('render', m.file); api('reveal', f); };
  $('#mDel').onclick = async e => {
    e.preventDefault();
    if (!confirm(`Delete "${m.file}"? This removes the MP4 from disk.`)) return;
    const f = new FormData(); f.append('f', m.file);
    try { await api('delete_render', f); closeModal(); loadGallery(); } catch (er) { alert(er.message); }
  };
  $('#modal').hidden = false; $('#mVideo').play().catch(() => {});
}
function closeModal() { $('#mVideo').pause(); $('#mVideo').removeAttribute('src'); $('#mVideo').load(); $('#modal').hidden = true; }
$('#mClose').onclick = closeModal;
$('#modal').onclick = e => { if (e.target === $('#modal')) closeModal(); };
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#modal').hidden) closeModal(); });

/* ═══════════════ subtitle + caption controls ═══════════════ */
const saveSub = () => { try { localStorage.toonSubs = JSON.stringify(SUB); } catch (e) {} };
const capKey = () => 'toonCaps:' + (A.job || A.name || '');
const saveCaps = () => { try { localStorage[capKey()] = JSON.stringify(CAPS); } catch (e) {} };
let PENDING_CAPS = null;   // captions the Director wrote for audio that isn't made yet
function loadCaps() {
  if (PENDING_CAPS) { CAPS = PENDING_CAPS; PENDING_CAPS = null; saveCaps(); }
  else try { CAPS = JSON.parse(localStorage[capKey()] || '[]'); } catch (e) { CAPS = []; }
  renderCaps();
}
function syncSubUI() {
  [['#subStyle', 'style'], ['#subPos', 'pos'], ['#subFont', 'font'], ['#subColor', 'color'], ['#subHi', 'hi']].forEach(([sel, k]) =>
    document.querySelectorAll(sel + ' button').forEach(b => b.classList.toggle('on', b.dataset.v === String(SUB[k]))));
  $('#subSize').value = SUB.size; $('#subSize').nextElementSibling.textContent = (+SUB.size).toFixed(2) + '×'; $('#subBox').checked = SUB.box;
}
function chipGroup(sel, key, html) {
  const box = $(sel);
  if (html) box.innerHTML = html;
  const sync = () => box.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === String(SUB[key])));
  box.querySelectorAll('button').forEach(b => b.onclick = () => { SUB[key] = b.dataset.v; sync(); saveSub(); });
  sync();
}
chipGroup('#subStyle', 'style');
chipGroup('#subPos', 'pos');
chipGroup('#subFont', 'font', FONTS.map(([f, w]) => `<button type="button" data-v="${f}" style="font-family:'${f}';font-weight:${w}">${f}</button>`).join(''));
chipGroup('#subColor', 'color', ['#ffffff', '#ffd23f', '#111111', ...PAL.cloth.slice(0, 7)].map(c => `<button type="button" data-v="${c}" style="background:${c}"></button>`).join(''));
chipGroup('#subHi', 'hi', ['#ffd23f', '#ff5c39', '#06d6a0', '#3a86ff', '#ff7eb6', '#8338ec', '#ffffff'].map(c => `<button type="button" data-v="${c}" style="background:${c}"></button>`).join(''));
$('#subSize').value = SUB.size; $('#subBox').checked = SUB.box;
$('#subSize').oninput = () => { SUB.size = +$('#subSize').value; $('#subSize').nextElementSibling.textContent = SUB.size.toFixed(2) + '×'; saveSub(); };
$('#subSize').oninput();
$('#subBox').onchange = () => { SUB.box = $('#subBox').checked; saveSub(); };

function retime(line, text) {   // spread edited words across the line's original time span
  const toks = text.trim().split(/\s+/).filter(Boolean), total = toks.reduce((s, w) => s + w.length + 1, 0);
  let t = line.start;
  line.words = toks.map(w => { const d = (line.end - line.start) * (w.length + 1) / total, r = [t, t + d, w]; t += d; return r; });
}
function renderSubLines() {
  const has = !!(TL && TL.lines.length);
  $('#subHint').textContent = has ? '' : 'Subtitles come from the words: run 🔬 Precise with "Read the words" ticked. Your own captions below work any time.';
  $('#subEdit').hidden = !has;
  if (!has) return;
  $('#subLines').innerHTML = TL.lines.map((l, i) => `<div class="row"><span class="shrink meta">${fmtTime(l.start)}</span><input type="text" data-i="${i}" value="${esc(l.words.map(w => w[2]).join(' '))}"></div>`).join('');
  $('#subLines').querySelectorAll('input').forEach(inp => inp.oninput = () => retime(TL.lines[+inp.dataset.i], inp.value));
}
$('#srtBtn').onclick = () => {
  const ts = s => new Date(Math.round(s * 1000)).toISOString().slice(11, 23).replace('.', ',');
  const srt = TL.lines.filter(l => l.words.length).map((l, i) => `${i + 1}\n${ts(l.start)} --> ${ts(l.end)}\n${l.words.map(w => w[2]).join(' ')}\n`).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([srt], {type: 'text/plain'}));
  a.download = (A.name || 'toon').replace(/\.[^.]+$/, '') + '.srt'; a.click();
};

const CAP_KINDS = [['title', 'Title', '#ffd23f'], ['lower', 'Lower third', '#e63946'], ['bubble', 'Speech bubble', '#111111']];
function renderCaps() {
  $('#capList').innerHTML = CAPS.map((c, i) => `<div class="cap" data-i="${i}">
    <div class="row"><input type="text" data-f="text" value="${esc(c.text)}" placeholder="Caption text"><button type="button" class="shrink" data-act="del" title="Remove">✕</button></div>
    <div class="row caprow">
      <select data-f="kind">${CAP_KINDS.map(([v, l]) => `<option value="${v}"${c.kind === v ? ' selected' : ''}>${l}</option>`).join('')}</select>
      <select data-f="font" style="font-family:'${esc(c.font)}'">${FONTS.map(([f]) => `<option${c.font === f ? ' selected' : ''}>${f}</option>`).join('')}</select>
      <input type="color" data-f="color" value="${c.color}" title="Color">
    </div>
    <div class="row caprow">
      <label>from <input type="number" data-f="start" step="0.1" min="0" value="${c.start.toFixed(1)}"></label>
      <label>to <input type="number" data-f="end" step="0.1" min="0" value="${c.end.toFixed(1)}"></label>
      <label>size <input type="range" data-f="size" min="0.5" max="2" step="0.05" value="${c.size}"></label>
      <button type="button" class="shrink" data-act="jump" title="Jump to this caption">▶</button>
    </div></div>`).join('') || '<div class="hint">No captions yet.</div>';
  $('#capList').querySelectorAll('.cap').forEach(el => {
    const c = CAPS[+el.dataset.i];
    el.querySelectorAll('[data-f]').forEach(inp => inp.oninput = () => {
      const f = inp.dataset.f;
      c[f] = ['start', 'end', 'size'].includes(f) ? +inp.value || 0 : inp.value;
      if (f === 'kind') { c.color = CAP_KINDS.find(k => k[0] === c.kind)[2]; el.querySelector('[data-f=color]').value = c.color; }
      if (f === 'font') inp.style.fontFamily = `'${c.font}'`;
      saveCaps();
    });
    el.querySelector('[data-act=del]').onclick = () => { CAPS.splice(CAPS.indexOf(c), 1); saveCaps(); renderCaps(); };
    el.querySelector('[data-act=jump]').onclick = () => seek(c.start);
  });
}
$('#addCap').onclick = () => {
  const t = +curTime().toFixed(1);
  CAPS.push({text: CAPS.length ? 'Your text here' : (C.name ? C.name : 'My Toon Show'), kind: 'title', font: SUB.font, color: '#ffd23f', start: t, end: +(t + 3).toFixed(1), size: 1});
  saveCaps(); renderCaps();
};

/* ═══════════════ boot ═══════════════ */
function modeChanged() {
  const precise = $('input[name=amode]:checked').value === 'precise';
  $('#preciseOpts').hidden = !precise;
  $('#modeHint').textContent = precise
    ? (ENGINE_READY ? 'Reads the actual speech sounds (phonemes) with a model on this Mac. First use downloads ~1.2GB.' : 'Precise needs the engine — install it on the Voice Swap page. Instant works now.')
    : 'Guesses ooo / ahh / eee shapes from the sound, right in the browser. Instant, no install, rougher.';
  updButtons();
}
document.querySelectorAll('input[name=amode]').forEach(r => r.onchange = modeChanged);
/* ═══════════════ 📝 type it → speech ═══════════════ */
const sleep = ms => new Promise(r => setTimeout(r, ms));
const isTextSrc = () => $('input[name=asrc]:checked').value === 'text';
function setAudioSrc(v) {
  $(`input[name=asrc][value=${v}]`).checked = true;
  $('#fileSrc').hidden = v !== 'file'; $('#textSrc').hidden = v !== 'text'; $('#dirSrc').hidden = v !== 'director';
  try { localStorage.toonSrc = v; } catch (e) {}
}
document.querySelectorAll('input[name=asrc]').forEach(r => r.onchange = () => setAudioSrc(r.value));
wireTextBox($('#ttsText'), $('#ttsLoad'), $('#ttsFile'), $('#ttsCount'));
$('#ttsText').addEventListener('input', () => { try { localStorage.toonScript = $('#ttsText').value; } catch (e) {} });
async function loadSpeakers() {
  const v = await api('voices').catch(() => []), cur = $('#ttsSpeaker').value || (() => { try { return localStorage.toonSpeaker; } catch (e) { return ''; } })();
  $('#ttsSpeaker').innerHTML = v.map(x => `<option value="${esc(x.file)}">🎙 My voice: ${esc(x.name)}</option>`).join('') + '<option value="">🤖 AI voice only (no swap, faster)</option>';
  if (cur !== undefined && [...$('#ttsSpeaker').options].some(o => o.value === cur)) $('#ttsSpeaker').value = cur;
}
$('#ttsSpeaker').onchange = () => { try { localStorage.toonSpeaker = $('#ttsSpeaker').value; } catch (e) {} };
$('#ttsVoice').onchange = () => { try { localStorage.vsTtsVoice = $('#ttsVoice').value; } catch (e) {} };
$('#speakBtn').onclick = async () => {
  const text = $('#ttsText').value.trim(), btn = $('#speakBtn');
  if (!text) { showAnErr('Type something for your toon to say first.'); return; }
  if (!ENGINE_READY) { showAnErr('Speaking needs the engine — install it on the Voice Swap page first.'); return; }
  const fd = new FormData();
  fd.append('text', text); fd.append('tts_voice', $('#ttsVoice').value); fd.append('tts_speed', $('#ttsSpeed').value); fd.append('mode', 'speech'); fd.append('steps', '25');
  if ($('#ttsSpeaker').value) { fd.append('saved_voice', $('#ttsSpeaker').value); fd.append('clean_voice', '1'); } else fd.append('ai_voice', '1');
  btn.disabled = true; showAnErr(''); $('#anProg').hidden = false; $('#anProg i').style.width = '0%'; $('#anStage').textContent = 'Starting…';
  try {
    const {ids: [id]} = await api('create', fd);
    for (;;) {
      await sleep(1200);
      const j = (await api('jobs')).find(x => x.id === id);
      if (!j) throw new Error('The speech job disappeared.');
      $('#anProg i').style.width = Math.round((j.status.progress || 0) * 100) + '%'; $('#anStage').textContent = '🗣 ' + (j.status.stage || '');
      if (j.status.state === 'done') break;
      if (['error', 'cancelled'].includes(j.status.state)) throw new Error((j.status.error || 'Speech failed') + (j.log ? '\n\n' + j.log.trim().split('\n').slice(-5).join('\n') : ''));
    }
    await loadSwapJobs(id);
    $('input[name=amode][value=precise]').checked = true; modeChanged();
    await syncMouth();
  } catch (e) { showAnErr(e.message); }
  $('#anProg').hidden = true; btn.disabled = false;
};

/* ═══════════════ ✨ Director + background renders (the same spec format agents use) ═══════════════ */
function setAspect(a) { const b = document.querySelector(`#aspect button[data-a="${a}"]`); if (b && !b.classList.contains('on')) b.click(); }
function setCamera(v) {
  CAMERA = CAM_ZOOM[v] ? v : 'full';
  document.querySelectorAll('#camera button').forEach(b => b.classList.toggle('on', b.dataset.v === CAMERA));
  try { localStorage.toonCam = CAMERA; } catch (e) {}
}
document.querySelectorAll('#camera button').forEach(b => b.onclick = () => setCamera(b.dataset.v));
function setSlider(id, v) { const r = $('#' + id); r.value = v; r.dispatchEvent(new Event('input')); }
function applySpec(sp) {
  C = normChar(sp.character); $('#charSel').value = ''; charChanged();
  Object.assign(SUB, sp.subtitles); syncSubUI(); saveSub();
  setAspect(sp.aspect);
  const m = sp.motion || {};
  setCamera(m.camera); ['energy', 'gestures', 'mouthAmt', 'offset'].forEach(k => m[k] !== undefined && setSlider(k, m[k])); $('#dance').checked = !!m.dance;
  if (sp.script) { $('#ttsText').value = sp.script; $('#ttsText').dispatchEvent(new Event('input')); setAudioSrc('text'); }
  if (sp.tts_voice) $('#ttsVoice').value = sp.tts_voice;
  PENDING_CAPS = sp.captions && sp.captions.length ? sp.captions : null;
  if (sp.bgm) { setSlider('bgmVol', sp.bgm.volume ?? 0.25); $('#bgmDuck').checked = sp.bgm.duck !== false; }
  if (sp.character && sp.character.bg === 'image') loadScenes();
}
function currentSpec() {
  return {title: C.name || 'Toon', character: C, script: $('#ttsText').value, voice: $('#ttsSpeaker').value, tts_voice: $('#ttsVoice').value, speed: +$('#ttsSpeed').value,
    bgm: BGM.file ? {file: BGM.file, volume: +$('#bgmVol').value, duck: $('#bgmDuck').checked} : null,
    subtitles: SUB, captions: PENDING_CAPS || CAPS, aspect: (document.querySelector('#aspect button.on') || {}).dataset?.a || '16:9',
    motion: {camera: CAMERA, energy: +$('#energy').value, gestures: +$('#gestures').value, mouthAmt: +$('#mouthAmt').value, offset: +$('#offset').value, dance: $('#dance').checked}};
}
let STORIES = [];
$('#newsBtn').onclick = async () => {
  const q = $('#dirPrompt').value.trim(), btn = $('#newsBtn');
  $('#dirErr').hidden = true;
  if (!q) { $('#dirPrompt').focus(); $('#dirInfo').textContent = 'Type a topic first, e.g. "a hacker talking about local AI news".'; return; }
  btn.disabled = true; btn.textContent = '📰 Searching…';
  try {
    const fd = new FormData(); fd.append('q', q.replace(/^(an?|the)\s+[\w\s-]{0,40}?\b(talking|explaining|reporting|ranting)\s+(about|on)\s+/i, ''));   // "a hacker talking about X" → search "X"
    STORIES = (await api('news', fd)).stories || [];
    $('#stories').innerHTML = STORIES.length ? STORIES.map((x, i) => `<button type="button" class="story" data-i="${i}"><b>${esc(x.title)}</b><small>${esc(x.source)}${x.date ? ' · ' + esc(x.date) : ''}</small></button>`).join('')
      : '<div class="hint">No news found. Try a broader topic.</div>';
    $('#stories').hidden = false;
    $('#stories').querySelectorAll('.story').forEach(b => b.onclick = () => runDirector(STORIES[+b.dataset.i]));
    $('#dirInfo').textContent = STORIES.length ? 'Pick a story: the script will report it in your toon\'s voice.' : '';
  } catch (e) { $('#dirErr').textContent = e.message; $('#dirErr').hidden = false; }
  btn.disabled = false; btn.textContent = '📰 Find a news story on this';
};
$('#dirBtn').onclick = () => runDirector(null);
async function runDirector(story) {
  const prompt = $('#dirPrompt').value.trim(), btn = $('#dirBtn');
  $('#dirErr').hidden = true;
  if (!prompt) { $('#dirPrompt').focus(); return; }
  btn.disabled = true; btn.textContent = '✨ Writing…';
  $('#dirInfo').textContent = story ? `Reading "${story.title}" and writing the script…` : `${$('#llmModel').value || 'Your local AI'} is building the toon and writing the script… (big models take a minute the first time)`;
  try {
    const fd = new FormData(); fd.append('prompt', prompt); fd.append('model', $('#llmModel').value);
    if (story) fd.append('story', JSON.stringify(story));
    const {spec} = await api('director', fd);
    applySpec(spec);
    $('#dirInfo').textContent = `✓ "${spec.title}" — ${spec.character.style} style${story ? `, from ${story.source}` : ''}. The script is in 📝 Type it: tweak it, then 🗣 Speak it, or 🚀 Make it in the background.`;
    if (story) $('#stories').hidden = true;
  } catch (e) { $('#dirErr').textContent = e.message; $('#dirErr').hidden = false; $('#dirInfo').textContent = ''; }
  btn.disabled = false; btn.textContent = '✨ Write it';
}
async function loadModels() {   // the dropdown lists the text models on this machine (Ollama: everything you've pulled)
  const sel = $('#llmModel');
  sel.innerHTML = '<option value="">loading models…</option>'; $('#dirErr').hidden = true;
  const st = await api('settings').catch(() => null);
  if (st) { $('#llmProvider').value = st.provider; $('#llmUrl').value = st.llm_url; }
  try {
    const r = await api('llm_models');
    sel.innerHTML = r.models.length ? r.models.map(m => `<option value="${esc(m.id)}">🧠 ${esc(m.id)}${m.size ? ' · ' + esc(m.size) : ''}</option>`).join('')
      : '<option value="">no text models yet — run: ollama pull qwen3.5:2b</option>';
    if (r.models.some(m => m.id === r.llm_model)) sel.value = r.llm_model;
  } catch (e) {
    sel.innerHTML = '<option value="">⚠ model server not reachable</option>';
    $('#dirErr').textContent = e.message; $('#dirErr').hidden = false;
  }
}
$('#llmModel').onchange = () => { const fd = new FormData(); fd.append('llm_model', $('#llmModel').value); api('settings', fd).catch(() => {}); };
$('#llmRefresh').onclick = loadModels;
$('#llmProvider').onchange = () => { $('#llmUrl').value = $('#llmProvider').value === 'ollama' ? 'http://127.0.0.1:11434' : 'http://127.0.0.1:8080/v1'; };
$('#llmSave').onclick = async () => {
  const fd = new FormData(); fd.append('provider', $('#llmProvider').value); fd.append('llm_url', $('#llmUrl').value.trim()); fd.append('llm_model', '');
  try { await api('settings', fd); $('#dirInfo').textContent = '✓ Model server saved.'; loadModels(); } catch (e) { $('#dirErr').textContent = e.message; $('#dirErr').hidden = false; }
};
loadModels();
// collapsible sidebar sections remember whether they're open
document.querySelectorAll('details.fold').forEach(d => {
  const k = 'toonFold:' + d.dataset.fold;
  try { if (localStorage[k]) d.open = localStorage[k] === '1'; } catch (e) {}
  d.addEventListener('toggle', () => { try { localStorage[k] = d.open ? '1' : '0'; } catch (e) {} });
});
loadGallery();
function makeCard(id, title) {   // a background render in the results list, kept up to date until it's done
  const box = document.createElement('div'); box.className = 'job'; $('#renders').prepend(box);
  const tick = async () => {
    let s; try { s = await api('make_status&id=' + id); } catch (e) { setTimeout(tick, 4000); return; }
    const head = `<div class="top"><span class="t">🚀 ${esc(s.title || title)}</span><span class="state ${s.state === 'done' ? 'done' : s.state === 'error' ? 'error' : ''}">${esc(s.state)}</span></div>`;
    if (s.state === 'done' && s.render) {
      box.innerHTML = head + `<div class="meta">✓ ${esc(s.render)} · took ${s.seconds}s — saved to the gallery below</div><div class="dl"><a href="${esc(s.toon_lab)}">Open the project in Toon Lab</a></div>`;
      loadGallery(s.render);
    } else if (s.state === 'done') box.innerHTML = head + `<div class="meta">${esc(s.stage)}</div><div class="dl"><a class="main" href="${esc(s.toon_lab)}">Open in Toon Lab</a></div>`;
    else if (s.state === 'error') box.innerHTML = head + `<div class="err">${esc(s.error)}</div>` + (s.log ? `<details><summary>log</summary><pre class="log">${esc(s.log)}</pre></details>` : '');
    else { box.innerHTML = head + `<div class="bar"><i style="width:${Math.round((s.progress || 0) * 100)}%"></i></div><div class="meta">${esc(s.stage || '')}</div>`; setTimeout(tick, 2000); }
  };
  tick();
}
$('#bgMake').onclick = async () => {
  if (!$('#ttsText').value.trim()) { setAudioSrc('text'); $('#ttsText').focus(); showAnErr('Write the script in 📝 Type it first (or let ✨ Director write it).'); return; }
  const btn = $('#bgMake'); btn.disabled = true;
  try { const r = await api('make', JSON.stringify(currentSpec())); makeCard(r.id, r.spec.title); }
  catch (e) { showAnErr(e.message); }
  btn.disabled = false;
};

// ?make=<project>: rebuild a project made by an agent / the background renderer. Headless Chrome uses this to record it.
const QS = new URLSearchParams(location.search), MAKE_ID = QS.get('make') || '', MANUAL = !!QS.get('manual');
function makeReport(msg) { const fd = new FormData(); fd.append('id', MAKE_ID); fd.append('error', msg); return api('make_report', fd).catch(() => {}); }
async function openProject(id, record) {
  try {
    const r = await api('make_get&id=' + encodeURIComponent(id));
    applySpec(r.spec);
    if (r.job) await loadSwapJobs(r.job);
    else await loadAudio(await (await fetch('?a=make_audio&id=' + encodeURIComponent(id))).blob(), r.spec.title);
    if (!A.buf) throw new Error('The project audio could not be loaded.');
    if (r.result) setTimeline(r.result, 'precise');
    CAPS = r.spec.captions || []; renderCaps();
    if (r.spec.bgm && r.spec.bgm.file) await loadMusicList(r.spec.bgm.file);
    if (!record) return;
    if (document.fonts) await document.fonts.ready;
    if (C.bg === 'image') for (let i = 0; i < 100 && !sceneImg(C.bgImage); i++) await sleep(100);   // the scene picture is on screen before the tape rolls
    if (C.style === 'real') {   // the 3D person is a big download: don't start the tape until it is on stage
      r3start();
      for (let i = 0; i < 600 && R3.state !== 'ready' && R3.state !== 'error'; i++) await sleep(100);
      if (R3.state === 'error') throw new Error('The 3D person failed to load: ' + R3.err);
    }
    await sleep(600);
    $('#recordBtn').click();
  } catch (e) { if (record) makeReport(e.message); else showAnErr(e.message); }
}
if (document.fonts) document.fonts.ready.then(() => { ATLAS = null; });
try { setCamera(localStorage.toonCam || 'full'); } catch (e) { setCamera('full'); }
try { setAudioSrc(['text', 'director'].includes(localStorage.toonSrc) ? localStorage.toonSrc : 'file'); if (localStorage.toonScript) { $('#ttsText').value = localStorage.toonScript; $('#ttsText').dispatchEvent(new Event('input')); } if (localStorage.vsTtsVoice) $('#ttsVoice').value = localStorage.vsTtsVoice; } catch (e) {}
loadSpeakers();

buildOpts(); loadChars();
api('engine').then(e => { ENGINE_READY = !!e.ready; if (!ENGINE_READY) $('input[name=amode][value=instant]').checked = true; modeChanged(); }).catch(() => modeChanged());
if (MAKE_ID) loadSwapJobs().then(() => openProject(MAKE_ID, !MANUAL)); else loadSwapJobs(QS.get('job'));
if (document.fonts) FONTS.forEach(([f, w]) => document.fonts.load(fontStr(f, 40)));
renderCaps(); renderSubLines();
requestAnimationFrame(frame);
</script>
<?php endif; ?>
<?php if ($page === 'game'): ?>
<main class="toon game">
  <aside class="side">
  <details class="panel fold" data-fold="g-game" open>
    <summary><h2><span class="step">1</span> Game</h2></summary>
    <div class="row"><input type="text" id="gTitle" placeholder="Name your game" maxlength="40"></div>
    <div class="opt"><b>Genre</b><div class="chips" id="gMold"></div></div>
    <div class="hint" id="gMoldHint"></div>
    <textarea id="gPitch" rows="2" placeholder="One sentence for the title screen (optional)"></textarea>
    <div class="subhead">✨ Game Director</div>
    <textarea id="gDirPrompt" rows="2" placeholder="e.g. a cyber fox dodging drones in a neon city, with a giant robot boss at the end"></textarea>
    <div class="row"><select id="gModel" title="Which local model designs it"></select><button type="button" class="shrink" id="gModelRefresh" title="Reload the model list">↻</button><button type="button" class="shrink" id="gDirBtn">✨ Design it</button></div>
    <div class="hint" id="gDirInfo">Your local model (Ollama) picks the genre, invents the cast as voxel buddies, lays out the stages and writes the voice lines. Then change anything below.</div>
    <div class="err" id="gDirErr" hidden></div>
  </details>

  <details class="panel fold" data-fold="g-cast" open>
    <summary><h2><span class="step">2</span> Cast</h2></summary>
    <div id="castList"></div>
    <div class="row"><button type="button" class="shrink" id="addEnemy">+ Enemy</button><button type="button" class="shrink" id="addBoss">+ Boss</button></div>
    <div class="hint">Heroes, enemies and bosses can be any saved toon from Toon Lab, a preset, or a voxel buddy. Toons keep their art style in the game.</div>
    <div class="picker" id="castPick" hidden>
      <div class="row"><b id="pickTitle">Pick a character</b><button type="button" class="shrink" id="pickClose">✕</button></div>
      <div class="seg" id="pickTabs"><label><input type="radio" name="ptab" value="toons" checked> 🎨 Toons</label><label><input type="radio" name="ptab" value="buddies"> 🧱 Voxel buddies</label></div>
      <div class="pgrid" id="pickGrid"></div>
      <div class="hint" id="pickHint"></div>
    </div>
    <details class="pbuild" id="buddyBox">
      <summary>🧱 Voxel buddies — a character from a sentence</summary>
      <div class="pbody">
        <div class="row"><input type="text" id="bPrompt" placeholder="e.g. a cyber fox with headphones" maxlength="120"><button type="button" class="shrink" id="bBuild">Build</button></div>
        <div class="row"><button type="button" class="shrink" id="bRandom">🎲 Random</button><button type="button" class="shrink" id="bReroll" title="Same words, new seed">↻ Reroll</button><button type="button" class="shrink" id="bAI" title="Ask your local model for a custom build sheet">✨ Ask the AI</button></div>
        <div class="bstage"><canvas id="bCanvas"></canvas><div class="bplate"><b id="bName">—</b><span id="bSub"></span></div></div>
        <div class="row"><input type="text" id="bNameIn" placeholder="Name" maxlength="40"><button type="button" class="shrink" id="bSave">💾 Save</button><button type="button" class="shrink" id="bVox" title="Export a MagicaVoxel .vox file">⬇ .vox</button></div>
        <div class="row"><span class="lbl shrink">Use as</span><button type="button" class="shrink" data-use="hero">Hero</button><button type="button" class="shrink" data-use="enemy">Enemy</button><button type="button" class="shrink" data-use="boss">Boss</button></div>
        <div class="hint" id="bMsg">Built instantly from the words: species, color, vibe, size, mood and accessories (crown, scarf, wings, sword…). ✨ asks your local model to design the parts instead; if it can't, the fast builder takes over. Drag to orbit.</div>
        <div class="subhead">Library <span class="meta" id="bCount"></span></div>
        <div class="bgrid" id="bGrid"></div>
      </div>
    </details>
  </details>

  <details class="panel fold" data-fold="g-levels">
    <summary><h2><span class="step">3</span> Stages</h2></summary>
    <div class="row"><select id="lvSel"></select><button type="button" class="shrink" id="lvAdd">+ Stage</button><button type="button" class="shrink" id="lvDel" title="Remove this stage">✕</button></div>
    <div class="row"><input type="text" id="lvName" placeholder="Stage name" maxlength="40"></div>
    <div class="opt"><b>Backdrop</b><div class="chips" id="lvBg"></div></div>
    <div class="scgrid" id="lvScenes"></div>
    <div class="opt"><b>Length</b><div class="chips" id="lvLen"><button type="button" data-v="short">Short</button><button type="button" data-v="medium">Medium</button><button type="button" data-v="long">Long</button></div></div>
    <div class="opt"><b>Challenge</b><div class="chips" id="lvDiff"><button type="button" data-v="1">Easy</button><button type="button" data-v="2">Tricky</button><button type="button" data-v="3">Mean</button></div></div>
    <label class="check"><input type="checkbox" id="lvKey"><span>Key and locked door</span></label>
    <label class="check"><input type="checkbox" id="lvBoss"><span>Boss at the end<small>Needs a boss in the cast</small></span></label>
    <div class="row"><button type="button" class="shrink" id="lvGen">🎲 New layout</button><button type="button" class="shrink" id="lvRegen" title="Rebuild from the same seed (undoes your painting)">↻ Rebuild</button><span class="meta" id="lvSeed"></span></div>
    <div class="opt"><b>Paint</b><div class="chips" id="lvTools"></div></div>
    <div class="painter" id="painter"><canvas id="pCanvas"></canvas></div>
    <div class="hint" id="pHint">Click or drag to paint, right-click to erase. S is where the hero starts, G is the goal.</div>
    <div class="row"><label class="shrink lbl">Music</label><select id="lvMusic"></select></div>
  </details>

  <details class="panel fold" data-fold="g-rules">
    <summary><h2><span class="step">4</span> Rules &amp; feel</h2></summary>
    <div class="field"><label>Lives</label><input type="range" id="rLives" min="1" max="9" step="1" value="3"><output></output></div>
    <div class="opt"><b>Difficulty</b><div class="chips" id="rDiff"><button type="button" data-v="chill">Chill</button><button type="button" data-v="normal">Normal</button><button type="button" data-v="hard">Hard</button><button type="button" data-v="brutal">Brutal</button></div></div>
    <div class="opt"><b>To clear a stage</b><div class="chips" id="rWin"><button type="button" data-v="goal">Reach the goal</button><button type="button" data-v="coins">Collect every coin</button><button type="button" data-v="boss">Beat the boss</button><button type="button" data-v="survive">Survive the clock</button></div></div>
    <div class="field"><label>Time limit</label><input type="range" id="rTime" min="0" max="300" step="15" value="0"><output></output></div>
    <div class="field"><label>Gravity</label><input type="range" id="rGravity" min="0.5" max="1.6" step="0.05" value="1"><output></output></div>
    <div class="field"><label>Jump</label><input type="range" id="rJump" min="0.6" max="1.6" step="0.05" value="1"><output></output></div>
    <div class="field"><label>Speed</label><input type="range" id="rSpeed" min="0.6" max="1.6" step="0.05" value="1"><output></output></div>
    <div class="row"><input type="text" id="rWinText" placeholder="Win screen line (optional)" maxlength="120"></div>
    <div class="row"><input type="text" id="rLoseText" placeholder="Game over line (optional)" maxlength="120"></div>
  </details>

  <details class="panel fold" data-fold="g-audio">
    <summary><h2><span class="step">5</span> Audio</h2></summary>
    <div class="opt"><b>Sound effects</b><div class="chips" id="aStyle"></div></div>
    <div class="sfxlist" id="aSfx"></div>
    <div class="subhead">Music</div>
    <div class="row"><select id="aMusic"><option value="">No music</option></select><button type="button" class="shrink" id="aMusicPlay" title="Preview">▶</button></div>
    <div class="field"><label>Volume</label><input type="range" id="aMusicVol" min="0" max="1" step="0.05" value="0.5"><output></output></div>
    <details><summary>🎼 Compose a track</summary>
      <textarea id="aCompPrompt" rows="2" placeholder="e.g. bouncy chiptune adventure theme · tense boss battle synth · dreamy space pads"></textarea>
      <div class="field"><label>Length</label><input type="range" id="aCompSecs" min="8" max="30" step="1" value="24"><output></output></div>
      <div class="row"><button type="button" class="shrink" id="aCompGo">🎼 Compose</button><span class="meta" id="aCompMsg"></span></div>
      <div id="aCompProg" hidden><div class="bar"><i></i></div></div>
      <div class="hint">MusicGen composes it on this Mac (about a minute). Tracks loop in the game. Each stage can pick its own track above.</div>
    </details>
    <div class="subhead">Voice lines</div>
    <div class="row"><select id="aVoice"></select></div>
    <div class="row"><label class="shrink lbl">Reader</label><select id="aReader"><?php foreach (TTS_VOICES as [$v, $l]) echo "<option value=\"$v\">$l</option>"; ?></select></div>
    <div class="vlines" id="aLines"></div>
    <div class="row"><button type="button" class="shrink" id="aSpeak">🗣 Make the voice lines</button><span class="meta" id="aSpeakMsg"></span></div>
    <div class="hint">The hero speaks these in your saved voice (or the AI reader) at the start, on a win, on a loss and when hurt. They're baked into the game.</div>
  </details>

  <details class="panel fold" data-fold="g-make" open>
    <summary><h2><span class="step">6</span> Play &amp; save</h2></summary>
    <button class="big" id="gPlay" type="button">▶ Play it</button>
    <div class="row"><button type="button" class="shrink" id="gSave">💾 Save to gallery</button><button type="button" class="shrink" id="gDownload">⬇ Download HTML</button><button type="button" class="shrink" id="gNew" title="Start a fresh game">✨ New</button></div>
    <div class="hint" id="gMsg">The whole game is one HTML file: sprites, music and voices included. It runs anywhere, offline.</div>
    <div class="err" id="gErr" hidden></div>
  </details>
  </aside>

  <div class="stagecol">
    <section class="panel">
      <div class="gshell"><iframe id="gameFrame" sandbox="allow-scripts" title="Your game"></iframe><div class="gempty" id="gEmpty"><div>Build your game on the left, then <b>▶ Play it</b>.<br><small>Click the game once to give it the keyboard. P pauses, R restarts.</small></div></div></div>
      <div class="row"><span class="hint" id="gPlayMsg"></span><button type="button" class="shrink" id="gFull">⛶ Full screen</button><button type="button" class="shrink" id="gPop">↗ Open in a tab</button></div>
    </section>
    <section class="panel">
      <h2>🕹 Gallery <span class="meta" id="galCount"></span></h2>
      <div class="ggal" id="gGallery"></div>
    </section>
  </div>
</main>
<script><?= GAME_RUNTIME_JS ?></script>
<script type="module">
/* 🧱 Voxel buddies — a prompt becomes a chunky 3D character (after lalomorales22/voxel-buddies, MIT).
   Deterministic: the same prompt + seed always rebuilds the same buddy, so the library only stores the recipe.
   The AI route (Ollama) returns a build sheet: a list of parts executed with the same primitives. */
import * as THREE from 'three';

/* ---------- random ---------- */
function hashStr(s) { let h = 2166136261 >>> 0; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; } return h >>> 0; }
function mulberry(a) { return function () { a |= 0; a = a + 0x6D2B79F5 | 0; let t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
const R = {f: null, init(seed) { this.f = mulberry(seed >>> 0); }, n() { return this.f(); }, i(a, b) { return a + Math.floor(this.f() * (b - a + 1)); }, pick(a) { return a[Math.floor(this.f() * a.length)]; }, chance(p) { return this.f() < p; }};

/* ---------- color ---------- */
function hsl2hex(h, s, l) {
  h = ((h % 360) + 360) % 360; s = Math.max(0, Math.min(100, s)) / 100; l = Math.max(0, Math.min(100, l)) / 100;
  const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2; let r, g, b;
  if (h < 60) { r = c; g = x; b = 0; } else if (h < 120) { r = x; g = c; b = 0; } else if (h < 180) { r = 0; g = c; b = x; } else if (h < 240) { r = 0; g = x; b = c; } else if (h < 300) { r = x; g = 0; b = c; } else { r = c; g = 0; b = x; }
  const t = v => Math.round((v + m) * 255).toString(16).padStart(2, '0');
  return '#' + t(r) + t(g) + t(b);
}
function shade(hex, f) { const n = parseInt(hex.slice(1), 16); const c = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(v => Math.max(0, Math.min(255, Math.round(f > 0 ? v + (255 - v) * f : v * (1 + f))))); return '#' + c.map(v => v.toString(16).padStart(2, '0')).join(''); }

/* ---------- voxel model ---------- */
const K = (x, y, z) => x + ',' + y + ',' + z;
class Vox {
  constructor() { this.m = new Map(); this.pal = []; this.pi = new Map(); }
  ci(hex) { hex = hex.toUpperCase(); let i = this.pi.get(hex); if (i === undefined) { i = this.pal.length; this.pal.push(hex); this.pi.set(hex, i); } return i; }
  set(x, y, z, hex) { if (y < 0) return; this.m.set(K(x, y, z), this.ci(hex)); }
  del(x, y, z) { this.m.delete(K(x, y, z)); }
  has(x, y, z) { return this.m.has(K(x, y, z)); }
  get size() { return this.m.size; }
  cells() { const out = []; for (const [k, c] of this.m) { const p = k.split(','); out.push([+p[0], +p[1], +p[2], c]); } return out; }
}
const VOXEL_CAP = 1536;
function interior(M) { const out = []; for (const c of M.cells()) { const [x, y, z] = c; if (M.has(x + 1, y, z) && M.has(x - 1, y, z) && M.has(x, y + 1, z) && M.has(x, y - 1, z) && M.has(x, y, z + 1) && M.has(x, y, z - 1)) out.push(c); } return out; }
function resample(M, k) { const N = new Vox(); for (const c of M.cells()) N.set(Math.round(c[0] * k), Math.round(c[1] * k), Math.round(c[2] * k), M.pal[c[3]]); return N; }
function fitToCap(M) {   // hollow first (free), shrink only if the shell is still over budget
  for (let pass = 0; pass < 8; pass++) {
    const inner = interior(M), shell = M.size - inner.length;
    if (shell <= VOXEL_CAP) { for (const c of inner) M.del(c[0], c[1], c[2]); return M; }
    M = resample(M, Math.max(.5, Math.sqrt(VOXEL_CAP / shell) * .98));
  }
  return M;
}
function bxA(M, x0, y0, z0, w, h, d, hex, bevel) { const b = bevel || 0; for (let ix = 0; ix < w; ix++) for (let iy = 0; iy < h; iy++) for (let iz = 0; iz < d; iz++) { if (b) { const dx = Math.min(ix, w - 1 - ix), dy = Math.min(iy, h - 1 - iy), dz = Math.min(iz, d - 1 - iz); if (dx + dy + dz < b) continue; } M.set(x0 + ix, y0 + iy, z0 + iz, hex); } }
function bx(M, cx, y0, cz, w, h, d, hex, bevel) { bxA(M, cx - ((w - 1) >> 1), y0, cz - ((d - 1) >> 1), w, h, d, hex, bevel); }
function pair(M, cx, y0, cz, w, h, d, hex, bevel) { const x0 = cx - ((w - 1) >> 1), z0 = cz - ((d - 1) >> 1); bxA(M, x0, y0, z0, w, h, d, hex, bevel); bxA(M, -(x0 + w - 1), y0, z0, w, h, d, hex, bevel); }
function domeAt(M, cx, y0, cz, rx, ry, rz, hex) { for (let x = -rx; x <= rx; x++) for (let z = -rz; z <= rz; z++) for (let y = 0; y <= ry; y++) { const v = (x * x) / (rx * rx + .4) + (y * y) / (ry * ry + .4) + (z * z) / (rz * rz + .4); if (v <= 1.02) M.set(cx + x, y0 + y, cz + z, hex); } }
function dome(M, cy, ry, rx, rz, hex, y0) { domeAt(M, 0, y0 + cy, 0, rx, ry, rz, hex); }
function cyl(M, cx, y0, cz, rx, rz, h, hex) { for (let x = -rx; x <= rx; x++) for (let z = -rz; z <= rz; z++) { if ((x * x) / (rx * rx + .4) + (z * z) / (rz * rz + .4) > 1.05) continue; for (let y = 0; y < h; y++) M.set(cx + x, y0 + y, cz + z, hex); } }
function topLight(M) { const lit = new Map(); for (const [k, c] of Array.from(M.m)) { const p = k.split(','), x = +p[0], y = +p[1], z = +p[2]; if (M.has(x, y + 1, z)) continue; let l = lit.get(c); if (l === undefined) { l = M.ci(shade(M.pal[c], .1)); lit.set(c, l); } M.m.set(k, l); } }
const fz = d => (d - 1) >> 1;
const odd = n => { n = Math.max(1, Math.round(n)); return n % 2 ? n : n + 1; };

/* ---------- lexicon ---------- */
const ARCH = {
  cat: {name: 'Cat', ears: 'point', muzzle: 1, tail: 'curl', head: 1.06, whisk: 1}, fox: {name: 'Fox', ears: 'point', muzzle: 1, tail: 'bush', head: 1.04, whisk: 1},
  dog: {name: 'Pup', ears: 'flop', muzzle: 1, tail: 'stub', head: 1.06}, bunny: {name: 'Bunny', ears: 'long', muzzle: 1, tail: 'puff', head: 1.02},
  bear: {name: 'Bear', ears: 'round', muzzle: 1, head: 1.1, chonk: 1.15}, panda: {name: 'Panda', ears: 'round', muzzle: 1, head: 1.1, chonk: 1.15, patch: 1},
  penguin: {name: 'Penguin', beak: 1, flippers: 1, belly: 1, feet: 'wide', head: 1.0, chonk: 1.15}, bird: {name: 'Birb', beak: 1, wings: 'feather', tail: 'fan', head: 1.05},
  frog: {name: 'Frog', topEyes: 1, wideMouth: 1, head: 1.05, chonk: 1.1}, dino: {name: 'Dino', snout: 1, tail: 'thick', spikes: 1, head: 1.0},
  dragon: {name: 'Dragon', snout: 1, tail: 'thick', spikes: 1, horns: 1, wings: 'bat', head: 1.0}, robot: {name: 'Bot', visor: 1, antenna: 1, boxy: 1, metal: 1, head: 1.0},
  alien: {name: 'Alien', antenna: 1, head: 1.3, bigEyes: 1, slim: 1}, knight: {name: 'Knight', helmet: 1, visor: 1, sword: 1, armor: 1, head: 1.0},
  wizard: {name: 'Wizard', hat: 'pointy', beard: 1, staff: 1, head: 1.0}, ghost: {name: 'Ghost', plan: 'blob', wavy: 1, nubs: 1, head: 1.0, bw: .78, bh: 1.35},
  slime: {name: 'Slime', plan: 'blob', jelly: 1, nubs: 1, head: 1.0, bw: 1.12, bh: .9}, mushroom: {name: 'Shroom', plan: 'blob', cap: 1, head: 1.0, bw: .5, bh: 1.15},
  axolotl: {name: 'Axolotl', gills: 1, muzzle: 1, tail: 'flat', head: 1.08},
};
const ARCH_WORDS = {cat: 'cat kitty kitten feline meow tabby', fox: 'fox kitsune vixen', dog: 'dog pup puppy doggo corgi shiba hound wolf', bunny: 'bunny rabbit hare bun', bear: 'bear teddy grizzly cub', panda: 'panda',
  penguin: 'penguin pengu pingu', bird: 'bird birb chick owl parrot duck crow', frog: 'frog toad froggy', dino: 'dino dinosaur raptor rex lizard trex', dragon: 'dragon drake wyvern',
  robot: 'robot bot android mech droid cyborg machine', alien: 'alien martian ufo extraterrestrial', knight: 'knight warrior paladin samurai soldier guard hero', wizard: 'wizard mage witch sorcerer warlock druid',
  ghost: 'ghost spirit spooky phantom boo specter', slime: 'slime blob goo jelly ooze', mushroom: 'mushroom shroom fungus toadstool', axolotl: 'axolotl salamander newt'};
const HUES = {red: 0, crimson: 352, scarlet: 8, orange: 26, amber: 38, gold: 44, yellow: 50, lime: 80, green: 130, mint: 158, teal: 176, cyan: 188, aqua: 186, sky: 202, blue: 218, indigo: 246, purple: 274, violet: 280,
  magenta: 308, pink: 334, rose: 344, peach: 20, lavender: 266, brown: 26, tan: 34, cream: 44, white: 200, black: 250, grey: 220, gray: 220, silver: 215, copper: 22, rainbow: -1};
const VIBES = {
  candy: {s: 74, l: 70, acc: 150, accL: 66, shift: -16, words: 'candy sweet pastel bubblegum sugar kawaii'}, cyber: {s: 88, l: 56, acc: 170, accL: 60, shift: 24, words: 'cyber neon tech synth hacker digital chrome'},
  lava: {s: 88, l: 52, acc: -24, accL: 58, shift: -14, words: 'lava fire flame magma ember volcano burning'}, frost: {s: 52, l: 74, acc: 36, accL: 72, shift: 14, words: 'ice frost snow winter arctic frozen glacier'},
  forest: {s: 46, l: 44, acc: -46, accL: 48, shift: 18, words: 'forest woods moss leaf nature jungle earthy'}, royal: {s: 58, l: 44, acc: -172, accL: 60, shift: 12, words: 'royal king queen noble regal crown majestic'},
  void: {s: 44, l: 32, acc: 130, accL: 56, shift: -20, words: 'void dark shadow cursed night eldritch goth evil angry'}, sunset: {s: 80, l: 60, acc: -40, accL: 64, shift: -26, words: 'sunset dusk summer tropical beach warm'},
  ocean: {s: 66, l: 52, acc: 64, accL: 60, shift: 20, words: 'ocean sea water aqua deep coral wave'}, cosmic: {s: 70, l: 56, acc: -120, accL: 66, shift: 32, words: 'space cosmic star galaxy nebula moon lunar astro planet'},
};
const ACC = {crown: 'crown king queen royal regal prince princess', tophat: 'tophat gentleman fancy formal dapper', cap: 'cap hat beanie hood', wizhat: 'wizardhat pointy hat wizard mage witch', scarf: 'scarf cozy winter knit',
  cape: 'cape hero super cloak', halo: 'halo angel angelic holy saint', glasses: 'glasses nerd specs goggles', headphones: 'headphones dj music beats audio', backpack: 'backpack adventurer explorer camper hiker traveler',
  horns: 'horns demon devil imp horned', wings: 'wings fairy angel winged flying', antenna: 'antenna signal radio', sword: 'sword blade knight katana warrior samurai'};
const words = s => s.toLowerCase().replace(/[^a-z0-9\s-]/g, ' ').split(/[\s-]+/).filter(Boolean);
function matchList(w, map) { const out = []; for (const k in map) { const set = map[k].split(' '); if (w.some(x => set.includes(x))) out.push(k); } return out; }
function readPrompt(text) {
  const w = words(text), t = {};
  let arch = matchList(w, ARCH_WORDS)[0]; if (!arch) arch = Object.keys(ARCH)[hashStr(text) % Object.keys(ARCH).length];
  t.archKey = arch; t.arch = ARCH[arch];
  let vibe = null; for (const k in VIBES) { if (w.some(x => VIBES[k].words.split(' ').includes(x))) { vibe = k; break; } }
  if (!vibe) vibe = R.pick(Object.keys(VIBES)); t.vibeKey = vibe; t.vibe = VIBES[vibe];
  let hue = null; const colorWord = w.find(x => x in HUES); if (colorWord !== undefined) { hue = HUES[colorWord]; t.colorWord = colorWord; }
  if (hue === null || hue === -1) hue = R.i(0, 359); t.hue = hue;
  t.acc = matchList(w, ACC);
  if (t.arch.horns && !t.acc.includes('horns')) t.acc.push('horns'); if (t.arch.hat === 'pointy' && !t.acc.includes('wizhat')) t.acc.push('wizhat'); if (t.arch.sword && !t.acc.includes('sword')) t.acc.push('sword');
  if (!t.acc.length && R.chance(.55)) t.acc.push(R.pick(['crown', 'cap', 'scarf', 'glasses', 'headphones', 'backpack', 'cape', 'halo']));
  if (t.acc.includes('wizhat')) t.acc = t.acc.filter(a => a !== 'cap'); if (t.acc.includes('crown')) t.acc = t.acc.filter(a => a !== 'tophat');
  t.acc = t.acc.filter((a, i, z) => z.indexOf(a) === i).slice(0, 3);
  t.chonk = (t.arch.chonk || 1) * (w.some(x => ['chonky', 'chubby', 'fat', 'thicc', 'round', 'big'].includes(x)) ? 1.35 : w.some(x => ['slim', 'skinny', 'thin', 'lanky'].includes(x)) ? .78 : 1);
  t.scale = w.some(x => ['tiny', 'small', 'mini', 'baby', 'micro'].includes(x)) ? .82 : w.some(x => ['huge', 'giant', 'tall', 'large', 'mega', 'boss'].includes(x)) ? 1.2 : 1;
  t.head = (t.arch.head || 1) * (w.some(x => ['cute', 'chibi', 'baby', 'kawaii'].includes(x)) ? 1.22 : 1);
  t.torso = w.some(x => ['tall', 'long'].includes(x)) ? 1.25 : 1; t.leg = t.arch.slim ? 1.3 : 1;
  t.eyes = t.arch.visor ? 'visor' : t.arch.bigEyes ? 'huge' : R.chance(.24) ? 'dot' : 'big';
  t.mood = w.some(x => ['angry', 'evil', 'grumpy', 'mean'].includes(x)) ? 'smug' : w.some(x => ['sleepy', 'tired'].includes(x)) ? 'sleepy' : R.pick(['happy', 'happy', 'calm', 'smug', 'sleepy', 'surprised']);
  return t;
}
function makePalette(t) {
  const v = t.vibe, h = t.hue;
  return {body: hsl2hex(h, v.s, v.l), body2: hsl2hex(h + v.shift, v.s * .92, v.l - 9), belly: hsl2hex(h + v.shift * .4, Math.max(10, v.s * .34), Math.min(93, v.l + 25)),
    accent: hsl2hex(h + v.acc, Math.min(100, v.s + 14), v.accL), accent2: hsl2hex(h + v.acc + 28, Math.min(100, v.s + 6), Math.max(24, v.accL - 16)), dark: hsl2hex(h, Math.min(52, v.s * .5), 13),
    metal: hsl2hex(h + 200, 10, 60), eyew: hsl2hex(h, 14, 95), blush: hsl2hex(h + 320, 72, 72), gold: hsl2hex(44, 86, 58)};
}
const NA = ['Bo', 'Piko', 'Mim', 'Tato', 'Nubs', 'Wobb', 'Kiri', 'Dodo', 'Muff', 'Zib', 'Pom', 'Guff', 'Tilly', 'Ori', 'Snib', 'Lumo', 'Fizz', 'Nib', 'Roro', 'Skip'];
const NB = ['blossom', 'bramble', 'stack', 'muffin', 'socks', 'pebble', 'thunder', 'noodle', 'biscuit', 'sprocket', 'glimmer', 'tumble', 'crumb', 'spark', 'pudding', 'waffle', 'cobble', 'fizzle'];
const makeName = () => R.pick(NA) + ' ' + R.pick(NB).replace(/^./, c => c.toUpperCase());

/* ---------- the builder ---------- */
function surfZ(M, x, y) { let z = 0; if (!M.has(x, y, 0)) return null; while (z < 48 && M.has(x, y, z + 1)) z++; return z; }
function addEyes(M, P, t, cx, ey, style) {
  const ex = Math.max(1, cx);
  for (const sx of [-1, 1]) {
    const px = sx * ex, front = surfZ(M, px, ey); if (front === null) continue;
    if (style === 'dot') { M.set(px, ey, front + 1, P.dark); M.set(px, ey + 1, front + 1, P.eyew); }
    else if (style === 'huge') { for (let dy = 0; dy < 4; dy++) for (let dx = 0; dx < 3; dx++) { if (dy === 0 && dx === 2) continue; M.set(px + sx * dx, ey + dy - 1, front + 1, P.dark); } M.set(px + sx * 1, ey + 2, front + 1, P.eyew); M.set(px, ey + 1, front + 1, P.accent); }
    else { for (let dy = 0; dy < 3; dy++) for (let dx = 0; dx < 2; dx++) M.set(px + sx * dx, ey + dy, front + 1, P.eyew); const lid = t.mood === 'sleepy' ? 1 : 0; for (let dy = 0; dy < 2 - lid; dy++) for (let dx = 0; dx < 2; dx++) M.set(px + sx * dx, ey + dy, front + 2, P.dark); M.set(px + sx * 1, ey + 2, front + 2, P.eyew); if (t.mood === 'sleepy') for (let dx = 0; dx < 2; dx++) M.set(px + sx * dx, ey + 1, front + 2, P.dark); }
  }
}
function addMouth(M, P, t, y) {
  const front = surfZ(M, 0, y); if (front === null) return;
  if (t.mood === 'surprised') { M.set(0, y, front + 1, P.dark); M.set(0, y - 1, front + 1, P.dark); }
  else if (t.mood === 'sleepy' || t.mood === 'smug') { for (let x = -1; x <= 1; x++) M.set(x, y, front + 1, P.dark); if (t.mood === 'smug') M.set(2, y + 1, front + 1, P.dark); }
  else { M.set(0, y, front + 1, P.dark); M.set(-1, y + 1, front + 1, P.dark); M.set(1, y + 1, front + 1, P.dark); }
}
function buildBuddy(t) {
  const P = t.pal, A = t.arch, M = new Vox(), S = t.scale, blob = A.plan === 'blob';
  let hy0, headW, headH, headD, ty0 = 0, bodyW = 0, bodyH = 0, bodyD = 0, legH = 0, brx = 0, bry = 0, brz = 0;
  if (blob) {
    const bw = A.bw || 1, bh = A.bh || 1;
    const rx = Math.max(3, Math.round(6 * bw * t.chonk * S)), rz = Math.max(3, Math.round(5 * bw * t.chonk * S)), ry = Math.max(5, Math.round(9 * bh * S));
    brx = rx; bry = ry; brz = rz;
    if (A.cap) { for (let x = -rx; x <= rx; x++) for (let z = -rz; z <= rz; z++) { if ((x * x) / (rx * rx + .4) + (z * z) / (rz * rz + .4) > 1.05) continue; for (let y = 0; y <= ry; y++) M.set(x, y, z, P.belly); } }
    else dome(M, 0, ry, rx, rz, P.body, 0);
    if (A.wavy) for (let x = -rx; x <= rx; x++) for (let z = -rz; z <= rz; z++) { const cut = Math.round(1.6 + 1.4 * Math.sin(Math.atan2(z, x) * 3.2)); for (let y = 0; y < cut; y++) M.del(x, y, z); }
    if (A.jelly) for (let i = 0; i < 4; i++) M.set(-rx + 2 + (i % 2), ry - 2 - ((i / 2) | 0), Math.max(0, rz - 3), P.eyew);
    if (A.cap) { const cr = rx + 3, cd = rz + 3; for (let x = -cr; x <= cr; x++) for (let z = -cd; z <= cd; z++) for (let y = 0; y < 6; y++) { const v = (x * x) / (cr * cr) + (y * y) / 30 + (z * z) / (cd * cd); if (v <= 1.02) M.set(x, ry - 1 + y, z, P.accent); } for (let i = 0; i < 7; i++) M.set(R.i(-cr + 1, cr - 1), ry + 3, R.i(-cd + 1, cd - 1), P.belly); }
    if (A.nubs) pair(M, rx + 1, Math.round(ry * .45), 0, 1, 2, 2, A.cap ? P.belly : P.body);
    hy0 = 0; headW = rx * 2 + 1; headH = ry + 1; headD = rz * 2 + 1;
  } else {
    bodyW = odd(6.4 * t.chonk * S); bodyD = odd(5 * t.chonk * S); bodyH = Math.max(4, Math.round(6 * t.torso * S * (A.slim ? .9 : 1))); legH = Math.max(2, Math.round(3 * t.leg * S));
    headW = odd(9.6 * t.head * S); headH = Math.max(5, Math.round(8 * t.head * S)); headD = odd(8.4 * t.head * S);
    ty0 = legH; hy0 = ty0 + bodyH;
    const legW = Math.max(1, Math.round(bodyW / 3.4)), legX = Math.max(1, Math.round(bodyW / 4));
    const footCol = A.beak ? P.accent : (A.metal || A.helmet) ? P.metal : P.body2;
    pair(M, legX, 0, 0, legW, legH, Math.max(2, bodyD - 2), P.body2); pair(M, legX, 0, 1, legW + (A.feet === 'wide' ? 2 : 1), 1, bodyD - 1, footCol);
    bx(M, 0, ty0, 0, bodyW, bodyH, bodyD, A.armor ? P.metal : P.body, 1);
    if (A.armor) bx(M, 0, ty0 + bodyH - 2, fz(bodyD), bodyW - 2, 1, 1, P.accent);
    if (A.belly || A.patch || t.chonk > 1.1) bx(M, 0, ty0 + 1, fz(bodyD), Math.max(3, bodyW - 2), Math.max(2, bodyH - 1), 1, P.belly);
    if (A.metal) for (let y = ty0 + 1; y < ty0 + bodyH; y += 2) bx(M, 0, y, fz(bodyD), bodyW - 4, 1, 1, P.accent);
    const armH = Math.max(3, Math.round(bodyH * .85)), armX = ((bodyW - 1) >> 1) + 1, armW = A.flippers ? 1 : Math.max(1, Math.round(bodyW / 5));
    pair(M, armX, ty0 + bodyH - armH, 0, armW, armH, A.flippers ? 4 : Math.max(2, bodyD - 2), P.body); pair(M, armX, ty0 + bodyH - armH - 1, 0, armW + (A.boxy ? 1 : 0), 1, Math.max(2, bodyD - 2), A.metal ? P.metal : P.belly);
    bx(M, 0, hy0, 0, headW, headH, headD, P.body, 2);
    if (A.patch) pair(M, Math.round(headW * .26), hy0 + Math.round(headH * .42), fz(headD), 3, 3, 1, P.dark);
    const muzY = hy0 + Math.round(headH * .3);
    if (A.muzzle) bx(M, 0, muzY, fz(headD) + 1, Math.max(3, Math.round(headW * .4)), 3, 2, P.belly);
    if (A.snout) bx(M, 0, muzY, fz(headD) + 2, Math.max(3, headW - 4), Math.max(3, Math.round(headH * .4)), 4, P.body2);
    if (A.beak) { bx(M, 0, muzY + 1, fz(headD) + 2, 3, 2, 3, P.accent); bx(M, 0, muzY + 1, fz(headD) + 4, 1, 1, 1, P.accent); }
    if (A.whisk) for (const sx of [-1, 1]) for (let i = 1; i <= 2; i++) M.set(sx * (Math.round(headW * .3) + i), muzY + 1, fz(headD) + 1, P.dark);
    if (A.gills) for (let i = 0; i < 3; i++) pair(M, ((headW - 1) >> 1) + 1, hy0 + Math.round(headH * .45) + i * 2, -1 + i, 2, 1, 1, P.accent);
    const ex = Math.max(2, Math.round(headW * .3));
    if (A.ears === 'point') for (let i = 0; i < 3; i++) pair(M, ex, hy0 + headH + i, 0, Math.max(1, 3 - i), 1, 2, i > 1 ? P.blush : P.body);
    if (A.ears === 'round') pair(M, ex + 1, hy0 + headH - 1, 0, 3, 3, 2, P.body, 1);
    if (A.ears === 'long') { pair(M, ex, hy0 + headH, 0, 2, Math.max(5, Math.round(7 * S)), 2, P.body); pair(M, ex, hy0 + headH + 1, 1, 1, Math.max(3, Math.round(5 * S)), 1, P.blush); }
    if (A.ears === 'flop') pair(M, ((headW - 1) >> 1) + 1, hy0 + Math.round(headH * .5), 0, 2, Math.round(4 * S), 3, P.body2);
    if (A.spikes) { const tz = -fz(headD); for (let i = 0; i < 4; i++) bx(M, 0, hy0 - i, tz - i, 1, 1, 1, P.accent); for (let i = 0; i < 3; i++) bx(M, 0, hy0 + headH - 1 - i * 2, -fz(headD) - 1, 1, 1, 1, P.accent); }
    if (A.helmet) { bx(M, 0, hy0 + Math.round(headH * .55), 0, headW + 2, Math.round(headH * .5) + 1, headD + 2, P.metal, 2); const sy = hy0 + Math.round(headH * .62), sz = fz(headD) + 2; bx(M, 0, sy, sz, headW - 2, 1, 1, P.dark); for (const sx of [-1, 1]) M.set(sx * 2, sy, sz + 1, P.accent); }
    if (A.beard) for (let i = 0; i < 3; i++) bx(M, 0, hy0 + Math.round(headH * .2) - i, fz(headD) + 1, Math.max(1, 5 - i * 2), 1, 2, P.eyew);
    if (A.tail === 'curl') for (let i = 0; i < 5; i++) bx(M, 0, ty0 + 1 + i, -fz(bodyD) - 1 - Math.round(Math.sin(i * .9) * 1.6), 2, 1, 2, P.body2);
    if (A.tail === 'bush') for (let i = 0; i < 4; i++) bx(M, 0, ty0 + 1 + i, -fz(bodyD) - 2, 3, 2, 3, i > 2 ? P.eyew : P.body2);
    if (A.tail === 'stub') bx(M, 0, ty0 + Math.round(bodyH * .6), -fz(bodyD) - 1, 2, 2, 2, P.body2);
    if (A.tail === 'puff') bx(M, 0, ty0 + 1, -fz(bodyD) - 2, 3, 3, 3, P.eyew, 1);
    if (A.tail === 'thick') for (let i = 0; i < 5; i++) bx(M, 0, Math.max(0, ty0 - 1 + Math.round(i * .4)), -fz(bodyD) - 1 - i, Math.max(1, 4 - Math.round(i * .7)), Math.max(1, 4 - Math.round(i * .7)), 1, P.body);
    if (A.tail === 'flat') for (let i = 0; i < 4; i++) bx(M, 0, ty0 + 1, -fz(bodyD) - 1 - i, 1, Math.max(2, 5 - i), 1, P.accent);
    if (A.tail === 'fan') for (let i = 0; i < 3; i++) bx(M, 0, ty0, -fz(bodyD) - 1 - i, 3, 2, 1, P.accent2);
    if (A.wings === 'feather') for (let i = 0; i < 4; i++) pair(M, armX + 1, ty0 + bodyH - 2 - i, -fz(bodyD) - i, 1, 2, 2, P.accent2);
    if (A.wings === 'bat') for (let i = 0; i < 5; i++) pair(M, armX + 1 + i, ty0 + bodyH - 1 - Math.round(i * .4), -fz(bodyD) - 1, 1, Math.max(1, 5 - i), 2, P.accent2);
  }
  const topY = hy0 + headH, hatW = blob ? Math.max(5, odd(brx)) : headW, hatD = blob ? Math.max(5, odd(brz)) : headD, neckY = blob ? Math.round(bry * .55) : hy0 - 1;
  const neckW = blob ? Math.max(3, odd(2 * brx * Math.sqrt(Math.max(.04, 1 - Math.pow(neckY / bry, 2))))) : bodyW, neckD = blob ? Math.max(3, odd(2 * brz * Math.sqrt(Math.max(.04, 1 - Math.pow(neckY / bry, 2))))) : bodyD;
  for (const a of t.acc) {
    if (a === 'crown') { bx(M, 0, topY, 0, hatW - 2, 1, hatD - 2, P.gold); for (let i = -1; i <= 1; i++) bx(M, i * Math.round(hatW * .22), topY + 1, 0, 1, 2, 1, P.gold); }
    if (a === 'tophat') { bx(M, 0, topY, 0, hatW + 1, 1, hatD + 1, P.dark); bx(M, 0, topY + 1, 0, Math.max(3, hatW - 3), 4, Math.max(3, hatD - 3), P.dark); bx(M, 0, topY + 3, 0, Math.max(3, hatW - 2), 1, Math.max(3, hatD - 2), P.accent); }
    if (a === 'cap') { bx(M, 0, topY - 1, 0, hatW, 2, hatD, P.accent, 1); bx(M, 0, topY - 1, fz(hatD) + 2, Math.max(3, hatW - 4), 1, 3, P.accent); }
    if (a === 'wizhat') { bx(M, 0, topY, 0, hatW + 2, 1, hatD + 2, P.accent2, 1); for (let i = 0; i < Math.round(7 * S); i++) bx(M, 0, topY + 1 + i, -Math.round(i * .3), Math.max(1, hatW - 3 - i), 1, Math.max(1, hatD - 3 - i), P.accent2); }
    if (a === 'halo') for (let x = -3; x <= 3; x++) for (let z = -3; z <= 3; z++) { const d = Math.sqrt(x * x + z * z); if (d > 2.2 && d < 3.2) M.set(x, topY + 2, z, P.gold); }
    if (a === 'scarf') { bx(M, 0, neckY, 0, neckW + 2, 2, neckD + 2, P.accent, 1); for (let i = 0; i < 3; i++) bx(M, Math.round(neckW * .4), neckY - 1 - i, fz(neckD) + 1, 2, 1, 1, P.accent); }
    if (a === 'cape') { const zz = -fz(neckD) - 1; for (let i = 0; i < (blob ? Math.round(bry * .7) : bodyH + 2); i++) bx(M, 0, (blob ? 1 : ty0) + i, zz, Math.max(3, neckW + 2 - Math.max(0, 2 - i)), 1, 1, P.accent); }
    if (a === 'glasses') { const gy = hy0 + Math.round(headH * (blob ? .55 : .45)) + 2; for (let x = -Math.round(headW * .34); x <= Math.round(headW * .34); x++) { const f = surfZ(M, x, gy); if (f !== null) M.set(x, gy, f + 1, P.dark); } }
    if (a === 'headphones') { bx(M, 0, topY, 0, hatW + 2, 1, 3, P.dark); pair(M, ((hatW - 1) >> 1) + 1, hy0 + Math.round((blob ? bry : headH) * .5), 0, 2, 3, 3, P.accent); }
    if (a === 'backpack') bx(M, 0, blob ? 2 : ty0 + 1, -fz(neckD) - 2, Math.max(3, neckW - 1), Math.max(3, bodyH || Math.round(bry * .6)), 2, P.accent2, 1);
    if (a === 'horns') for (let i = 0; i < 3; i++) pair(M, Math.round(hatW * .3), topY - 1 + i, -i, Math.max(1, 2 - Math.round(i * .6)), 1, 1, P.eyew);
    if (a === 'wings' && !A.wings) for (let i = 0; i < 4; i++) pair(M, (blob ? brx : bodyW >> 1) + 2 + i, (blob ? Math.round(bry * .5) : ty0 + 2) + Math.round(i * .8), -fz(neckD) - 1, 1, Math.max(2, 5 - i), 2, P.eyew);
    if (a === 'antenna' && !A.antenna) { bx(M, 0, topY, 0, 1, 3, 1, P.metal); bx(M, 0, topY + 3, 0, 1, 1, 1, P.accent); }
    if (a === 'sword') { const hx = ((blob ? brx : bodyW >> 1)) + 2, by = (blob ? 3 : ty0 + 1); bx(M, hx, by + 2, 0, 1, Math.round(11 * S), 2, P.metal); bx(M, hx, by + 1, 0, 3, 1, 2, P.gold); bx(M, hx, by - 1, 0, 1, 2, 1, P.dark); }
  }
  if (A.antenna) { bx(M, 0, topY, 0, 1, Math.round(3 * S), 1, P.metal); bx(M, 0, topY + Math.round(3 * S), 0, 1, 1, 1, P.accent); }
  const eX = Math.max(2, Math.round(headW * .24));
  if (A.topEyes) pair(M, eX, hy0 + headH, 0, 3, 2, 3, P.body, 1);
  const ey = A.topEyes ? hy0 + headH : hy0 + Math.round(headH * (blob ? .55 : .45));
  if (!A.visor && !A.helmet) { addEyes(M, P, t, eX, ey, t.eyes); addMouth(M, P, t, ey - 2); }
  if (A.wideMouth) for (let x = -2; x <= 2; x++) { const f = surfZ(M, x, ey - 2); if (f !== null) M.set(x, ey - 2, f + 1, P.dark); }
  if (t.head > 1.1 || A.ears) for (const sx of [-1, 1]) { const bxx = sx * (eX + 2), f = surfZ(M, bxx, ey - 1); if (f !== null) M.set(bxx, ey - 1, f + 1, P.blush); }
  if (A.visor) { const f = surfZ(M, 0, ey); bx(M, 0, ey, f + 1, headW - 2, 2, 1, P.dark); bx(M, 0, ey + 1, f + 2, headW - 4, 1, 1, P.accent); }
  topLight(M);
  return M;
}
/* a build sheet from the local model: every value is clamped, so a strange sheet makes a strange buddy, never a broken page */
function buildFromSheet(sheet) {
  const M = new Vox(), pal = sheet.palette || {}, hex = /^#[0-9a-fA-F]{6}$/;
  const col = c => hex.test(c || '') ? c : (hex.test(pal[c] || '') ? pal[c] : (hex.test(pal.body || '') ? pal.body : '#9AA6B8'));
  const n = (v, lo, hi, dflt) => { const x = Math.round(Number(v)); return Number.isFinite(x) ? Math.max(lo, Math.min(hi, x)) : dflt; };
  for (const p of (Array.isArray(sheet.parts) ? sheet.parts.slice(0, 90) : [])) {
    const x = n(p.x, -24, 24, 0), y = n(p.y, 0, 44, 0), z = n(p.z, -24, 24, 0), c = col(p.color);
    if (p.shape === 'dome') domeAt(M, x, y, z, n(p.rx, 1, 16, 3), n(p.ry, 1, 20, 3), n(p.rz, 1, 16, 3), c);
    else if (p.shape === 'cyl') cyl(M, x, y, z, n(p.rx, 1, 16, 2), n(p.rz, 1, 16, 2), n(p.h, 1, 32, 3), c);
    else { const w = n(p.w, 1, 40, 3), h = n(p.h, 1, 40, 3), d = n(p.d, 1, 40, 3), b = n(p.bevel, 0, 3, 0); (p.mirror ? pair : bx)(M, x, y, z, w, h, d, c, b); }
  }
  if (!M.size) return null;
  const P = {dark: col('dark'), eyew: col('eyew'), accent: col('accent'), blush: col('blush')}, mood = (sheet.mouth && sheet.mouth.mood) || 'happy', e = sheet.eyes || {};
  let mnY = 1e9, mxY = -1e9; for (const c of M.cells()) { mnY = Math.min(mnY, c[1]); mxY = Math.max(mxY, c[1]); }
  const ey = n(e.y, mnY, mxY, Math.round(mnY + (mxY - mnY) * .72));
  addEyes(M, P, {mood}, n(e.spread, 1, 12, 2), ey, ['big', 'dot', 'huge'].includes(e.style) ? e.style : 'big');
  if (mood !== 'none') addMouth(M, P, {mood}, n((sheet.mouth || {}).y, mnY, mxY, ey - 2));
  topLight(M);
  return M;
}

/* ---------- .vox (MagicaVoxel 150) ---------- */
function encodeVox(cells, dims, palHex) {
  const nv = cells.length, size = 12, xyzi = 4 + nv * 4, rgba = 1024, total = 12 + size + 12 + xyzi + 12 + rgba;
  const buf = new ArrayBuffer(8 + 12 + total), dv = new DataView(buf); let o = 0;
  const tag = s => { for (let i = 0; i < 4; i++) dv.setUint8(o++, s.charCodeAt(i)); }, i32 = v => { dv.setInt32(o, v, true); o += 4; };
  tag('VOX '); i32(150); tag('MAIN'); i32(0); i32(total); tag('SIZE'); i32(12); i32(0); i32(dims[0]); i32(dims[1]); i32(dims[2]); tag('XYZI'); i32(xyzi); i32(0); i32(nv);
  for (const c of cells) { dv.setUint8(o++, c[0]); dv.setUint8(o++, c[1]); dv.setUint8(o++, c[2]); dv.setUint8(o++, c[3]); }
  tag('RGBA'); i32(rgba); i32(0);
  for (let i = 0; i < 256; i++) { const hex = palHex[i] || '#000000', n = parseInt(hex.slice(1), 16); dv.setUint8(o++, (n >> 16) & 255); dv.setUint8(o++, (n >> 8) & 255); dv.setUint8(o++, n & 255); dv.setUint8(o++, palHex[i] ? 255 : 0); }
  return buf;
}
function toVoxData(M) {
  const cells = M.cells(); let mnx = 1e9, mny = 1e9, mnz = 1e9, mxx = -1e9, mxy = -1e9, mxz = -1e9;
  for (const c of cells) { mnx = Math.min(mnx, c[0]); mny = Math.min(mny, c[1]); mnz = Math.min(mnz, c[2]); mxx = Math.max(mxx, c[0]); mxy = Math.max(mxy, c[1]); mxz = Math.max(mxz, c[2]); }
  return {cells: cells.map(c => [c[0] - mnx, c[2] - mnz, c[1] - mny, Math.min(255, c[3] + 1)]), dims: [mxx - mnx + 1, mxz - mnz + 1, mxy - mny + 1], pal: M.pal.slice(0, 255), span: [mxx - mnx + 1, mxy - mny + 1, mxz - mnz + 1]};
}

/* ---------- build entry points ---------- */
function build(prompt, seed) {
  prompt = (prompt || '').trim() || 'a friendly voxel buddy';
  seed = seed === undefined || seed === null ? Math.floor(Math.random() * 1e9) : seed;
  R.init(hashStr(prompt.toLowerCase()) ^ seed);
  const t = readPrompt(prompt); t.seed = seed; t.prompt = prompt; t.pal = makePalette(t); t.name = makeName();
  t.species = ARCH[t.archKey].name; t.vibe = t.vibeKey;
  const M = fitToCap(buildBuddy(t));
  return {t, M, vox: toVoxData(M)};
}
function fromSheet(sheet, prompt) {
  const raw = buildFromSheet(sheet); if (!raw || !raw.size) throw new Error('empty build');
  const M = fitToCap(raw);
  return {t: {prompt, sheet, name: sheet.name || 'Unnamed Buddy', pal: sheet.palette || {}, species: 'AI build', vibe: ''}, M, vox: toVoxData(M)};
}

/* ---------- three.js stage: the preview, and the camera that turns a buddy into sprite frames ---------- */
class Stage {
  constructor(canvas, opts = {}) {
    this.canvas = canvas; this.yaw = .62; this.pitch = .26; this.dist = 40; this.spin = !opts.still; this.h = 16; this.pose = {yaw: 0, bob: 0, tilt: 0, scale: 1};
    this.reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const tries = [{antialias: true, alpha: true, preserveDrawingBuffer: true}, {antialias: false, alpha: true, preserveDrawingBuffer: true}, {antialias: false, alpha: true, preserveDrawingBuffer: true, powerPreference: 'low-power'}];
    let last = null; for (const o of tries) { try { this.renderer = new THREE.WebGLRenderer(Object.assign({canvas}, o)); break; } catch (e) { last = e; } }
    if (!this.renderer) throw last || new Error('WebGL is not available.');
    const r = this.renderer; r.setPixelRatio(Math.min(devicePixelRatio, 2)); r.setClearColor(0x000000, 0); r.shadowMap.enabled = true; r.shadowMap.type = THREE.PCFSoftShadowMap;
    this.scene = new THREE.Scene(); this.camera = new THREE.PerspectiveCamera(30, 1, .1, 400); this.group = new THREE.Group(); this.scene.add(this.group);
    this.scene.add(new THREE.HemisphereLight(0xffffff, 0x8a7f9c, .95));
    const key = new THREE.DirectionalLight(0xffffff, 1.6); key.position.set(22, 34, 26); key.castShadow = true; key.shadow.mapSize.set(1024, 1024);
    Object.assign(key.shadow.camera, {left: -30, right: 30, top: 30, bottom: -30, far: 140}); key.shadow.bias = -.002; this.scene.add(key);
    const rim = new THREE.DirectionalLight(0xffffff, .5); rim.position.set(-20, 14, -18); this.scene.add(rim);
    this.plane = new THREE.Mesh(new THREE.PlaneGeometry(160, 160), new THREE.ShadowMaterial({opacity: .22})); this.plane.rotation.x = -Math.PI / 2; this.plane.receiveShadow = true; this.scene.add(this.plane);
    this.dummy = new THREE.Object3D(); this.mesh = null; this.intro = null;
    if (!opts.still) {
      let drag = null;
      canvas.addEventListener('pointerdown', e => { drag = {x: e.clientX, y: e.clientY}; canvas.setPointerCapture(e.pointerId); });
      canvas.addEventListener('pointermove', e => { if (!drag) return; this.yaw -= (e.clientX - drag.x) * .01; this.pitch = Math.max(-.5, Math.min(1.2, this.pitch + (e.clientY - drag.y) * .006)); drag = {x: e.clientX, y: e.clientY}; });
      canvas.addEventListener('pointerup', () => { drag = null; }); canvas.addEventListener('pointercancel', () => { drag = null; });
      canvas.addEventListener('wheel', e => { e.preventDefault(); this.dist = Math.max(12, Math.min(120, this.dist + e.deltaY * .05)); }, {passive: false});
      this.resize(); new ResizeObserver(() => this.resize()).observe(canvas.parentElement || canvas);
      requestAnimationFrame(t => this.loop(t));
    }
  }
  resize() { const w = this.canvas.clientWidth || 300, h = this.canvas.clientHeight || 300; this.renderer.setSize(w, h, false); this.camera.aspect = w / h; this.camera.updateProjectionMatrix(); }
  show(M, instant) {
    if (this.mesh) { this.group.remove(this.mesh); this.mesh.geometry.dispose(); this.mesh.material.dispose(); this.mesh = null; }
    if (!M) return;
    const cells = M.cells(), keep = cells.filter(c => !(M.has(c[0] + 1, c[1], c[2]) && M.has(c[0] - 1, c[1], c[2]) && M.has(c[0], c[1] + 1, c[2]) && M.has(c[0], c[1] - 1, c[2]) && M.has(c[0], c[1], c[2] + 1) && M.has(c[0], c[1], c[2] - 1)));
    let mnx = 1e9, mxx = -1e9, mny = 1e9, mxy = -1e9, mnz = 1e9, mxz = -1e9;
    for (const c of cells) { mnx = Math.min(mnx, c[0]); mxx = Math.max(mxx, c[0]); mny = Math.min(mny, c[1]); mxy = Math.max(mxy, c[1]); mnz = Math.min(mnz, c[2]); mxz = Math.max(mxz, c[2]); }
    const cx = (mnx + mxx) / 2, cz = (mnz + mxz) / 2, hgt = mxy - mny + 1;
    const mesh = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 1, 1), new THREE.MeshLambertMaterial({color: 0xffffff}), keep.length);
    mesh.castShadow = true; mesh.receiveShadow = true; mesh.userData.pos = []; mesh.userData.delay = [];
    const col = new THREE.Color();
    for (let i = 0; i < keep.length; i++) {
      const c = keep[i], px = c[0] - cx, py = c[1] - mny + .5, pz = c[2] - cz;
      mesh.userData.pos.push([px, py, pz]); mesh.userData.delay.push((1 - (c[1] - mny) / hgt) * 260 + Math.random() * 140);
      this.dummy.position.set(px, py, pz); this.dummy.scale.set(1, 1, 1); this.dummy.updateMatrix(); mesh.setMatrixAt(i, this.dummy.matrix);
      col.set(M.pal[c[3]]); mesh.setColorAt(i, col);
    }
    mesh.instanceMatrix.needsUpdate = true; if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
    this.group.add(mesh); this.mesh = mesh; this.h = hgt; this.dist = Math.max(26, hgt * 2.5); this.yaw = .55; this.pitch = .22;
    this.intro = (instant || this.reduce) ? null : {t0: performance.now()};
  }
  place() {
    const h = this.h * .45, p = this.pose;
    this.camera.position.set(Math.sin(this.yaw) * Math.cos(this.pitch) * this.dist, h + Math.sin(this.pitch) * this.dist, Math.cos(this.yaw) * Math.cos(this.pitch) * this.dist);
    this.camera.lookAt(0, h, 0);
    this.group.rotation.set(0, p.yaw || 0, p.tilt || 0); this.group.position.set(0, p.bob || 0, 0); this.group.scale.setScalar(p.scale || 1);
  }
  loop(now) {
    requestAnimationFrame(t => this.loop(t));
    if (this.spin && !this.reduce) this.yaw += .0038;
    this.place();
    if (this.intro && this.mesh) {
      const el = now - this.intro.t0, m = this.mesh; let done = true;
      for (let i = 0; i < m.count; i++) { const d = m.userData.delay[i], p = Math.max(0, Math.min(1, (el - d) / 300)); if (p < 1) done = false; const e = p === 0 ? 0 : 1 + 2.2 * Math.pow(p - 1, 3) + 1.2 * Math.pow(p - 1, 2), P = m.userData.pos[i]; this.dummy.position.set(P[0], P[1] + (1 - p) * 6 * (1 - p), P[2]); this.dummy.scale.setScalar(Math.max(.001, e)); this.dummy.updateMatrix(); m.setMatrixAt(i, this.dummy.matrix); }
      m.instanceMatrix.needsUpdate = true; if (done) this.intro = null;
    }
    this.renderer.render(this.scene, this.camera);
  }
  /* one frame at a given size: the preview's current view, or an explicit pose for sprite sheets */
  snapshot(w, h, o = {}) {
    const r = this.renderer, cw = this.canvas.width, ch = this.canvas.height, keep = {yaw: this.yaw, pitch: this.pitch, dist: this.dist, pose: this.pose, plane: this.plane.visible};
    if (o.view) { this.yaw = o.view.yaw ?? this.yaw; this.pitch = o.view.pitch ?? this.pitch; this.dist = o.view.dist ?? this.dist; }
    if (o.pose) this.pose = o.pose; if (o.shadow === false) this.plane.visible = false;
    if (this.intro && this.mesh) { const m = this.mesh; for (let i = 0; i < m.count; i++) { const P = m.userData.pos[i]; this.dummy.position.set(P[0], P[1], P[2]); this.dummy.scale.setScalar(1); this.dummy.updateMatrix(); m.setMatrixAt(i, this.dummy.matrix); } m.instanceMatrix.needsUpdate = true; this.intro = null; }
    r.setSize(w, h, false); this.camera.aspect = w / h; this.camera.updateProjectionMatrix(); this.place(); r.render(this.scene, this.camera);
    const url = o.raw ? r.domElement : r.domElement.toDataURL(o.type || 'image/png', o.quality);
    if (o.raw) return url;
    r.setSize(cw / Math.min(devicePixelRatio, 2) || 300, ch / Math.min(devicePixelRatio, 2) || 300, false); this.camera.aspect = (cw || 1) / (ch || 1); this.camera.updateProjectionMatrix();
    Object.assign(this, {yaw: keep.yaw, pitch: keep.pitch, dist: keep.dist, pose: keep.pose}); this.plane.visible = keep.plane;
    return url;
  }
}
/* the 7 sprite frames the game engine expects, from a buddy */
const SPRITE_POSES = [{yaw: .35}, {yaw: .35, bob: 1.2, tilt: .07}, {yaw: .35, bob: 0}, {yaw: .35, bob: 1.2, tilt: -.07}, {yaw: .2, bob: 4, tilt: -.14, scale: 1.04}, {yaw: 1.1, tilt: .42, bob: .5}, {yaw: 0, bob: 2.5, scale: 1.06}];
let spriteStage = null;
function spriteSheet(M, fw = 128, fh = 192) {
  if (!spriteStage) { const c = document.createElement('canvas'); c.width = fw; c.height = fh; spriteStage = new Stage(c, {still: true}); }
  const st = spriteStage; st.show(M, true);
  const dist = Math.max(14, st.h * 2.45), out = document.createElement('canvas'); out.width = fw * 7; out.height = fh; const g = out.getContext('2d');
  st.renderer.setPixelRatio(1);
  SPRITE_POSES.forEach((pose, i) => { const cv = st.snapshot(fw, fh, {pose, view: {yaw: 0, pitch: .16, dist}, shadow: false, raw: true}); g.drawImage(cv, 0, 0, cv.width, cv.height, i * fw, 0, fw, fh); });
  return out;
}

window.VOX = {hashStr, build, fromSheet, encodeVox, toVoxData, fitToCap, Stage, spriteSheet, ARCH, VIBES, HUES, ACC,
  PRESETS: ['a chonky space penguin with a crown', 'tiny lava dragon with horns', 'cute pastel cat with headphones', 'frost knight with a sword', 'neon robot with an antenna', 'forest mushroom buddy',
    'spooky ghost with a scarf', 'royal panda with a cape', 'cyber axolotl', 'sleepy slime with a tiny hat', 'angry red slime', 'evil void bat with wings', 'grumpy lava crab', 'giant cosmic dragon boss']};
window.dispatchEvent(new Event('vox-ready'));
</script>
<script>
/* ═══════════════ 🕹 Game Creator ═══════════════ */
const GAME_HOST = <?= json_encode(GAME_HOST_HTML) ?>, GAME_RUNTIME = <?= json_encode(GAME_RUNTIME_JS) ?>;
const BG_ROW = SCHEMA.find(r => r[0] === 'bg')[2];
const FRAMES = ['idle', 'walk1', 'walk2', 'walk3', 'jump', 'hurt', 'win'];
const sleep = ms => new Promise(r => setTimeout(r, ms));
const voxReady = () => window.VOX ? Promise.resolve() : new Promise(r => window.addEventListener('vox-ready', r, {once: true}));
const uid = () => Math.random().toString(36).slice(2, 8);

/* ── the spec: everything about a game, small enough to keep in localStorage and in games/<id>/game.json ── */
function newLevel(n, mold) {
  const bgs = ['sky', 'stage', 'city', 'space', 'synthwave', 'hacker', 'studio', 'news'];
  return {id: uid(), name: 'Stage ' + n, bg: {kind: 'builtin', value: mold === 'shooter' ? 'space' : bgs[(n - 1) % bgs.length], color: '#3a86ff'}, music: '', length: n === 1 ? 'short' : 'medium', difficulty: Math.min(3, n), seed: Math.floor(Math.random() * 1e9), key: false, boss: false, map: null};
}
function defaultSpec() {
  return {version: 1, title: '', pitch: '', mold: 'platformer',
    cast: {hero: {kind: 'toon', name: '', char: normChar({...DEFAULT_CHAR, prop: 'none'}), speed: 1, jump: 1}, enemies: [{kind: 'buddy', name: 'Slime', buddy: {prompt: 'angry green slime', seed: 7}, behavior: 'patrol', speed: 1}], boss: null},
    levels: [newLevel(1, 'platformer')], rules: {lives: 3, difficulty: 'normal', gravity: 1, jump: 1, speed: 1, win: 'goal', timeLimit: 0},
    audio: {sfxStyle: 'chip', sfx: {}, music: {file: '', volume: .5}, voice: {saved_voice: '', tts_voice: 'am_michael', lines: {start: '', win: '', lose: '', hurt: ''}, files: {}}}, winText: '', loseText: ''};
}
function normSpec(s) {
  const d = defaultSpec(); s = s && typeof s === 'object' ? s : {};
  const out = {...d, ...s, cast: {...d.cast, ...(s.cast || {})}, rules: {...d.rules, ...(s.rules || {})}, audio: {...d.audio, ...(s.audio || {})}};
  out.audio.music = {...d.audio.music, ...(out.audio.music || {})}; out.audio.voice = {...d.audio.voice, ...(out.audio.voice || {})}; out.audio.voice.lines = {...d.audio.voice.lines, ...(out.audio.voice.lines || {})}; out.audio.voice.files = out.audio.voice.files || {}; out.audio.sfx = out.audio.sfx || {};
  if (!TG.MOLDS[out.mold]) out.mold = 'platformer';
  const member = (m, role) => { if (!m || typeof m !== 'object') return null; m = {...m}; if (m.kind === 'toon') m.char = normChar({...(m.char || DEFAULT_CHAR), prop: 'none'}); else { m.kind = 'buddy'; m.buddy = {prompt: '', seed: 1, ...(m.buddy || {})}; } m.name = String(m.name || ''); m.speed = +m.speed || 1; if (role === 'hero') m.jump = +m.jump || 1; if (role === 'enemy') m.behavior = TG.BEHAVIORS.includes(m.behavior) ? m.behavior : 'patrol'; if (role === 'boss') m.hp = Math.max(1, Math.min(12, +m.hp || 3)); return m; };
  out.cast.hero = member(out.cast.hero, 'hero') || d.cast.hero; out.cast.enemies = (Array.isArray(out.cast.enemies) ? out.cast.enemies : []).map(e => member(e, 'enemy')).filter(Boolean).slice(0, 3); out.cast.boss = member(out.cast.boss, 'boss');
  out.levels = (Array.isArray(out.levels) ? out.levels : []).map((l, i) => ({...newLevel(i + 1, out.mold), ...l, bg: {...newLevel(1).bg, ...(l.bg || {})}})).slice(0, 8);
  if (!out.levels.length) out.levels = [newLevel(1, out.mold)];
  return out;
}
const GS = {spec: null, sel: 0, tool: '#', chars: [], buddies: [], scenes: [], music: [], voices: [], picking: null, bud: null, sheets: new Map(), thumbs: new Map(), bgCache: new Map(), gameId: '', dirty: false};
try { GS.spec = normSpec(JSON.parse(localStorage.gameDraft || 'null')); } catch (e) { GS.spec = defaultSpec(); }
if (!GS.spec) GS.spec = defaultSpec();
let saveT = null;
function changed() { GS.dirty = true; clearTimeout(saveT); saveT = setTimeout(() => { try { localStorage.gameDraft = JSON.stringify(GS.spec); } catch (e) {} }, 300); }
const msg = (t, err) => { const m = $('#gMsg'); m.textContent = t; m.className = (err ? 'err' : 'hint'); };

/* ── sprites: a toon or a buddy becomes a 7-frame sheet (idle, 3 walk, jump, hurt, win) ── */
const SPR = {W: 400, H: 600};
function toonPose(name) {
  const arm = (sh, el) => ({rest: {sh, el}}), base = {bob: 0, lean: 0, tilt: 0, headY: 0, yaw: 0, squash: 1, shrug: 0, armL: arm(.13, .18), armR: arm(.13, .18), blink: 0, look: {x: 0, y: 0}, brow: 0, mouth: {...MOUTH0}, env: 0, T: 0, dx: 0};
  switch (name) {
    case 'walk1': return {...base, legs: {L: {hip: .05, knee: .85}, R: {hip: 0, knee: 0}}, lean: -.05, armL: arm(.42, .3), armR: arm(.1, .15), mouth: {...MOUTH0, open: .12}};
    case 'walk2': return {...base, bob: 6, legs: {L: {hip: 0, knee: .12}, R: {hip: 0, knee: .12}}};
    case 'walk3': return {...base, legs: {R: {hip: .05, knee: .85}, L: {hip: 0, knee: 0}}, lean: .05, armR: arm(.42, .3), armL: arm(.1, .15), mouth: {...MOUTH0, open: .12}};
    case 'jump': return {...base, legs: {L: {hip: .35, knee: .1}, R: {hip: .35, knee: .1}}, armL: arm(2.4, .3), armR: arm(2.4, .3), mouth: {...MOUTH0, open: .45, wide: .9}, look: {x: 0, y: -.6}, brow: .5};
    case 'hurt': return {...base, tilt: .28, lean: .15, blink: 1, mouth: {...MOUTH0, open: .75, wide: .75}, armL: arm(1.1, 1.3), armR: arm(1.1, 1.3), brow: 1, shrug: 8};
    case 'win': return {...base, armL: arm(2.6, .2), armR: arm(2.6, .2), mouth: {...MOUTH0, open: .3, wide: 1.15}, brow: .6, look: {x: 0, y: -.3}};
    default: return base;
  }
}
function drawToonFrame(g, char, pose) {
  const st = char.style; STY = ['anime', 'neon', 'sketch', 'clay', 'blueprint'].includes(st) ? st : 'classic'; LOOK = st === 'comic' ? 'comic' : 'classic';
  drawToon(g, SPR.W, SPR.H, char, pose); STY = 'classic'; LOOK = 'classic';
}
function toonSheet(char) {
  const c = normChar({...char, prop: 'none'}), key = 'T' + JSON.stringify(c);
  if (GS.sheets.has(key)) return GS.sheets.get(key);
  const out = document.createElement('canvas'); out.width = TG.FW * 7; out.height = TG.FH; const og = out.getContext('2d');
  const cv = document.createElement('canvas'); cv.width = SPR.W; cv.height = SPR.H; const g = cv.getContext('2d');
  const px = document.createElement('canvas'); px.width = 40; px.height = 60; const pg = px.getContext('2d');
  FRAMES.forEach((f, i) => {
    g.setTransform(1, 0, 0, 1, 0, 0); g.clearRect(0, 0, SPR.W, SPR.H); drawToonFrame(g, c, toonPose(f));
    if (c.style === 'pixel') {   // 8-bit: shrink, snap the colors, blow back up with hard pixels
      pg.clearRect(0, 0, 40, 60); pg.drawImage(cv, 0, 0, 40, 60); const img = pg.getImageData(0, 0, 40, 60), d = img.data, q = v => Math.round(v / 51) * 51;
      for (let k = 0; k < d.length; k += 4) { d[k] = q(d[k]); d[k + 1] = q(d[k + 1]); d[k + 2] = q(d[k + 2]); d[k + 3] = d[k + 3] > 110 ? 255 : 0; } pg.putImageData(img, 0, 0);
      og.imageSmoothingEnabled = false; og.drawImage(px, 0, 0, 40, 60, i * TG.FW, 0, TG.FW, TG.FH); og.imageSmoothingEnabled = true;
    } else og.drawImage(cv, 0, 0, SPR.W, SPR.H, i * TG.FW, 0, TG.FW, TG.FH);
  });
  const url = out.toDataURL(); GS.sheets.set(key, url); return url;
}
function buddyModel(b) { return b.sheet ? VOX.fromSheet(b.sheet, b.prompt || '') : VOX.build(b.prompt || 'a friendly buddy', b.seed || 1); }
function buddySheet(b) {
  const key = 'B' + JSON.stringify([b.prompt, b.seed, b.sheet]); if (GS.sheets.has(key)) return GS.sheets.get(key);
  const url = VOX.spriteSheet(buddyModel(b).M).toDataURL(); GS.sheets.set(key, url); return url;
}
async function castSheet(m) { if (m.kind === 'toon') return toonSheet(m.char); await voxReady(); return buddySheet(m.buddy); }
async function castThumb(m, w = 54, h = 81) {   // idle frame, small
  const key = (m.kind === 'toon' ? 'T' + JSON.stringify(m.char) : 'B' + JSON.stringify([m.buddy.prompt, m.buddy.seed, m.buddy.sheet])) + w;
  if (GS.thumbs.has(key)) return GS.thumbs.get(key);
  const sheet = await castSheet(m), im = new Image(); im.src = sheet; await new Promise(r => { im.onload = r; im.onerror = r; });
  const c = document.createElement('canvas'); c.width = w; c.height = h; c.getContext('2d').drawImage(im, 0, 0, TG.FW, TG.FH, 0, 0, w, h);
  const url = c.toDataURL(); GS.thumbs.set(key, url); return url;
}
function randomToon() {
  const c = {...DEFAULT_CHAR, name: ''};
  for (const [k, label, spec, multi] of SCHEMA) {
    if (!label || ['style', 'avatar', 'bg', 'bgColor', 'prop'].includes(k)) continue;
    if (typeof spec === 'string') c[k] = pick(PAL[spec].filter(x => x !== 'auto'));
    else if (typeof spec[0] === 'number') c[k] = +(spec[0] + Math.random() * (spec[1] - spec[0])).toFixed(2);
    else if (multi) c[k] = spec.map(s => s[0]).filter(() => Math.random() < .16);
    else c[k] = pick(spec)[0];
  }
  c.style = pick(['classic', 'classic', 'anime', 'comic', 'sketch', 'clay', 'pixel', 'neon', 'blueprint']); c.lips = 'auto'; c.prop = 'none'; if (Math.random() < .5) c.hat = 'none';
  return normChar(c);
}

/* ── backdrops: Toon Lab's backgrounds (and scene pictures), rendered once per stage ── */
const THEMES = {stage: {ground: '#5a3b2a', top: '#8b5a3c', sky: '#2a0f14', accent: '#ffd23f'}, sky: {ground: '#5b4636', top: '#7ccf5a', sky: '#48b5f0', accent: '#ffe066'}, studio: {ground: '#3b4252', top: '#8a93a8', sky: '#2e3440', accent: '#7fe3ff'},
  hacker: {ground: '#1d2a1f', top: '#39ff88', sky: '#06110a', accent: '#39ff88'}, city: {ground: '#2b2d42', top: '#9aa0b8', sky: '#14162a', accent: '#f15bb5'}, news: {ground: '#1e3a6e', top: '#4f8ff0', sky: '#0b1a3a', accent: '#ff5c39'},
  matrix: {ground: '#031a08', top: '#1fd65a', sky: '#000000', accent: '#39ff88'}, space: {ground: '#3a2a5c', top: '#b89cff', sky: '#06021a', accent: '#c7b9ff'}, synthwave: {ground: '#2a0a3a', top: '#ff4fd8', sky: '#120024', accent: '#00f0ff'},
  green: {ground: '#3a3a3a', top: '#9a9a9a', sky: '#00b140', accent: '#ffffff'}, image: {ground: '#2b2f3a', top: '#8fa3c7', sky: '#1a1d26', accent: '#ffd23f'}};
const shadeHex = (col, amt) => { const n = parseInt(col.slice(1), 16), f = v => Math.max(0, Math.min(255, Math.round(amt < 0 ? v * (1 + amt) : v + (255 - v) * amt))); return '#' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(v => f(v).toString(16).padStart(2, '0')).join(''); };
function themeFor(l) { if (l.bg.kind === 'scene') return THEMES.image; if (l.bg.value === 'color') return {ground: shadeHex(l.bg.color, -.55), top: shadeHex(l.bg.color, .25), sky: l.bg.color, accent: '#ffd23f'}; return THEMES[l.bg.value] || THEMES.sky; }
async function renderBackdrop(l) {
  const key = JSON.stringify(l.bg); if (GS.bgCache.has(key)) return GS.bgCache.get(key);
  const Cb = {...DEFAULT_CHAR, bg: l.bg.kind === 'scene' ? 'image' : l.bg.value, bgColor: l.bg.color, bgImage: l.bg.kind === 'scene' ? l.bg.value : '', prop: 'none'};
  if (Cb.bg === 'image') { const im = SCN.img[Cb.bgImage] || (SCN.img[Cb.bgImage] = Object.assign(new Image(), {src: 'scenes/' + encodeURIComponent(Cb.bgImage)})); if (!im.complete || !im.naturalWidth) await new Promise(r => { im.onload = r; im.onerror = r; }); }
  const c = document.createElement('canvas'); c.width = 960; c.height = 540; drawBg(c.getContext('2d'), 960, 540, Cb, 0.3);
  const url = c.toDataURL('image/jpeg', .85); GS.bgCache.set(key, url); return url;
}

/* ── step 1: game ── */
function renderGame() {
  const s = GS.spec; $('#gTitle').value = s.title; $('#gPitch').value = s.pitch;
  $('#gMold').innerHTML = Object.entries(TG.MOLDS).map(([k, m]) => `<button type="button" data-v="${k}" class="${s.mold === k ? 'on' : ''}">${esc(m.name)}</button>`).join('');
  $('#gMoldHint').textContent = TG.MOLDS[s.mold].blurb + ' ' + TG.MOLDS[s.mold].controls;
  $('#gMold').querySelectorAll('button').forEach(b => b.onclick = () => { if (s.mold === b.dataset.v) return; s.mold = b.dataset.v; s.levels.forEach(l => { l.map = null; }); changed(); renderGame(); renderLevels(); });
}
$('#gTitle').oninput = () => { GS.spec.title = $('#gTitle').value; changed(); };
$('#gPitch').oninput = () => { GS.spec.pitch = $('#gPitch').value; changed(); };

/* ── step 2: cast ── */
async function renderCast() {
  const s = GS.spec, slots = [['hero', s.cast.hero], ...s.cast.enemies.map((e, i) => ['enemy' + i, e]), ...(s.cast.boss ? [['boss', s.cast.boss]] : [])];
  $('#castList').innerHTML = slots.map(([id, m]) => {
    const role = id === 'hero' ? 'Hero' : id === 'boss' ? 'Boss' : 'Enemy ' + 'efg'[+id.slice(5)].toUpperCase();
    const knobs = id === 'hero' ? `<label>Speed <input type="range" data-k="speed" min="0.6" max="1.6" step="0.05" value="${m.speed}"></label><label>Jump <input type="range" data-k="jump" min="0.6" max="1.6" step="0.05" value="${m.jump}"></label>`
      : id === 'boss' ? `<label>Hits to beat <input type="range" data-k="hp" min="1" max="12" step="1" value="${m.hp}"></label><label>Speed <input type="range" data-k="speed" min="0.5" max="2" step="0.05" value="${m.speed}"></label>`
      : `<label>Moves <select data-k="behavior">${TG.BEHAVIORS.map(b => `<option value="${b}"${m.behavior === b ? ' selected' : ''}>${b}</option>`).join('')}</select></label><label>Speed <input type="range" data-k="speed" min="0.5" max="2" step="0.05" value="${m.speed}"></label>`;
    return `<div class="castcard" data-id="${id}"><img class="cthumb" alt=""><div class="cbody"><div class="row"><span class="crole">${role}</span><input type="text" data-k="name" value="${esc(m.name)}" placeholder="Name" maxlength="30"></div>
      <div class="cknobs">${knobs}</div><div class="row"><button type="button" class="shrink" data-pick="${id}">Pick…</button>${m.kind === 'toon' ? `<button type="button" class="shrink" data-rnd="${id}" title="A random toon">🎲</button>` : ''}${id !== 'hero' ? `<button type="button" class="shrink" data-rm="${id}" title="Remove">✕</button>` : ''}<span class="meta">${m.kind === 'toon' ? '🎨 toon · ' + esc(m.char.style) : '🧱 buddy'}</span></div></div></div>`;
  }).join('');
  const get = id => id === 'hero' ? s.cast.hero : id === 'boss' ? s.cast.boss : s.cast.enemies[+id.slice(5)];
  $('#castList').querySelectorAll('[data-k]').forEach(i => i.oninput = () => { const m = get(i.closest('.castcard').dataset.id), v = i.type === 'range' ? +i.value : i.value; m[i.dataset.k] = v; changed(); });
  $('#castList').querySelectorAll('[data-pick]').forEach(b => b.onclick = () => openPicker(b.dataset.pick));
  $('#castList').querySelectorAll('[data-rnd]').forEach(b => b.onclick = () => { const m = get(b.dataset.rnd); m.char = randomToon(); changed(); renderCast(); });
  $('#castList').querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { const id = b.dataset.rm; if (id === 'boss') { s.cast.boss = null; s.levels.forEach(l => { if (l.boss) { l.boss = false; l.map = null; } }); } else { s.cast.enemies.splice(+id.slice(5), 1); s.levels.forEach(l => { l.map = null; }); } changed(); renderCast(); renderLevels(); });
  $('#addEnemy').disabled = s.cast.enemies.length >= 3; $('#addBoss').disabled = !!s.cast.boss;
  for (const [id, m] of slots) { const img = $(`#castList .castcard[data-id="${id}"] .cthumb`); try { img.src = await castThumb(m); } catch (e) { console.warn(e); } }
}
$('#addEnemy').onclick = () => { const s = GS.spec; if (s.cast.enemies.length >= 3) return; s.cast.enemies.push({kind: 'buddy', name: pick(['Grub', 'Zap', 'Mook', 'Blip', 'Krang']), buddy: {prompt: pick(VOX ? VOX.PRESETS.slice(10) : ['angry red slime']), seed: Math.floor(Math.random() * 1e9)}, behavior: pick(TG.BEHAVIORS), speed: 1}); s.levels.forEach(l => { l.map = null; }); changed(); renderCast(); renderLevels(); };
$('#addBoss').onclick = () => { const s = GS.spec; if (s.cast.boss) return; s.cast.boss = {kind: 'buddy', name: 'Big Bad', buddy: {prompt: 'giant evil void dragon boss with horns', seed: Math.floor(Math.random() * 1e9)}, hp: 3, speed: 1}; const last = s.levels[s.levels.length - 1]; last.boss = true; last.map = null; changed(); renderCast(); renderLevels(); };
async function openPicker(id) {
  GS.picking = id; const p = $('#castPick'); p.hidden = false; $('#pickTitle').textContent = 'Pick the ' + (id === 'hero' ? 'hero' : id === 'boss' ? 'boss' : 'enemy');
  if (!GS.chars.length) GS.chars = await api('chars').catch(() => []);
  if (!GS.buddies.length) GS.buddies = await api('buddies').catch(() => []);
  renderPicker(); p.scrollIntoView({block: 'nearest'});
}
document.querySelectorAll('input[name=ptab]').forEach(r => r.onchange = renderPicker);
$('#pickClose').onclick = () => { $('#castPick').hidden = true; GS.picking = null; };
async function renderPicker() {
  const tab = document.querySelector('input[name=ptab]:checked').value, grid = $('#pickGrid');
  if (tab === 'toons') {
    const items = [{label: '🎲 Random toon', rnd: true}, ...Object.keys(PRESETS).map(k => ({label: '⭐ ' + k, char: normChar({...DEFAULT_CHAR, ...PRESETS[k], prop: 'none'})})), ...GS.chars.map(c => ({label: c.name || c.file, char: normChar({...c, prop: 'none'})}))];
    grid.innerHTML = items.map((it, i) => `<button type="button" class="pitem" data-i="${i}"><img alt=""><span>${esc(it.label)}</span></button>`).join('');
    $('#pickHint').innerHTML = `${GS.chars.length} saved toon${GS.chars.length === 1 ? '' : 's'} from Toon Lab. Make more there: build one, name it, Save. <a href="?p=toon">Open Toon Lab ↗</a>`;
    grid.querySelectorAll('.pitem').forEach(b => { const it = items[+b.dataset.i]; b.onclick = () => assign({kind: 'toon', char: it.rnd ? randomToon() : it.char, name: it.rnd ? '' : (it.char.name || it.label.replace(/^⭐ /, ''))}); });
    for (const [i, it] of items.entries()) { const img = grid.querySelector(`.pitem[data-i="${i}"] img`); if (!img) continue; if (it.rnd) { img.src = await castThumb({kind: 'toon', char: randomToon()}); continue; } img.src = await castThumb({kind: 'toon', char: it.char}); }
  } else {
    grid.innerHTML = GS.buddies.map(b => `<button type="button" class="pitem" data-id="${esc(b.id)}"><img alt="" src="${b.thumb || ''}"><span>${esc(b.name)}</span></button>`).join('') || '<div class="hint">No buddies saved yet. Make one in 🧱 Voxel buddies below and press 💾 Save.</div>';
    $('#pickHint').textContent = 'Saved voxel buddies. Build new ones below from a sentence.';
    grid.querySelectorAll('.pitem').forEach(b => b.onclick = () => { const bd = GS.buddies.find(x => x.id === b.dataset.id); assign({kind: 'buddy', name: bd.name, buddy: {prompt: bd.prompt, seed: bd.seed, sheet: bd.sheet || null}}); });
  }
}
function assign(m) {
  const s = GS.spec, id = GS.picking; if (!id) return;
  const prev = id === 'hero' ? s.cast.hero : id === 'boss' ? s.cast.boss : s.cast.enemies[+id.slice(5)];
  const next = {...prev, ...m}; if (!next.name) next.name = m.name || prev.name;
  if (id === 'hero') s.cast.hero = next; else if (id === 'boss') s.cast.boss = next; else s.cast.enemies[+id.slice(5)] = next;
  $('#castPick').hidden = true; GS.picking = null; changed(); renderCast();
}

/* ── voxel buddies ── */
let bStage = null;
async function initBuddies() {
  await voxReady();
  try { bStage = new VOX.Stage($('#bCanvas')); } catch (e) { $('#bMsg').textContent = 'The 3D view could not start (' + e.message + '). Buddies still build and work in games.'; }
  GS.buddies = await api('buddies').catch(() => []); renderBuddyGrid();
  if (!GS.bud) buildBuddy(VOX.PRESETS[Math.floor(Math.random() * 10)]);
}
function showBuddy(res, prompt) {
  GS.bud = {res, prompt, seed: res.t.seed, sheet: res.t.sheet || null};
  if (bStage) bStage.show(res.M);
  $('#bName').textContent = res.t.name; $('#bNameIn').value = res.t.name;
  $('#bSub').textContent = (res.t.species || '') + ' · ' + res.M.size.toLocaleString() + ' voxels' + (res.t.sheet ? ' · AI build' : ' · seed ' + (res.t.seed || 0).toString(36));
}
function buildBuddy(prompt, seed) { prompt = (prompt || '').trim() || 'a friendly buddy'; $('#bPrompt').value = prompt; showBuddy(VOX.build(prompt, seed), prompt); }
$('#bBuild').onclick = () => buildBuddy($('#bPrompt').value);
$('#bPrompt').onkeydown = e => { if (e.key === 'Enter') buildBuddy($('#bPrompt').value); };
$('#bRandom').onclick = () => buildBuddy(pick(VOX.PRESETS));
$('#bReroll').onclick = () => buildBuddy($('#bPrompt').value || (GS.bud && GS.bud.prompt));
$('#bAI').onclick = async () => {
  const prompt = $('#bPrompt').value.trim(); if (!prompt) { $('#bPrompt').focus(); return; }
  $('#bAI').disabled = true; $('#bMsg').textContent = `${$('#gModel').value || 'The local model'} is designing the parts… (10–60 s)`;
  try {
    const fd = new FormData(); fd.append('prompt', prompt); fd.append('model', $('#gModel').value);
    const {sheet} = await api('buddy_ai', fd);
    showBuddy(VOX.fromSheet(sheet, prompt), prompt); $('#bMsg').textContent = `✓ Designed by the AI: ${sheet.parts.length} parts. Not quite it? Ask again, or ↻ Reroll for the fast builder's take.`;
  } catch (e) { $('#bMsg').textContent = (e.message || 'The AI build failed') + ' — built it the fast way instead.'; buildBuddy(prompt); }
  $('#bAI').disabled = false;
};
$('#bSave').onclick = async () => {
  const b = GS.bud; if (!b) return;
  const thumb = bStage ? bStage.snapshot(120, 120, {type: 'image/webp', quality: .75}) : '';
  const data = {name: $('#bNameIn').value.trim() || b.res.t.name, prompt: b.prompt, seed: b.seed || 0, sheet: b.sheet, thumb};
  try { const fd = new FormData(); fd.append('data', JSON.stringify(data)); const r = await api('buddy_save', fd); GS.buddies.unshift(r); renderBuddyGrid(); $('#bMsg').textContent = `✓ ${r.name} joined the library.`; } catch (e) { $('#bMsg').textContent = e.message; }
};
$('#bVox').onclick = () => {
  const b = GS.bud; if (!b) return; const v = b.res.vox, buf = VOX.encodeVox(v.cells, v.dims, v.pal), name = ($('#bNameIn').value.trim() || b.res.t.name).toLowerCase().replace(/[^a-z0-9]+/g, '-') + '.vox';
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([buf])); a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 3000);
  $('#bMsg').textContent = `Saved ${name} — opens in MagicaVoxel, Goxel or Blender.`;
};
document.querySelectorAll('#buddyBox [data-use]').forEach(b => b.onclick = () => {
  const bd = GS.bud; if (!bd) return; const s = GS.spec, m = {kind: 'buddy', name: $('#bNameIn').value.trim() || bd.res.t.name, buddy: {prompt: bd.prompt, seed: bd.seed || 0, sheet: bd.sheet}};
  if (b.dataset.use === 'hero') s.cast.hero = {...s.cast.hero, ...m};
  else if (b.dataset.use === 'boss') { s.cast.boss = {hp: 3, speed: 1, ...(s.cast.boss || {}), ...m}; const last = s.levels[s.levels.length - 1]; if (!last.boss) { last.boss = true; last.map = null; } }
  else { if (s.cast.enemies.length >= 3) s.cast.enemies.pop(); s.cast.enemies.push({behavior: 'patrol', speed: 1, ...m}); s.levels.forEach(l => { l.map = null; }); }
  changed(); renderCast(); renderLevels(); $('#bMsg').textContent = `✓ ${m.name} is now the ${b.dataset.use}.`;
});
function renderBuddyGrid() {
  $('#bCount').textContent = GS.buddies.length ? GS.buddies.length + ' saved' : '';
  $('#bGrid').innerHTML = GS.buddies.map(b => `<div class="bitem" data-id="${esc(b.id)}" title="${esc(b.prompt)}"><img alt="" src="${b.thumb || ''}"><span>${esc(b.name)}</span><button type="button" class="scdel" data-del="${esc(b.id)}">✕</button></div>`).join('') || '<div class="hint">Nothing saved yet.</div>';
  $('#bGrid').querySelectorAll('.bitem').forEach(el => el.onclick = async e => {
    const id = el.dataset.id, b = GS.buddies.find(x => x.id === id);
    if (e.target.dataset.del) { const fd = new FormData(); fd.append('id', id); await api('buddy_delete', fd).catch(() => {}); GS.buddies = GS.buddies.filter(x => x.id !== id); renderBuddyGrid(); return; }
    if (!b) return; $('#bPrompt').value = b.prompt;
    try { showBuddy(b.sheet ? VOX.fromSheet(b.sheet, b.prompt) : VOX.build(b.prompt, b.seed), b.prompt); } catch (err) { buildBuddy(b.prompt, b.seed); }
    $('#bNameIn').value = b.name;
  });
}

/* ── step 3: stages + the tile painter ── */
const TILE_COLORS = {'#': '#6b4f3a', '=': '#8bc34a', 'o': '#ffd23f', '*': '#7bf5c4', '^': '#cfd6e6', 'j': '#ff6b7a', 'k': '#ffe9a6', 'd': '#7a4b2a', 'e': '#ac88e9', 'f': '#59c3ff', 'g': '#ffb347', 'B': '#e63946', 'S': '#ffffff', 'G': '#39ff88'};
function ensureMap(l) {
  const s = GS.spec;
  if (!l.map) l.map = TG.generate(s.mold, {seed: l.seed, length: l.length, difficulty: l.difficulty, enemies: s.cast.enemies.length || 1, boss: !!(l.boss && s.cast.boss), key: !!l.key});
  return l.map;
}
function renderLevels() {
  const s = GS.spec; GS.sel = Math.min(GS.sel, s.levels.length - 1); const l = s.levels[GS.sel];
  $('#lvSel').innerHTML = s.levels.map((x, i) => `<option value="${i}"${i === GS.sel ? ' selected' : ''}>${i + 1}. ${esc(x.name)}</option>`).join('');
  $('#lvDel').disabled = s.levels.length < 2; $('#lvAdd').disabled = s.levels.length >= 8;
  $('#lvName').value = l.name;
  $('#lvBg').innerHTML = BG_ROW.filter(([v]) => v !== 'image').map(([v, label]) => `<button type="button" data-v="${v}" class="${l.bg.kind === 'builtin' && l.bg.value === v ? 'on' : ''}">${esc(label)}</button>`).join('') + `<input type="color" id="lvBgColor" value="${l.bg.color}" title="Solid / studio color">`;
  $('#lvBg').querySelectorAll('button').forEach(b => b.onclick = () => { l.bg = {kind: 'builtin', value: b.dataset.v, color: l.bg.color}; changed(); renderLevels(); });
  $('#lvBgColor').oninput = e => { l.bg.color = e.target.value; changed(); };
  $('#lvScenes').innerHTML = GS.scenes.map(m => `<div class="scthumb${l.bg.kind === 'scene' && l.bg.value === m.file ? ' on' : ''}" data-f="${esc(m.file)}" title="${esc(m.title)}"><img loading="lazy" src="scenes/${encodeURIComponent(m.file)}" alt=""></div>`).join('') || '<div class="hint">Scene pictures made in Toon Lab (Background → 🖼 Picture) show up here as backdrops.</div>';
  $('#lvScenes').querySelectorAll('.scthumb').forEach(t => t.onclick = () => { l.bg = {kind: 'scene', value: t.dataset.f, color: l.bg.color}; changed(); renderLevels(); });
  for (const [id, k] of [['lvLen', 'length'], ['lvDiff', 'difficulty']]) $('#' + id).querySelectorAll('button').forEach(b => { b.classList.toggle('on', String(l[k]) === b.dataset.v); b.onclick = () => { l[k] = k === 'difficulty' ? +b.dataset.v : b.dataset.v; l.map = null; changed(); renderLevels(); }; });
  $('#lvKey').checked = !!l.key; $('#lvBoss').checked = !!l.boss; $('#lvBoss').disabled = !s.cast.boss;
  $('#lvSeed').textContent = 'seed ' + l.seed.toString(36);
  const tools = ['.', ...TG.MOLDS[s.mold].tiles]; if (!tools.includes(GS.tool)) GS.tool = '#';
  $('#lvTools').innerHTML = tools.map(t => `<button type="button" data-v="${t}" class="${GS.tool === t ? 'on' : ''}" title="${esc(TG.TILES[t])}"><i style="background:${TILE_COLORS[t] || 'transparent'}"></i>${t === '.' ? 'Erase' : esc(TG.TILES[t])}</button>`).join('');
  $('#lvTools').querySelectorAll('button').forEach(b => b.onclick = () => { GS.tool = b.dataset.v; $('#lvTools').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); });
  $('#lvMusic').innerHTML = '<option value="">Same as the game</option>' + GS.music.map(t => `<option value="${esc(t.file)}"${l.music === t.file ? ' selected' : ''}>🎵 ${esc(t.title)}</option>`).join('');
  $('#pHint').textContent = s.mold === 'shooter' ? 'Rows arrive from the top in order: paint waves of enemies, asteroids (#), mines (^) and coins. Right-click erases.' : 'Click or drag to paint, right-click to erase. S is where the hero starts, G is the goal.';
  drawPainter();
}
$('#lvSel').onchange = () => { GS.sel = +$('#lvSel').value; renderLevels(); };
$('#lvAdd').onclick = () => { const s = GS.spec; if (s.levels.length >= 8) return; s.levels.push(newLevel(s.levels.length + 1, s.mold)); GS.sel = s.levels.length - 1; changed(); renderLevels(); };
$('#lvDel').onclick = () => { const s = GS.spec; if (s.levels.length < 2) return; s.levels.splice(GS.sel, 1); GS.sel = Math.max(0, GS.sel - 1); changed(); renderLevels(); };
$('#lvName').oninput = () => { GS.spec.levels[GS.sel].name = $('#lvName').value; changed(); $('#lvSel').options[GS.sel].textContent = (GS.sel + 1) + '. ' + $('#lvName').value; };
$('#lvKey').onchange = () => { const l = GS.spec.levels[GS.sel]; l.key = $('#lvKey').checked; l.map = null; changed(); renderLevels(); };
$('#lvBoss').onchange = () => { const l = GS.spec.levels[GS.sel]; l.boss = $('#lvBoss').checked; l.map = null; changed(); renderLevels(); };
$('#lvGen').onclick = () => { const l = GS.spec.levels[GS.sel]; l.seed = Math.floor(Math.random() * 1e9); l.map = null; changed(); renderLevels(); };
$('#lvRegen').onclick = () => { const l = GS.spec.levels[GS.sel]; l.map = null; changed(); renderLevels(); };
$('#lvMusic').onchange = () => { GS.spec.levels[GS.sel].music = $('#lvMusic').value; changed(); };
const PX = 10;
function drawPainter() {
  const l = GS.spec.levels[GS.sel], map = ensureMap(l), cv = $('#pCanvas'), rows = map.length, cols = map[0].length, theme = themeFor(l);
  cv.width = cols * PX; cv.height = rows * PX; cv.style.width = cols * PX + 'px'; cv.style.height = rows * PX + 'px';
  const g = cv.getContext('2d'); g.fillStyle = theme.sky; g.fillRect(0, 0, cv.width, cv.height);
  g.font = 'bold 8px ui-monospace, monospace'; g.textAlign = 'center'; g.textBaseline = 'middle';
  for (let y = 0; y < rows; y++) for (let x = 0; x < cols; x++) {
    const ch = map[y][x]; if (ch === '.') continue;
    g.fillStyle = ch === '#' ? theme.ground : ch === '=' ? theme.top : TILE_COLORS[ch] || '#fff'; g.fillRect(x * PX, y * PX + (ch === '=' ? 3 : 0), PX, ch === '=' ? 3 : PX);
    if ('efgBSGkd'.includes(ch)) { g.fillStyle = ch === 'S' || ch === 'k' ? '#000' : '#fff'; g.fillText(ch, x * PX + PX / 2, y * PX + PX / 2 + .5); }
  }
  g.strokeStyle = 'rgba(255,255,255,.06)'; for (let x = 0; x <= cols; x += 5) { g.beginPath(); g.moveTo(x * PX, 0); g.lineTo(x * PX, rows * PX); g.stroke(); }
  if (GS.spec.mold === 'platformer') $('#painter').classList.add('wide'); else $('#painter').classList.remove('wide');
}
{
  const cv = $('#pCanvas'); let painting = false, erase = false;
  const paint = e => {
    const l = GS.spec.levels[GS.sel], map = ensureMap(l), r = cv.getBoundingClientRect(), x = Math.floor((e.clientX - r.left) / PX), y = Math.floor((e.clientY - r.top) / PX);
    if (y < 0 || y >= map.length || x < 0 || x >= map[0].length) return;
    const ch = erase ? '.' : GS.tool; if (map[y][x] === ch) return;
    if (ch === 'S' || ch === 'G' || ch === 'B') map.forEach((row, yy) => { if (row.includes(ch)) map[yy] = row.replace(ch, '.'); });   // only one of these per stage
    map[y] = map[y].slice(0, x) + ch + map[y].slice(x + 1); l.custom = true; changed(); drawPainter();
  };
  cv.addEventListener('contextmenu', e => e.preventDefault());
  cv.addEventListener('pointerdown', e => { painting = true; erase = e.button === 2; cv.setPointerCapture(e.pointerId); paint(e); });
  cv.addEventListener('pointermove', e => { if (painting) paint(e); });
  cv.addEventListener('pointerup', () => { painting = false; }); cv.addEventListener('pointercancel', () => { painting = false; });
}

/* ── step 4: rules ── */
const RFMT = {rLives: v => v, rTime: v => +v ? v + ' s' : 'none', rGravity: v => (+v).toFixed(2) + '×', rJump: v => (+v).toFixed(2) + '×', rSpeed: v => (+v).toFixed(2) + '×', aMusicVol: v => Math.round(v * 100) + '%', aCompSecs: v => v + ' s'};
function renderRules() {
  const r = GS.spec.rules;
  for (const [id, k] of [['rLives', 'lives'], ['rTime', 'timeLimit'], ['rGravity', 'gravity'], ['rJump', 'jump'], ['rSpeed', 'speed']]) { const i = $('#' + id); i.value = r[k]; i.nextElementSibling.textContent = RFMT[id](i.value); i.oninput = () => { r[k] = +i.value; i.nextElementSibling.textContent = RFMT[id](i.value); changed(); }; }
  for (const [id, k] of [['rDiff', 'difficulty'], ['rWin', 'win']]) $('#' + id).querySelectorAll('button').forEach(b => { b.classList.toggle('on', r[k] === b.dataset.v); b.onclick = () => { r[k] = b.dataset.v; changed(); renderRules(); }; });
  $('#rWinText').value = GS.spec.winText || ''; $('#rLoseText').value = GS.spec.loseText || '';
}
$('#rWinText').oninput = () => { GS.spec.winText = $('#rWinText').value; changed(); };
$('#rLoseText').oninput = () => { GS.spec.loseText = $('#rLoseText').value; changed(); };

/* ── step 5: audio ── */
let actx = null;
function playSfx(p) { try { actx = actx || new AudioContext(); actx.resume(); const d = TG.SFX.render(p), b = actx.createBuffer(1, d.length, 44100); b.getChannelData(0).set(d); const s = actx.createBufferSource(); s.buffer = b; s.connect(actx.destination); s.start(); } catch (e) {} }
const SFX_LABEL = {jump: 'Jump', coin: 'Coin', gem: 'Gem', stomp: 'Stomp', hurt: 'Hurt', shoot: 'Shoot', hit: 'Hit', spring: 'Spring', key: 'Key', door: 'Door', win: 'Stage clear', lose: 'Game over', select: 'Start', boss: 'Boss roar', explode: 'Explosion', bomb: 'Bomb'};
function renderAudio() {
  const a = GS.spec.audio, set = TG.SFX.set(a.sfxStyle, a.sfx);
  $('#aStyle').innerHTML = TG.SFX.styles.map(s => `<button type="button" data-v="${s}" class="${a.sfxStyle === s ? 'on' : ''}">${s}</button>`).join('');
  $('#aStyle').querySelectorAll('button').forEach(b => b.onclick = () => { a.sfxStyle = b.dataset.v; changed(); renderAudio(); });
  $('#aSfx').innerHTML = Object.keys(set).map(k => `<div class="sfxrow"><span>${SFX_LABEL[k] || k}</span><button type="button" data-play="${k}" title="Play">▶</button><button type="button" data-vary="${k}" title="A different take">🎲</button><button type="button" data-reset="${k}" title="Back to the style's default"${a.sfx[k] ? '' : ' disabled'}>↺</button></div>`).join('');
  $('#aSfx').querySelectorAll('[data-play]').forEach(b => b.onclick = () => playSfx(set[b.dataset.play]));
  $('#aSfx').querySelectorAll('[data-vary]').forEach(b => b.onclick = () => { const k = b.dataset.vary; a.sfx[k] = TG.SFX.vary(set[k], Math.floor(Math.random() * 1e9)); changed(); renderAudio(); playSfx(a.sfx[k]); });
  $('#aSfx').querySelectorAll('[data-reset]').forEach(b => b.onclick = () => { delete a.sfx[b.dataset.reset]; changed(); renderAudio(); });
  $('#aMusic').innerHTML = '<option value="">No music</option>' + GS.music.map(t => `<option value="${esc(t.file)}"${a.music.file === t.file ? ' selected' : ''}>🎵 ${esc(t.title)}</option>`).join('');
  const v = $('#aMusicVol'); v.value = a.music.volume; v.nextElementSibling.textContent = RFMT.aMusicVol(v.value);
  $('#aVoice').innerHTML = GS.voices.map(x => `<option value="${esc(x.file)}"${a.voice.saved_voice === x.file ? ' selected' : ''}>🎙 My voice: ${esc(x.name)}</option>`).join('') + `<option value=""${a.voice.saved_voice ? '' : ' selected'}>🤖 AI reader only (faster)</option>`;
  $('#aReader').value = a.voice.tts_voice;
  $('#aLines').innerHTML = [['start', 'At the start'], ['win', 'On a win'], ['lose', 'On game over'], ['hurt', 'When hurt']].map(([k, label]) => { const f = a.voice.files[k]; return `<div class="row"><label class="shrink lbl">${label}</label><input type="text" data-line="${k}" value="${esc(a.voice.lines[k])}" placeholder="${k === 'hurt' ? 'Ouch!' : k === 'start' ? "Let's go!" : ''}" maxlength="120">${f ? `<button type="button" class="shrink" data-vplay="${k}" title="Play">▶</button>` : ''}</div>`; }).join('');
  $('#aLines').querySelectorAll('[data-line]').forEach(i => i.oninput = () => { a.voice.lines[i.dataset.line] = i.value; delete a.voice.files[i.dataset.line]; changed(); });
  $('#aLines').querySelectorAll('[data-vplay]').forEach(b => b.onclick = () => { const f = a.voice.files[b.dataset.vplay]; new Audio(`?a=file&id=${f.id}&f=${encodeURIComponent(f.f)}`).play(); });
  const sc = $('#aCompSecs'); sc.nextElementSibling.textContent = RFMT.aCompSecs(sc.value); sc.oninput = () => { sc.nextElementSibling.textContent = RFMT.aCompSecs(sc.value); };
}
$('#aMusic').onchange = () => { GS.spec.audio.music.file = $('#aMusic').value; changed(); };
$('#aMusicVol').oninput = () => { GS.spec.audio.music.volume = +$('#aMusicVol').value; $('#aMusicVol').nextElementSibling.textContent = RFMT.aMusicVol($('#aMusicVol').value); changed(); };
let prev = null;
$('#aMusicPlay').onclick = () => { if (prev) { prev.pause(); prev = null; $('#aMusicPlay').textContent = '▶'; return; } const f = $('#aMusic').value; if (!f) return; prev = new Audio('music/' + encodeURIComponent(f)); prev.volume = +$('#aMusicVol').value; prev.play(); prev.onended = () => { prev = null; $('#aMusicPlay').textContent = '▶'; }; $('#aMusicPlay').textContent = '■'; };
$('#aVoice').onchange = () => { GS.spec.audio.voice.saved_voice = $('#aVoice').value; GS.spec.audio.voice.files = {}; changed(); renderAudio(); };
$('#aReader').onchange = () => { GS.spec.audio.voice.tts_voice = $('#aReader').value; GS.spec.audio.voice.files = {}; changed(); };
$('#aCompGo').onclick = async () => {
  const prompt = $('#aCompPrompt').value.trim(); if (!prompt) { $('#aCompPrompt').focus(); return; }
  $('#aCompGo').disabled = true; $('#aCompProg').hidden = false; $('#aCompMsg').textContent = 'Starting…';
  try {
    const fd = new FormData(); fd.append('prompt', prompt); fd.append('seconds', $('#aCompSecs').value);
    const {id, file} = await api('music_gen', fd);
    for (;;) { await sleep(1000); const st = await api('music_status&id=' + id); $('#aCompMsg').textContent = st.stage || ''; $('#aCompProg i').style.width = Math.round((st.progress || 0) * 100) + '%'; if (st.state === 'error') throw new Error(st.error || 'MusicGen failed.'); if (st.state === 'done') break; }
    GS.music = (await api('music')).tracks || []; GS.spec.audio.music.file = file; changed(); renderAudio(); renderLevels(); $('#aCompMsg').textContent = '✓ Composed and selected.';
  } catch (e) { $('#aCompMsg').textContent = e.message; }
  $('#aCompGo').disabled = false; $('#aCompProg').hidden = true;
};
$('#aSpeak').onclick = async () => {
  const v = GS.spec.audio.voice, todo = Object.entries(v.lines).filter(([k, t]) => t.trim() && !v.files[k]);
  if (!todo.length) { $('#aSpeakMsg').textContent = 'Type a line or two first.'; return; }
  $('#aSpeak').disabled = true;
  try {
    for (const [k, text] of todo) {
      $('#aSpeakMsg').textContent = `🗣 "${text.slice(0, 30)}"…`;
      const fd = new FormData(); fd.append('text', text); fd.append('tts_voice', v.tts_voice); fd.append('tts_speed', '1'); fd.append('mode', 'speech'); fd.append('steps', '20');
      if (v.saved_voice) { fd.append('saved_voice', v.saved_voice); fd.append('clean_voice', '1'); } else fd.append('ai_voice', '1');
      const {ids: [id]} = await api('create', fd);
      for (;;) { await sleep(1200); const j = (await api('jobs')).find(x => x.id === id); if (!j) throw new Error('The speech job disappeared.'); $('#aSpeakMsg').textContent = `🗣 ${k}: ${j.status.stage || ''}`; if (j.status.state === 'done') { v.files[k] = {id, f: j.status.files.vocals || j.status.files.mix}; changed(); break; } if (['error', 'cancelled'].includes(j.status.state)) throw new Error(j.status.error || 'Speech failed'); }
    }
    $('#aSpeakMsg').textContent = '✓ Voice lines ready.'; renderAudio();
  } catch (e) { $('#aSpeakMsg').textContent = e.message; }
  $('#aSpeak').disabled = false;
};

/* ── step 6: build the cartridge, play it, save it ── */
const toDataURL = async url => { const r = await fetch(url); if (!r.ok) throw new Error('Missing: ' + url); const b = await r.blob(); return new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(b); }); };
async function buildConfig() {
  const s = GS.spec, sheets = {hero: await castSheet(s.cast.hero)};
  for (const [i, e] of s.cast.enemies.entries()) sheets['e' + i] = await castSheet(e);
  if (s.cast.boss) sheets.boss = await castSheet(s.cast.boss);
  const backdrops = []; for (const l of s.levels) backdrops.push(await renderBackdrop(l));
  const music = s.audio.music.file ? await toDataURL('music/' + encodeURIComponent(s.audio.music.file)).catch(() => null) : null;
  const levelMusic = []; for (const l of s.levels) levelMusic.push(l.music && l.music !== s.audio.music.file ? await toDataURL('music/' + encodeURIComponent(l.music)).catch(() => null) : null);
  const voices = {}; for (const [k, f] of Object.entries(s.audio.voice.files)) if (f && s.audio.voice.lines[k]) voices[k] = await toDataURL(`?a=file&id=${f.id}&f=${encodeURIComponent(f.f)}`).catch(() => null);
  const spec = {title: s.title || 'Toon Game', pitch: s.pitch, mold: s.mold, winText: s.winText, loseText: s.loseText, rules: s.rules,
    cast: {hero: {name: s.cast.hero.name, speed: s.cast.hero.speed, jump: s.cast.hero.jump}, enemies: s.cast.enemies.map(e => ({name: e.name, behavior: e.behavior, speed: e.speed})), boss: s.cast.boss ? {name: s.cast.boss.name, hp: s.cast.boss.hp, speed: s.cast.boss.speed} : null},
    levels: s.levels.map(l => ({name: l.name, map: ensureMap(l), theme: themeFor(l)}))};
  return {id: GS.gameId || 'draft', spec, sheets, backdrops, music, levelMusic, musicVolume: s.audio.music.volume, voices, sfxStyle: s.audio.sfxStyle, sfx: s.audio.sfx};
}
const cartridge = cfg => GAME_HOST.replace('__TITLE__', () => esc(cfg.spec.title)).replace('__RUNTIME__', () => GAME_RUNTIME).replace('__CONFIG__', () => JSON.stringify(cfg).replace(/</g, '\\u003c'));
async function makeThumb(cfg) {
  const c = document.createElement('canvas'); c.width = 480; c.height = 270; const g = c.getContext('2d'), load = src => new Promise(r => { const im = new Image(); im.onload = () => r(im); im.onerror = () => r(null); im.src = src; });
  const bg = await load(cfg.backdrops[0]); if (bg) g.drawImage(bg, 0, 0, 480, 270); else { g.fillStyle = '#1b2440'; g.fillRect(0, 0, 480, 270); }
  g.fillStyle = 'rgba(6,8,16,.55)'; g.fillRect(0, 0, 480, 270);
  const hero = await load(cfg.sheets.hero); if (hero) g.drawImage(hero, 6 * TG.FW, 0, TG.FW, TG.FH, 40, 60, 120, 180);
  g.fillStyle = cfg.spec.levels[0].theme.accent; g.font = 'bold 30px ui-monospace, monospace'; g.textAlign = 'center'; g.fillText(cfg.spec.title.slice(0, 20).toUpperCase(), 300, 120);
  g.fillStyle = '#cfd6e6'; g.font = '14px ui-monospace, monospace'; g.fillText(TG.MOLDS[cfg.spec.mold].name + ' · ' + cfg.spec.levels.length + (cfg.spec.levels.length > 1 ? ' stages' : ' stage'), 300, 150);
  return c.toDataURL('image/jpeg', .8);
}
let lastHTML = '', building = false;
async function play() {
  if (building) return; building = true; $('#gPlay').disabled = true; msg('Rendering the sprites and backdrops…');
  try {
    const cfg = await buildConfig(); lastHTML = cartridge(cfg);
    $('#gEmpty').hidden = true; $('#gameFrame').srcdoc = lastHTML; $('#gameFrame').focus();
    msg(`✓ Ready to play (${(lastHTML.length / 1048576).toFixed(1)} MB). Click the game, then Enter. 💾 Save keeps it in the gallery.`);
    $('#gPlayMsg').textContent = TG.MOLDS[GS.spec.mold].controls;
  } catch (e) { console.error(e); msg(e.message, true); }
  building = false; $('#gPlay').disabled = false;
}
$('#gPlay').onclick = play;
$('#gSave').onclick = async () => {
  $('#gSave').disabled = true; msg('Building the game file…');
  try {
    const cfg = await buildConfig(); const html = cartridge(cfg), thumb = await makeThumb(cfg);
    const fd = new FormData(); fd.append('id', GS.gameId); fd.append('spec', JSON.stringify(GS.spec)); fd.append('html', html); fd.append('thumb', thumb);
    const r = await api('game_save', fd); GS.gameId = r.id; lastHTML = html;
    msg(`✓ Saved "${cfg.spec.title}" to the gallery (${(html.length / 1048576).toFixed(1)} MB).`); await loadGallery(r.id);
  } catch (e) { msg(e.message, true); }
  $('#gSave').disabled = false;
};
$('#gDownload').onclick = async () => {
  try { if (!lastHTML) lastHTML = cartridge(await buildConfig()); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([lastHTML], {type: 'text/html'})); a.download = (GS.spec.title || 'toon-game').replace(/[^\w.-]+/g, '-') + '.html'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 3000); msg('✓ Downloaded. Open the file in any browser to play.'); } catch (e) { msg(e.message, true); }
};
$('#gPop').onclick = async () => { try { if (!lastHTML) lastHTML = cartridge(await buildConfig()); window.open(URL.createObjectURL(new Blob([lastHTML], {type: 'text/html'})), '_blank'); } catch (e) { msg(e.message, true); } };
$('#gFull').onclick = () => { const f = $('#gameFrame'); (f.requestFullscreen || f.webkitRequestFullscreen || (() => {})).call(f); };
$('#gNew').onclick = () => { GS.spec = defaultSpec(); GS.gameId = ''; GS.sel = 0; lastHTML = ''; changed(); renderAll(); msg('A fresh game. Name it, pick a cast, play.'); };

/* ── gallery ── */
async function loadGallery(flash) {
  const list = await api('games').catch(() => []);
  $('#galCount').textContent = list.length ? list.length + (list.length === 1 ? ' game' : ' games') : '';
  $('#gGallery').innerHTML = list.length ? list.map(g => `<div class="gcard${g.id === flash ? ' flash' : ''}" data-id="${g.id}"><div class="gthumb" style="background-image:url('games/${g.id}/thumb.jpg')"><span>${esc(TG.MOLDS[g.mold] ? TG.MOLDS[g.mold].name : g.mold)}</span></div>
      <div class="gt">${esc(g.title)}</div><div class="meta">${g.levels} stage${g.levels === 1 ? '' : 's'}${g.hero ? ' · ' + esc(g.hero) : ''} · ${new Date(g.time * 1000).toLocaleDateString([], {month: 'short', day: 'numeric'})}</div>
      <div class="gacts"><button type="button" data-play="${g.id}">▶ Play</button><button type="button" data-edit="${g.id}">✎ Remix</button><a href="games/${g.id}/index.html?dl=${encodeURIComponent(g.title)}">⬇</a><button type="button" data-reveal="${g.id}" title="Show in Finder">📂</button><button type="button" data-del="${g.id}" title="Delete">🗑</button></div></div>`).join('')
    : '<div class="empty">No games yet. Build one on the left and 💾 Save it.</div>';
  $('#gGallery').querySelectorAll('[data-play]').forEach(b => b.onclick = () => { $('#gEmpty').hidden = true; $('#gameFrame').removeAttribute('srcdoc'); $('#gameFrame').src = `games/${b.dataset.play}/index.html`; $('#gameFrame').focus(); });
  $('#gGallery').querySelectorAll('[data-edit]').forEach(b => b.onclick = async () => { try { GS.spec = normSpec(await api('game_get&id=' + b.dataset.edit)); GS.gameId = b.dataset.edit; GS.sel = 0; lastHTML = ''; changed(); renderAll(); msg(`Editing "${GS.spec.title}". 💾 Save updates it; ✨ New starts over.`); window.scrollTo({top: 0, behavior: 'smooth'}); } catch (e) { msg(e.message, true); } });
  $('#gGallery').querySelectorAll('[data-reveal]').forEach(b => b.onclick = () => api('game_reveal&id=' + b.dataset.reveal).catch(() => {}));
  $('#gGallery').querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (b.dataset.armed !== '1') { b.dataset.armed = '1'; b.textContent = 'Sure?'; setTimeout(() => { b.dataset.armed = ''; b.textContent = '🗑'; }, 3000); return; } const fd = new FormData(); fd.append('id', b.dataset.del); await api('game_delete', fd).catch(() => {}); if (GS.gameId === b.dataset.del) GS.gameId = ''; loadGallery(); });
}

/* ── ✨ Director: one sentence → a whole game plan from the local model ── */
async function loadModels() {
  const sel = $('#gModel'); sel.innerHTML = '<option value="">loading models…</option>';
  try { const r = await api('llm_models'); sel.innerHTML = r.models.length ? r.models.map(m => `<option value="${esc(m.id)}">🧠 ${esc(m.id)}${m.size ? ' · ' + esc(m.size) : ''}</option>`).join('') : '<option value="">no text models yet — run: ollama pull qwen3.5:2b</option>'; if (r.models.some(m => m.id === r.llm_model)) sel.value = r.llm_model; }
  catch (e) { sel.innerHTML = '<option value="">⚠ model server not reachable</option>'; }
}
$('#gModelRefresh').onclick = loadModels;
$('#gModel').onchange = () => { const fd = new FormData(); fd.append('llm_model', $('#gModel').value); api('settings', fd).catch(() => {}); };
$('#gDirBtn').onclick = async () => {
  const prompt = $('#gDirPrompt').value.trim(); if (!prompt) { $('#gDirPrompt').focus(); return; }
  const btn = $('#gDirBtn'); btn.disabled = true; $('#gDirErr').hidden = true; $('#gDirInfo').textContent = `${$('#gModel').value || 'Your local model'} is designing the game… (big models take a minute the first time)`;
  try {
    const fd = new FormData(); fd.append('prompt', prompt); fd.append('model', $('#gModel').value);
    const {plan} = await api('game_director', fd); await voxReady();
    const s = GS.spec, bgs = BG_ROW.map(b => b[0]).filter(b => b !== 'image');
    const firstOf = (v, list) => String(v || '').toLowerCase().split(/[^a-z]+/).find(x => list.includes(x));   // small models answer "topdown|shooter"
    plan.mold = firstOf(plan.mold, Object.keys(TG.MOLDS)); plan.sfx = firstOf(plan.sfx, TG.SFX.styles);
    if (plan.rules) { plan.rules.difficulty = firstOf(plan.rules.difficulty, ['chill', 'normal', 'hard', 'brutal']); plan.rules.win = firstOf(plan.rules.win, ['goal', 'coins', 'boss', 'survive']); }
    if (!plan.voice && plan.rules && plan.rules.voice) plan.voice = plan.rules.voice;
    if (Array.isArray(plan.enemies)) plan.enemies.forEach(e => { e.behavior = firstOf(e.behavior, TG.BEHAVIORS); });
    if (Array.isArray(plan.levels)) plan.levels.forEach(l => { l.length = firstOf(l.length, ['short', 'medium', 'long']); l.bg = firstOf(l.bg, bgs); });
    if (plan.boss && !plan.boss.name && !plan.boss.buddy) plan.boss = null;
    s.title = String(plan.title || s.title || 'Toon Game').slice(0, 40); s.pitch = String(plan.pitch || '').slice(0, 160); if (TG.MOLDS[plan.mold]) s.mold = plan.mold;
    const h = plan.hero || {}; if (h.toon && PRESETS[h.toon]) s.cast.hero = {kind: 'toon', name: h.name || h.toon, char: normChar({...DEFAULT_CHAR, ...PRESETS[h.toon], prop: 'none'}), speed: 1, jump: 1};
    else if (h.buddy) s.cast.hero = {kind: 'buddy', name: h.name || 'Hero', buddy: {prompt: h.buddy, seed: TG.hashStr(h.buddy)}, speed: 1, jump: 1}; else if (h.name) s.cast.hero.name = h.name;
    if (Array.isArray(plan.enemies) && plan.enemies.length) s.cast.enemies = plan.enemies.slice(0, 3).map(e => ({kind: 'buddy', name: String(e.name || 'Enemy').slice(0, 30), buddy: {prompt: String(e.buddy || e.name || 'angry slime'), seed: TG.hashStr(String(e.buddy || e.name))}, behavior: TG.BEHAVIORS.includes(e.behavior) ? e.behavior : 'patrol', speed: 1}));
    s.cast.boss = plan.boss && (plan.boss.buddy || plan.boss.name) ? {kind: 'buddy', name: String(plan.boss.name || 'Boss').slice(0, 30), buddy: {prompt: 'giant ' + String(plan.boss.buddy || plan.boss.name) + ' boss', seed: TG.hashStr(String(plan.boss.buddy || plan.boss.name))}, hp: 3, speed: 1} : null;
    if (Array.isArray(plan.levels) && plan.levels.length) s.levels = plan.levels.slice(0, 4).map((l, i) => { const nl = newLevel(i + 1, s.mold); nl.name = String(l.name || nl.name).slice(0, 40); if (bgs.includes(l.bg)) nl.bg.value = l.bg; if (['short', 'medium', 'long'].includes(l.length)) nl.length = l.length; nl.difficulty = Math.max(1, Math.min(3, +l.difficulty || i + 1)); nl.key = !!l.key && s.mold !== 'shooter'; nl.boss = !!l.boss && !!s.cast.boss; return nl; });
    if (s.cast.boss && !s.levels.some(l => l.boss)) s.levels[s.levels.length - 1].boss = true;
    const r = plan.rules || {}; if (r.lives) s.rules.lives = Math.max(1, Math.min(9, +r.lives || 3)); if (['chill', 'normal', 'hard', 'brutal'].includes(r.difficulty)) s.rules.difficulty = r.difficulty; if (['goal', 'coins', 'boss', 'survive'].includes(r.win)) s.rules.win = r.win === 'boss' && !s.cast.boss ? 'goal' : r.win;
    const v = plan.voice || {}; for (const k of ['start', 'win', 'lose', 'hurt']) if (typeof v[k] === 'string') { s.audio.voice.lines[k] = v[k].slice(0, 120); delete s.audio.voice.files[k]; }
    if (TG.SFX.styles.includes(plan.sfx)) s.audio.sfxStyle = plan.sfx;
    if (plan.music && typeof plan.music === 'string') $('#aCompPrompt').value = plan.music;
    GS.sel = 0; changed(); renderAll();
    $('#gDirInfo').textContent = `✓ "${s.title}" — ${TG.MOLDS[s.mold].name}, ${s.levels.length} stage${s.levels.length > 1 ? 's' : ''}, ${s.cast.enemies.length} enem${s.cast.enemies.length === 1 ? 'y' : 'ies'}${s.cast.boss ? ' and a boss' : ''}. ▶ Play it, or tweak anything first.` + (plan.music ? ' A music idea is waiting under Audio → 🎼 Compose.' : '');
  } catch (e) { $('#gDirErr').textContent = e.message; $('#gDirErr').hidden = false; $('#gDirInfo').textContent = ''; }
  btn.disabled = false;
};

/* ── boot ── */
function renderAll() { renderGame(); renderCast(); renderLevels(); renderRules(); renderAudio(); }
(async () => {
  [GS.scenes, GS.music, GS.voices] = await Promise.all([api('scenes').catch(() => []), api('music').then(r => r.tracks || []).catch(() => []), api('voices').catch(() => [])]);
  renderAll(); loadGallery(); loadModels(); initBuddies();
})();
</script>
<?php endif; ?>
</body>
</html>
