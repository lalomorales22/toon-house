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
 * Everything lives next to this file: engine/ (venv, Seed-VC, models), jobs/, voices/.
 */

const PORT = 8777;
const SEEDVC_REPO = 'https://github.com/Plachtaa/seed-vc.git';
const SEEDVC_COMMIT = '51383efd921027683c89e5348211d93ff12ac2a8';

define('BASE', __DIR__);
define('ENGINE', BASE . '/engine');
define('JOBS', BASE . '/jobs');
define('VOICES', BASE . '/voices');
define('SYS_PATH', '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin');

// ───────────────────────────── CLI launcher ─────────────────────────────
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'setup') {
        $py = find_python();
        if (!$py) { fwrite(STDERR, "Need Python 3.10–3.12 (brew install python@3.11)\n"); exit(1); }
        write_engine_files();
        passthru('env PATH=' . escapeshellarg(SYS_PATH) . ' bash ' . escapeshellarg(ENGINE . '/install.sh') . ' ' . escapeshellarg($py), $rc);
        exit($rc);
    }
    $url = 'http://127.0.0.1:' . PORT;
    echo "\n  VOICE SWAP running at $url  (Ctrl+C to stop)\n\n";
    if (PHP_OS_FAMILY === 'Darwin') exec('(sleep 1; open ' . escapeshellarg($url) . ') > /dev/null 2>&1 &');
    putenv('PHP_CLI_SERVER_WORKERS=4');
    passthru(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=1G -d post_max_size=1G -d max_execution_time=0 -d memory_limit=512M'
        . ' -S 127.0.0.1:' . PORT . ' ' . escapeshellarg(__FILE__));
    exit;
}

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
    foreach (['worker.py' => WORKER_PY, 'install.sh' => $install] as $f => $src) {
        $p = ENGINE . "/$f";
        if (!is_file($p) || md5_file($p) !== md5($src)) file_put_contents($p, $src);
    }
}

function spawn_worker(string $id): void {
    $dir = JOBS . "/$id";
    // subshell + exec: the PID we record is the python process itself, and no fd keeps this request open
    $cmd = '(cd ' . escapeshellarg(ENGINE) . ' && exec nohup env PATH=' . escapeshellarg(SYS_PATH)
        . ' ./venv/bin/python worker.py ' . escapeshellarg($dir)
        . ') < /dev/null > ' . escapeshellarg("$dir/log.txt") . ' 2>&1 & echo $!';
    file_put_contents("$dir/pid", trim((string)shell_exec($cmd)));
}

