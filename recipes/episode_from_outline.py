#!/usr/bin/env python3
"""Turn a markdown outline into a multi-scene episode: each ## heading is a scene.

The first `# Title` line (if any) names the episode. Every `## Heading` starts
a talk scene whose script is the paragraph(s) under it. The heading is shown
as a lower-third; with --broll a 3 s Wan clip made from the heading text is
inserted between scenes. The episode starts with a title card.

Outline example:

    # Local AI News, Ep. 1
    ## The new small model
    Tonight a seven billion parameter model landed that runs on a laptop ...
    ## Why it matters
    Local means private ...

Args:
  outline           path to the .md / .txt outline
  --preset NAME     toon preset for every scene (default: anchor)
  --style NAME      art style (default: comic)
  --aspect RATIO    16:9, 9:16 or 1:1 (default: 16:9)
  --title TEXT      episode title (default: the # heading or the file name)
  --name TEXT       the character's name (default: from the preset)
  --voice NAME      saved voice (default: AI reader)
  --reader ID       TTS reader (default: am_michael)
  --scene PROMPT    painted background for the talk scenes (default: none)
  --broll           add a 3 s video clip between scenes from each heading
  --bgm PROMPT      compose background music under the whole episode
  --transition cut|fade  (default: fade)
  --dry-run         print the episode JSON instead of rendering

Output: prints render_path (the finished MP4). Exit 1 if the render failed.
"""

import argparse
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402


def parse_outline(text):
    """Return (title, [(heading, script), ...]) from a markdown outline."""
    title = None
    scenes = []
    heading, buf = None, []

    def flush():
        if heading is not None:
            script = " ".join(" ".join(buf).split())
            if script:
                scenes.append((heading, script))

    for line in text.splitlines():
        s = line.rstrip()
        if re.match(r"^#\s+", s) and title is None and heading is None:
            title = s.lstrip("#").strip()
        elif re.match(r"^##\s+", s):
            flush()
            heading, buf = s.lstrip("#").strip(), []
        elif heading is not None:
            s = re.sub(r"^[-*>]\s+", "", s.strip())      # bullets / quotes
            s = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", s)  # links
            s = s.replace("**", "").replace("__", "").replace("`", "")
            buf.append(s)
    flush()
    return title, scenes


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("outline")
    ap.add_argument("--preset", default="anchor")
    ap.add_argument("--style", default="comic")
    ap.add_argument("--aspect", default="16:9")
    ap.add_argument("--title", default=None)
    ap.add_argument("--name", default=None)
    ap.add_argument("--voice", default="")
    ap.add_argument("--reader", default="am_michael")
    ap.add_argument("--scene", default=None)
    ap.add_argument("--broll", action="store_true")
    ap.add_argument("--bgm", default=None)
    ap.add_argument("--transition", default="fade", choices=["cut", "fade"])
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()

    path = Path(a.outline).expanduser()
    if not path.is_file():
        print("no such outline: %s" % path, file=sys.stderr)
        return 1
    doc_title, parts = parse_outline(path.read_text(encoding="utf-8"))
    if not parts:
        print("no '## Heading' scenes with text under them in %s" % path, file=sys.stderr)
        return 1
    title = a.title or doc_title or path.stem.replace("-", " ").replace("_", " ").title()

    scenes = [{"kind": "title", "text": title.upper(), "sub": "%d scenes" % len(parts), "seconds": 3, "color": "#111111"}]
    for i, (heading, script) in enumerate(parts):
        talk = {
            "kind": "talk",
            "preset": a.preset,
            "style": a.style,
            "script": script,
            "voice": a.voice or "",
            "tts_voice": a.reader,
            "captions": [{"text": heading, "kind": "lower", "start": 0.5, "end": 6.0}],
            "subtitles": {"style": "karaoke"},
            "motion": {"camera": "medium"},
        }
        if a.name:
            talk["name"] = a.name
        if a.scene:
            talk["scene"] = a.scene
        scenes.append(talk)
        if a.broll and i < len(parts) - 1:
            scenes.append({"kind": "clip", "prompt": "cinematic b-roll: %s, slow camera move, no text" % heading, "image": "", "seconds": 3})

    episode = {"title": title, "aspect": a.aspect, "transition": a.transition,
               "bgm": a.bgm or None, "scenes": scenes}
    if a.dry_run:
        print(json.dumps(episode, indent=2, ensure_ascii=False))
        return 0

    th = ToonHouse()
    try:
        st = th.show(episode)
    except ToonHouseError as e:
        print("episode failed: %s" % e, file=sys.stderr)
        return 1
    if not st.get("render_path"):
        print("finished without render_path: %s" % json.dumps(st)[:400], file=sys.stderr)
        return 1
    print(st["render_path"])
    return 0


if __name__ == "__main__":
    sys.exit(main())
