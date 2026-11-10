#!/usr/bin/env python3
"""Smoke test: render a fixed small clip and compare a frame against a baseline.

Run this after every change to index.php or the engine. It renders a fixed
spec (hacker preset, ascii style, two sentences, 16:9), grabs the frame at
1.5 s with ffmpeg and measures PSNR against recipes/.baseline/regression.png.
The first run (or --update) writes the baseline instead.

Args:
  --threshold DB    minimum average PSNR in dB to pass (default: 20)
  --at SECONDS      frame time (default: 1.5)
  --update          overwrite the baseline with this render's frame
  --keep            keep the extracted frame next to the baseline as last.png

Output: prints the PSNR and PASS/FAIL. Exit code 0 on pass or when the baseline
was just created; 1 if the render failed or PSNR is below the threshold.
"""

import argparse
import re
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402

BASELINE_DIR = Path(__file__).resolve().parent / ".baseline"
BASELINE = BASELINE_DIR / "regression.png"

SPEC = {
    "title": "regression render",
    "preset": "hacker",
    "style": "ascii",
    "script": "This is the regression render. If you can read this, the pipeline still works.",
    "voice": "",
    "tts_voice": "am_michael",
    "subtitles": {"style": "karaoke"},
    "motion": {"camera": "medium", "energy": 1, "gestures": 0.6, "dance": False},
    "aspect": "16:9",
    "render": True,
}


def psnr(ffmpeg, a, b):
    """Average PSNR in dB between two images via ffmpeg's psnr filter (inf -> 99)."""
    cmd = [ffmpeg, "-hide_banner", "-i", str(a), "-i", str(b),
           "-filter_complex", "[0:v][1:v]scale2ref[x][y];[x][y]psnr", "-f", "null", "-"]
    r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    m = re.search(r"PSNR .*?average:(inf|[0-9.]+)", r.stderr)
    if not m:
        raise RuntimeError("could not parse PSNR from ffmpeg:\n" + r.stderr[-800:])
    return 99.0 if m.group(1) == "inf" else float(m.group(1))


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--threshold", type=float, default=20.0)
    ap.add_argument("--at", type=float, default=1.5)
    ap.add_argument("--update", action="store_true")
    ap.add_argument("--keep", action="store_true")
    a = ap.parse_args()

    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg:
        print("FAIL: ffmpeg not found", file=sys.stderr)
        return 1

    th = ToonHouse()
    try:
        st = th.make(SPEC, timeout=900)
    except ToonHouseError as e:
        print("FAIL: render error: %s" % e, file=sys.stderr)
        return 1
    mp4 = st.get("render_path")
    if not mp4 or not Path(mp4).exists():
        print("FAIL: no render_path (audio: %s)" % st.get("audio_path"), file=sys.stderr)
        return 1

    BASELINE_DIR.mkdir(parents=True, exist_ok=True)
    frame = BASELINE_DIR / "last.png"
    r = subprocess.run([ffmpeg, "-y", "-v", "error", "-ss", str(a.at), "-i", mp4, "-frames:v", "1", str(frame)])
    if r.returncode or not frame.exists():
        print("FAIL: could not extract a frame from %s" % mp4, file=sys.stderr)
        return 1

    if a.update or not BASELINE.exists():
        shutil.copyfile(frame, BASELINE)
        if not a.keep:
            frame.unlink()
        print("baseline written: %s (render: %s)" % (BASELINE, mp4))
        return 0

    try:
        db = psnr(ffmpeg, frame, BASELINE)
    except RuntimeError as e:
        print("FAIL: %s" % e, file=sys.stderr)
        return 1
    if not a.keep:
        frame.unlink()
    ok = db >= a.threshold
    print("PSNR %.2f dB vs baseline (threshold %.0f): %s  render=%s" % (db, a.threshold, "PASS" if ok else "FAIL", mp4))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
