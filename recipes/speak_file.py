#!/usr/bin/env python3
"""Read a .txt or .md file out loud in your voice (or an AI reader) and save the MP3 next to it.

Markdown is cleaned up before reading (headings, bullets, links, emphasis).
With --voice the text is read by the AI reader and then converted into that
saved voice with Seed-VC; without it, the AI reader alone is used (faster).

Args:
  file              a .txt or .md file
  --voice NAME      saved voice name (default: none, AI reader only)
  --reader ID       TTS reader voice, e.g. am_michael, af_heart (default: am_michael)
  --speed F         reading speed (default: 1.0)
  --out PATH        where to save (default: <file>.mp3 next to the input)

Output: prints the MP3 path. Exit 1 on failure.
"""

import argparse
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402


def clean_markdown(text):
    """Strip markdown syntax so it reads naturally."""
    text = re.sub(r"```.*?```", "", text, flags=re.S)
    text = re.sub(r"^\s{0,3}#{1,6}\s*", "", text, flags=re.M)
    text = re.sub(r"^\s*[-*+]\s+", "", text, flags=re.M)
    text = re.sub(r"^\s*\d+\.\s+", "", text, flags=re.M)
    text = re.sub(r"!\[[^\]]*\]\([^)]*\)", "", text)
    text = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", text)
    text = re.sub(r"[*_`~>#|]+", "", text)
    text = re.sub(r"https?://\S+", "", text)
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("file")
    ap.add_argument("--voice", default=None)
    ap.add_argument("--reader", default="am_michael")
    ap.add_argument("--speed", type=float, default=1.0)
    ap.add_argument("--out", default=None)
    a = ap.parse_args()

    src = Path(a.file).expanduser()
    if not src.is_file():
        print("no such file: %s" % src, file=sys.stderr)
        return 1
    text = src.read_text(encoding="utf-8", errors="replace")
    if src.suffix.lower() in (".md", ".markdown"):
        text = clean_markdown(text)
    text = text.strip()
    if not text:
        print("the file is empty", file=sys.stderr)
        return 1
    out = Path(a.out).expanduser() if a.out else src.with_suffix(".mp3")
    print("%d words -> %s" % (len(text.split()), out), file=sys.stderr)

    th = ToonHouse()
    try:
        r = th.speak(text, voice=a.voice, reader=a.reader, speed=a.speed, out=str(out))
    except ToonHouseError as e:
        print("failed: %s" % e, file=sys.stderr)
        return 1
    print(r.get("mp3") or out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
