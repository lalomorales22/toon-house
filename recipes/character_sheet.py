#!/usr/bin/env python3
"""Render one character in every 2-D art style and tile the frames into a sheet.

Renders a short clip per style (sequentially, render: true), grabs a frame at
1.0 s from each MP4 with ffmpeg and tiles them into one JPG. Handy for picking
a style, or to show off a saved toon.

Args:
  --preset NAME       toon preset, e.g. hacker (default when nothing else is given)
  --toon NAME         a saved toon from Toon Lab (overrides --preset)
  --character JSON    a character dict (file path or inline JSON) merged on top
  --script TEXT       what the character says (default: one sentence)
  --styles a,b,c      styles to render (default: all 11 2-D styles)
  --camera full|medium|close  framing (default: medium)
  --aspect RATIO      16:9, 9:16 or 1:1 (default: 16:9)
  --at SECONDS        where to grab the frame (default: 1.0)
  --out DIR           where to write the sheet (default: the app's renders/ folder)

Output: prints the path of character-sheet-<name>.jpg; one MP4 per style also
lands in renders/. Exit code 1 if every render failed or ffmpeg is missing.
"""

import argparse
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402

ALL_2D = ["classic", "anime", "comic", "sketch", "clay", "ascii", "matrix", "pixel", "neon", "noir", "blueprint"]


def grid(n):
    """Columns, rows for n tiles."""
    cols = 4 if n > 9 else (3 if n > 4 else (2 if n > 1 else 1))
    rows = -(-n // cols)
    return cols, rows


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--preset", default=None)
    ap.add_argument("--toon", default=None)
    ap.add_argument("--character", default=None)
    ap.add_argument("--script", default="Hi there. This is me in every style the lab can draw.")
    ap.add_argument("--styles", default=",".join(ALL_2D))
    ap.add_argument("--camera", default="medium", choices=["full", "medium", "close"])
    ap.add_argument("--aspect", default="16:9")
    ap.add_argument("--at", type=float, default=1.0)
    ap.add_argument("--out", default=None)
    a = ap.parse_args()

    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg:
        print("ffmpeg not found on PATH", file=sys.stderr)
        return 1

    th = ToonHouse()
    character = {}
    name = None
    if a.toon:
        match = [c for c in th.chars() if c.get("name") == a.toon]
        if not match:
            print("no saved toon called %r" % a.toon, file=sys.stderr)
            return 1
        character = dict(match[0])
        name = a.toon
    if a.character:
        p = Path(a.character)
        extra = json.loads(p.read_text()) if p.exists() else json.loads(a.character)
        character.update(extra)
        name = name or extra.get("name")
    preset = a.preset or (None if a.toon else "hacker")
    name = name or character.get("name") or preset or "toon"
    slug = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-") or "toon"
    styles = [s.strip() for s in a.styles.split(",") if s.strip()]

    frames = []
    tmp = Path(tempfile.mkdtemp(prefix="charsheet-"))
    for i, style in enumerate(styles, 1):
        print("[%d/%d] style %s" % (i, len(styles), style), file=sys.stderr)
        spec = {
            "title": "%s sheet: %s" % (name, style),
            "style": style,
            "character": {k: v for k, v in character.items() if k != "style"},
            "script": a.script,
            "voice": "",
            "subtitles": {"style": "off"},
            "captions": [{"text": style, "kind": "lower", "start": 0, "end": 60}],
            "motion": {"camera": a.camera},
            "aspect": a.aspect,
            "render": True,
        }
        if preset:
            spec["preset"] = preset
        try:
            st = th.make(spec)
            mp4 = st.get("render_path")
            if not mp4:
                raise ToonHouseError("no render_path (is Chrome installed?)")
            frame = tmp / ("%02d-%s.png" % (i, style))
            subprocess.run([ffmpeg, "-y", "-v", "error", "-ss", str(a.at), "-i", mp4, "-frames:v", "1", str(frame)], check=True)
            frames.append(frame)
        except (ToonHouseError, subprocess.CalledProcessError) as e:
            print("  %s failed: %s" % (style, e), file=sys.stderr)

    if not frames:
        print("every style failed", file=sys.stderr)
        return 1

    cols, rows = grid(len(frames))
    out_dir = Path(a.out).expanduser() if a.out else th.app_dir / "renders"
    out_dir.mkdir(parents=True, exist_ok=True)
    sheet = out_dir / ("character-sheet-%s.jpg" % slug)
    # pad to a full grid with black tiles so the tile filter has every slot
    cmd = [ffmpeg, "-y", "-v", "error"]
    for f in frames:
        cmd += ["-i", str(f)]
    inputs = "".join("[%d:v]scale=480:-2,pad=480:ih:0:0[s%d];" % (i, i) for i in range(len(frames)))
    concat = "".join("[s%d]" % i for i in range(len(frames)))
    filt = "%s%sconcat=n=%d:v=1:a=0,tile=%dx%d:padding=6:margin=6:color=black[out]" % (inputs, concat, len(frames), cols, rows)
    cmd += ["-filter_complex", filt, "-map", "[out]", "-frames:v", "1", "-q:v", "3", str(sheet)]
    try:
        subprocess.run(cmd, check=True)
    except subprocess.CalledProcessError as e:
        print("ffmpeg tiling failed: %s" % e, file=sys.stderr)
        return 1
    shutil.rmtree(tmp, ignore_errors=True)
    print(sheet)
    return 0


if __name__ == "__main__":
    sys.exit(main())
