#!/usr/bin/env python3
"""Re-sing every song in a folder in one of your saved voices.

Walks a folder for mp3/wav/m4a/flac files, runs each through Voice Swap
(Demucs split, Seed-VC conversion, re-mix) with the saved voice, downloads the
finished mix MP3 into the output folder and prints a table when done.

Args:
  folder            folder of songs (searched one level deep)
  --voice NAME      saved voice name (required; see `toonhouse.py voices`)
  --steps N         diffusion steps, 10-50 (default: 25)
  --out DIR         where to put the mixes (default: <folder>/in-my-voice)
  --no-separate     the files are dry acapellas (skip the music split)
  --semitones N     manual pitch shift on top of auto octave (default 0)
  --similarity F    voice likeness 0-1 (default 0.7)

Output: one MP3 per song in --out, then a table of song -> result on stdout.
Exit code 1 if any song failed, 2 if no songs were found.
"""

import argparse
import shutil
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402

EXTS = {".mp3", ".wav", ".m4a", ".flac"}


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("folder")
    ap.add_argument("--voice", required=True)
    ap.add_argument("--steps", type=int, default=25)
    ap.add_argument("--out", default=None)
    ap.add_argument("--no-separate", action="store_true")
    ap.add_argument("--semitones", type=float, default=0)
    ap.add_argument("--similarity", type=float, default=0.7)
    a = ap.parse_args()

    folder = Path(a.folder).expanduser().resolve()
    if not folder.is_dir():
        print("not a folder: %s" % folder, file=sys.stderr)
        return 2
    songs = sorted(p for p in list(folder.iterdir()) + list(folder.glob("*/*")) if p.suffix.lower() in EXTS and p.is_file())
    if not songs:
        print("no mp3/wav/m4a/flac files in %s" % folder, file=sys.stderr)
        return 2
    out = Path(a.out).expanduser().resolve() if a.out else folder / "in-my-voice"
    out.mkdir(parents=True, exist_ok=True)

    th = ToonHouse()
    th.voice_file(a.voice)  # fail early with the list of saved voices
    rows = []
    failed = 0
    for i, song in enumerate(songs, 1):
        print("[%d/%d] %s" % (i, len(songs), song.name), file=sys.stderr)
        t0 = time.time()
        try:
            r = th.convert_song(str(song), a.voice, separate=not a.no_separate, steps=a.steps,
                                semitones=a.semitones, similarity=a.similarity)
            src = Path(r["mp3"])
            dest = out / ("%s (%s).mp3" % (song.stem, a.voice))
            if src.resolve() != dest.resolve():
                shutil.copyfile(src, dest)
            rows.append((song.name, "ok", "%ds" % (time.time() - t0), str(dest)))
        except ToonHouseError as e:
            failed += 1
            rows.append((song.name, "FAILED", "%ds" % (time.time() - t0), str(e)[:60]))

    w = max(len(r[0]) for r in rows)
    print("%-*s  %-6s  %-6s  %s" % (w, "song", "state", "time", "result"))
    for r in rows:
        print("%-*s  %-6s  %-6s  %s" % (w, r[0], r[1], r[2], r[3]))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