// ───────────────────────────── embedded engine files ─────────────────────────────
const INSTALL_SH = <<<'SH'
#!/bin/bash
# Installs Seed-VC + Demucs into ./venv. Usage: install.sh /path/to/python3.x
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
./venv/bin/pip install -q --upgrade pip
./venv/bin/pip install --progress-bar off torch==2.5.1 torchaudio==2.5.1 numpy==1.26.4 scipy==1.13.1 \
  librosa==0.10.2 "huggingface-hub>=0.28.1,<1.0" munch==4.0.0 einops==0.8.0 transformers==4.46.3 \
  soundfile==0.12.1 pyyaml accelerate hydra-core==1.3.2 python-dotenv descript-audio-codec==1.0.0 demucs
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

    # 1. normalise inputs
    ref = os.path.join(work, 'voice.wav')
    ffmpeg('-i', opts['voice_path'], '-ac', '1', '-ar', '44100', '-t', '90', ref)
    trimmed = os.path.join(work, 'voice_trim.wav')
    ffmpeg('-i', ref, '-af', 'silenceremove=start_periods=1:start_threshold=-45dB:stop_periods=-1:stop_duration=0.6:stop_threshold=-45dB', trimmed)
    if duration(trimmed) >= 3:
        ref = trimmed
    song = os.path.join(work, 'song.wav')
    ffmpeg('-i', opts['song_path'], '-ac', '2', '-ar', '44100', song)

    # 2. separation
    instrumental = None
    if opts['separate']:
        vocals, instrumental = demucs_split(song, os.path.join(work, 'sep'), 0.04, 0.28, 'Separating vocals from music')
    else:
        vocals = song
    if opts['clean_voice']:
        ref, _ = demucs_split(ref, os.path.join(work, 'sep_voice'), 0.28, 0.34, 'Cleaning your voice clip')
    capped = os.path.join(work, 'voice_ref.wav')
    ffmpeg('-i', ref, '-ac', '1', '-t', str(REF_MAX), capped)
    ref = capped
    log(f'RAM {RAM_GB:.0f}GB -> context window {WINDOW}s, voice reference {REF_MAX}s')
    if os.path.getsize(vocals) < 1000:
        raise RuntimeError('No vocals found in the target audio.')

    # 3. conversion
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

    # 4. mix + export
    st(stage='Mixing & exporting MP3', progress=0.93)
    name = opts['out_name']
    files = {}
    vox = os.path.join(JOB, f'{name} (my vocals).mp3')
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
    JOB = os.path.abspath(sys.argv[1])
    os.setpgrp()                                      # so "cancel" can kill demucs children too
    STATUS.update(json.load(open(os.path.join(JOB, 'status.json'))) if os.path.exists(os.path.join(JOB, 'status.json')) else {})
    try:
        run_job()
    except Exception as e:
        traceback.print_exc()
        st(state='error', stage='Failed', error=str(e)[-600:])
        sys.exit(1)
PY;

// ───────────────────────────── API ─────────────────────────────
$a = $_GET['a'] ?? '';
if ($a !== '') {
    ini_set('display_errors', '0'); // PHP warnings must never leak into JSON responses
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0)
        json_out(['error' => 'Upload too big for this PHP server (limit ' . ini_get('post_max_size') . '). Stop it and start the app with:  php index.php'], 413);
    @mkdir(JOBS, 0755, true);
    @mkdir(VOICES, 0755, true);

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
            // your voice: a saved one, or a new upload/recording
            $voice = null;
            if (!empty($_POST['saved_voice'])) {
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
            if (!$songs || !is_array($songs['name']) || !count(array_filter($songs['error'], fn($e) => $e === UPLOAD_ERR_OK)))
                json_out(['error' => 'Add at least one target song/clip. (If it is big, start the app with "php index.php" so large uploads are allowed.)'], 400);

            $mode = ($_POST['mode'] ?? 'sing') === 'speech' ? 'speech' : 'sing';
            $ids = [];
            foreach ($songs['name'] as $i => $orig) {
                if ($songs['error'][$i] !== UPLOAD_ERR_OK) continue;
                $id = date('ymd-His') . '-' . bin2hex(random_bytes(3));
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
                spawn_worker($id);
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
            // Range support: Safari won't play <audio> without it
            $size = filesize($f);
            $start = 0; $end = $size - 1;
            if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
                if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
                elseif ($m[2] !== '') $start = max(0, $size - (int)$m[2]);
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
            }
            header('Content-Type: audio/mpeg');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . ($end - $start + 1));
            if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . addslashes(basename($f)) . '"');
            $fh = fopen($f, 'rb');
            fseek($fh, $start);
            $left = $end - $start + 1;
            while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); $left -= strlen($chunk); echo $chunk; }
            exit;

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
            if (valid_id($id) && is_dir(JOBS . "/$id") && PHP_OS_FAMILY === 'Darwin') exec('open ' . escapeshellarg(JOBS . "/$id"));
            json_out(['ok' => true]);
    }
    json_out(['error' => 'unknown action'], 404);
}
?><!doctype html>
<html lang="en" data-theme="gold">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Voice Swap</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
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
.empty { color:var(--muted); text-align:center; padding:40px 10px; border:2px dashed var(--edge); }
.track-label { font-size:12px; color:var(--muted); margin-top:8px; }
</style>
</head>
<body>
<header>
  <h1>Voice <span>Swap</span></h1>
  <div class="tag">put your voice on any song · runs 100% on this Mac</div>
  <div class="themes" id="themes">
    <button data-t="classic">Classic</button><button data-t="dark">Dark</button><button data-t="gold">All Gold Everything</button><button data-t="iced">Iced Out</button>
  </div>
