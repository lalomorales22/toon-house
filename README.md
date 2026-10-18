# Voice Swap

Put **your own voice** on any song — locally, free, no training.

Give it 10–30 seconds of you talking (or singing) and a target track (e.g. a Suno MP3). It splits the vocals from the beat, re-sings the vocals in your voice, and mixes them back into a new MP3.

Everything is one file: **`index.php`**. The first time you open it, it installs its own engine next to itself.

| Piece | What it does |
|---|---|
| [Demucs](https://github.com/facebookresearch/demucs) (htdemucs) | Splits the song into vocals + instrumental |
| [Seed-VC](https://github.com/Plachtaa/seed-vc) (44 kHz singing model) | Zero-shot voice conversion — your timbre, the original melody & lyrics |
| ffmpeg | Decoding, cleanup, final mix → 320 kbps MP3 |

## Requirements

- Mac with Apple Silicon (M1/M2/M3/M4). **16 GB+ RAM recommended, 32 GB ideal.** 8 GB works for short clips with everything else closed.
  Linux works too (CPU or NVIDIA).
- ~6 GB free disk (Python packages + ~2 GB of models)
- Homebrew packages:

```bash
brew install php ffmpeg python@3.11 git
```

## Install & run

```bash
git clone https://github.com/lalomorales22/voice-swap.git
cd voice-swap
php index.php
```

That starts a local server at **http://127.0.0.1:8777** and opens your browser. Click **Install engine** (one time, ~10 min: downloads PyTorch, Seed-VC, Demucs and the models into `engine/`).

Prefer the terminal for the install? `php index.php setup`

> Always start it with `php index.php` (not `php -S …` by hand) — the launcher raises PHP's upload limit to 1 GB so full songs upload.

## Use it

1. **Your voice** — drop a clip, hit **● Record** (up to 40 s), or pick a saved voice. Type a name in *Save voice as…* to reuse it later.
2. **Target song** — drop one or more MP3/WAV/M4A/FLAC/MP4 files. Several files = a queue.
3. **Settings**
   - **Singing / Speech** — Singing for songs (keeps the melody). Speech for spoken clips.
   - **Target has background music** — on for songs, off for a dry acapella or voice clip.
   - **Auto octave match** — if the original singer is in a different range than you, it moves the melody whole octaves only, so you stay in key with the beat.
   - **Quality** — diffusion steps. 25 is a good default; 40–50 for final renders.
   - **Pitch** — manual semitone shift on top of auto.
   - *Advanced*: clean my voice clip, voice likeness, vocal/music volume, and *Original vox* (blend some of the original singer back in — helps harmonies/ad-libs).
4. **Generate.** When it's done you get:
   - **Song with my voice** (the full mix)
   - **My vocals only**
   - **Instrumental**

Files are saved in `jobs/<id>/`. *Show file* opens the folder in Finder.

## Tips for good results

- Record your clip in a quiet room, close to the mic, no music behind you. Talking naturally is fine; singing a bit is even better.
- 10–20 seconds of clean voice is better than 30 seconds of noisy voice.
- If it sounds too much like the original singer, raise **Voice likeness**. If it gets garbly, lower it or raise **Quality**.
- Heavy reverb, stacked harmonies and screaming in the source song are hard for any voice converter — use *Original vox* at 10–20% to keep them.

## Speed

Rough numbers per minute of song:

| Machine | Time |
|---|---|
| M1, 8 GB (measured, with other apps open) | ~7 min — auto-uses a 15 s window + 6 s voice reference |
| M-series, 32 GB | faster, and uses the full 30 s window + 15 s reference for better likeness |
| NVIDIA GPU (Linux) | about real time |

The worker picks the window size from your RAM automatically (≤8 GB / ≤16 GB / more).

Only one job runs at a time; the rest wait in the queue.

## How it works / files

```
index.php          ← the whole app (UI + API + embedded worker & installer)
engine/            ← created on install: venv, Seed-VC checkout, models, worker.py
jobs/              ← one folder per conversion (inputs, status, MP3 outputs)
voices/            ← your saved voice clips
```

`index.php` writes `engine/worker.py` and `engine/install.sh` from copies embedded in itself, so updating the app is just replacing `index.php`.

Apple-Silicon fixes applied by the worker (Seed-VC upstream is unchanged):
- the pitch tracker (RMVPE) runs in 20 s chunks and returns float32 (MPS has no float64)
- the BigVGAN vocoder runs in short overlapping windows (MPS has a 65 536-sample conv limit)
- the diffusion context window shrinks on low-RAM Macs

## Responsible use

Only clone voices you own or have permission to use. Don't use this to impersonate real people.

## License

MIT for this app. Seed-VC (GPL-3.0) and Demucs (MIT) are downloaded at install time under their own licenses.