</header>

<main>
  <section class="panel banner" id="setup" hidden>
    <h2>⚙ Engine setup</h2>
    <div id="setupMsg"></div>
    <div class="row"><button class="shrink" id="installBtn">Install engine (~3GB download, one time)</button></div>
    <pre class="log" id="installLog" hidden></pre>
  </section>

  <form class="panel" id="form">
    <div class="sec">
      <h2><span class="step">1</span> Your voice</h2>
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
    </div>

    <div class="sec">
      <h2><span class="step">2</span> Target song / clip</h2>
      <div class="drop" id="songDrop">Drop the song(s) here or click to browse
        <small>e.g. your Suno MP3 · several files = a queue</small></div>
      <input type="file" id="songFile" multiple accept="audio/*,video/*,.mp3,.wav,.m4a,.flac,.aac,.ogg,.mp4,.mov" hidden>
      <div id="songList"></div>
    </div>

    <div class="sec">
      <h2><span class="step">3</span> Settings</h2>
      <div class="seg">
        <label><input type="radio" name="mode" value="sing" checked> 🎤 Singing (songs)</label>
        <label><input type="radio" name="mode" value="speech"> 🗣 Speech (talking)</label>
      </div>
      <label class="check"><input type="checkbox" name="separate" value="1" checked><span>Target has background music<small>Split vocals from the beat, convert vocals, mix back</small></span></label>
      <label class="check" id="octaveRow"><input type="checkbox" name="auto_octave" value="1" checked><span>Auto octave match<small>Moves the melody up/down whole octaves to fit your range (stays in key)</small></span></label>
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
    </div>

    <div class="sec">
      <button class="big" id="go" type="submit">Generate</button>
      <div class="hint" id="speedHint"></div>
      <div class="err" id="formErr" hidden></div>
    </div>
  </form>

  <section class="panel">
    <div class="queue-head"><h2>Queue / Results</h2><span class="meta" id="qcount"></span></div>
    <div class="jobs" id="jobs"><div class="empty">Nothing yet. Add your voice + a song and hit Generate.</div></div>
  </section>
</main>

<script>
const $ = s => document.querySelector(s);
const api = (a, body) => fetch('?a=' + a, body ? {method:'POST', body} : {}).then(async r => {
  const t = await r.text(), i = t.search(/^[{[]/m); // skip any PHP warning HTML printed before the JSON
  let j; try { j = JSON.parse(i < 0 ? t : t.slice(i)); } catch { throw new Error(`Server sent an unexpected reply (${r.status}). Restart the app with:  php index.php`); }
  if (!r.ok) throw new Error(j.error || r.statusText); return j; });
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

// theme
const setTheme = t => { document.documentElement.dataset.theme = t; document.querySelectorAll('#themes button').forEach(b => b.classList.toggle('on', b.dataset.t === t)); try { localStorage.vsTheme = t; } catch(e){} };
document.querySelectorAll('#themes button').forEach(b => b.onclick = () => setTheme(b.dataset.t));
try { setTheme(localStorage.vsTheme || 'gold'); } catch(e) { setTheme('gold'); }

// sliders
const fmt = {steps: v => v, semitones: v => (v > 0 ? '+' : '') + v, similarity: v => (+v).toFixed(2), vocal_vol: v => v + '%', music_vol: v => v + '%', orig_vol: v => v + '%'};
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
  if (!songs.length) return showErr('Add a target song (step 2).');
  songs.forEach(s => fd.append('songs[]', s, s.name));
  const go = $('#go'); go.disabled = true; go.textContent = 'Uploading…';
  try {
    await api('create', fd);
    songs = []; renderSongs();
    if (fd.get('save_as')) { await loadVoices(); $('#saveAs').value = ''; }
    poll();
  } catch (err) { showErr(err.message); }
  go.disabled = false; go.textContent = 'Generate';
};

// jobs
const elapsed = s => s >= 60 ? Math.floor(s/60) + 'm ' + (s%60) + 's' : s + 's';
function jobHTML(j) {
  const s = j.status, J = j.job, st = s.state, f = s.files || {};
  const link = (k, label, main) => f[k] ? `<a class="${main?'main':''}" href="?a=file&id=${j.id}&f=${encodeURIComponent(f[k])}&dl=1">⬇ ${label}</a>` : '';
  const play = f.mix || f.vocals;
  const meta = [J.voice_name && '🎙 ' + J.voice_name, J.mode === 'speech' ? 'speech' : 'singing', s.pitch_shift ? 'pitch ' + (s.pitch_shift > 0 ? '+' : '') + s.pitch_shift : '', s.seconds ? 'took ' + elapsed(s.seconds) : ''].filter(Boolean).join(' · ');
  return `<div class="job">
    <div class="top"><span class="t" title="${esc(J.title)}">${esc(J.title)}</span><span class="state ${st}">${st}</span>
      <span class="acts">${['queued','running'].includes(st) ? `<button data-act="cancel" data-id="${j.id}">Cancel</button>` : ''}
      ${st === 'done' ? `<button data-act="reveal" data-id="${j.id}">Show file</button>` : ''}
      ${!['queued','running'].includes(st) ? `<button data-act="delete" data-id="${j.id}">✕</button>` : ''}</span></div>
    <div class="meta">${esc(meta)}</div>
    ${['queued','running'].includes(st) ? `<div class="bar"><i style="width:${Math.round((s.progress||0)*100)}%"></i></div><div class="meta">${esc(s.stage)}</div>` : ''}
    ${st === 'done' && play ? `<audio controls preload="none" src="?a=file&id=${j.id}&f=${encodeURIComponent(play)}"></audio>
      <div class="dl">${link('mix','Song with my voice',true)}${link('vocals','My vocals only',!f.mix)}${link('instrumental','Instrumental')}</div>` : ''}
    ${st === 'error' ? `<div class="err">${esc(s.error)}</div><details><summary>log</summary><pre class="log">${esc(j.log)}</pre></details>` : ''}
  </div>`;
}
let lastSig = '', pollT = null;
async function poll() {
  clearTimeout(pollT);
  let list = [];
  try { list = await api('jobs'); } catch (e) {}
  const sig = JSON.stringify(list.map(j => [j.id, j.status]));
  if (sig !== lastSig) {
    lastSig = sig;
    $('#jobs').innerHTML = list.length ? list.map(jobHTML).join('') : '<div class="empty">Nothing yet. Add your voice + a song and hit Generate.</div>';
    $('#jobs').querySelectorAll('button[data-act]').forEach(b => b.onclick = async () => { const fd = new FormData(); fd.append('id', b.dataset.id); await api(b.dataset.act, fd); lastSig = ''; poll(); });
  }
  const active = list.filter(j => ['queued','running'].includes(j.status.state)).length;
  $('#qcount').textContent = active ? active + ' working' : '';
  pollT = setTimeout(poll, active ? 1500 : 8000);
}

// engine state
async function checkEngine() {
  const e = await api('engine');
  const box = $('#setup'), log = $('#installLog');
  $('#speedHint').textContent = e.ram_gb ? `This Mac: ${e.ram_gb}GB RAM. ${e.ram_gb <= 8 ? 'Expect roughly 5–10 min per minute of song; close other apps.' : 'Expect a few minutes per song.'}` : '';
  if (parseInt(e.upload_limit) < 50) showErr(`Upload limit is only ${e.upload_limit}. Start the app with:  php index.php`);
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
</body>
</html>
