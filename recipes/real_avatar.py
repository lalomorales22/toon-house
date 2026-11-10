#!/usr/bin/env python3
"""A Real Avatar short: you (green-screen footage) over a screen recording, saying a script in your own voice.

Give it rough notes (the local model writes the script) or the script itself,
and the screen recording to put you over. The footage is the first clip you
uploaded on the Real Avatar page unless --footage names another one: a file
from options -> footage, or a path to a clip, which is imported and indexed
first. By default the video is vertical, you sit bottom-left at 0.4 of the
frame height, the subtitles are karaoke style, and the script is spoken in
your first saved voice.

Args:
  --notes TEXT        what the video is about; the local model writes the script
  --script TEXT       or exactly what you say (about 2.6 words per second)
  --script-file PATH  or a .txt / .md file with the script
  --seconds N         target length for --notes, 15-180 (default: 45)
  --tone NAME         conversational, energetic, calm, funny or serious (default: conversational)
  --footage FILE      a file from options -> footage, or a path to a green-screen clip (default: the first clip)
  --broll FILE        the background: a file from options -> broll, a clip, a scene picture, or a path to a
                      screen recording / picture (default: a solid color)
  --voice NAME        saved voice (default: the first saved voice; "" = the AI reader only)
  --reader ID         TTS reader that sets pace and accent (default: am_michael)
  --corner POS        bl, br, tl, tr, bc or c (default: bl)
  --size F            your height as a fraction of the frame, 0.15-1 (default: 0.4 for 9:16, 0.45 otherwise)
  --aspect RATIO      9:16, 16:9 or 1:1 (default: 9:16)
  --title TEXT        the video's title, also shown as a title card for the first seconds
  --lower TEXT        a lower-third, e.g. "Lalo · built on a Mac mini"
  --no-subs           no subtitles
  --export-avatar     also keep reals/<id>/avatar.webm: you alone, keyed, with transparency
  --dry-run           print the spec instead of rendering

Output: prints render_path (the finished MP4). When the lip-sync engine is not
installed the video uses your footage as-is and the server's warning is printed
to stderr. Exit 1 if the render failed.
"""

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402


def media_arg(value):
    """A --footage / --broll value: a file on disk becomes an absolute path (the server imports it); a name is passed as is."""
    if not value:
        return None
    p = Path(value).expanduser()
    return str(p.resolve()) if p.is_file() else value


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    src = ap.add_mutually_exclusive_group(required=True)
    src.add_argument("--notes", help="what the video is about; the local model writes the script")
    src.add_argument("--script", help="exactly what you say")
    src.add_argument("--script-file", help="a .txt / .md file with the script")
    ap.add_argument("--seconds", type=int, default=45, help="target length for --notes (default: 45)")
    ap.add_argument("--tone", default="conversational", choices=["conversational", "energetic", "calm", "funny", "serious"])
    ap.add_argument("--footage", default=None, help="footage file name, or a path to a green-screen clip (default: the first clip)")
    ap.add_argument("--broll", default=None, help="background: a broll/clip/scene file name, or a path to a recording or picture")
    ap.add_argument("--voice", default=None, help="saved voice (default: the first saved voice; '' = AI reader only)")
    ap.add_argument("--reader", default="am_michael")
    ap.add_argument("--corner", default="bl", choices=["bl", "br", "tl", "tr", "bc", "c"])
    ap.add_argument("--size", type=float, default=None, help="your height as a fraction of the frame (0.15-1)")
    ap.add_argument("--aspect", default="9:16", choices=["9:16", "16:9", "1:1"])
    ap.add_argument("--title", default=None, help="the video's title, shown as a title card")
    ap.add_argument("--lower", default=None, help='a lower-third, e.g. "Name · line"')
    ap.add_argument("--no-subs", action="store_true")
    ap.add_argument("--export-avatar", action="store_true")
    ap.add_argument("--dry-run", action="store_true", help="print the spec instead of rendering")
    a = ap.parse_args()

    th = ToonHouse()
    try:
        footage = media_arg(a.footage)
        if not footage and not th.footage():
            print("no footage yet: drop a green-screen clip of yourself on the Real Avatar page, or pass --footage <clip>", file=sys.stderr)
            return 1

        if a.script_file:
            script = Path(a.script_file).expanduser().read_text(encoding="utf-8")
        elif a.script:
            script = a.script
        else:
            th.log("writing the script from your notes (local model) ...")
            script = th.real_script(a.notes, seconds=a.seconds, tone=a.tone) or ""
            th.log(script)
        script = " ".join(script.split())
        if not script:
            print("the script is empty", file=sys.stderr)
            return 1

        spec = {
            "script": script,
            "aspect": a.aspect,
            "tts_voice": a.reader,
            "avatar": {"corner": a.corner},
            "subtitles": {"style": "off" if a.no_subs else "karaoke"},
            "captions": [],
            "export_avatar": a.export_avatar,
        }
        if footage:
            spec["footage"] = footage
        broll = media_arg(a.broll)
        if broll:
            spec["broll"] = broll
        if a.voice is not None:          # omitted = the server's default, the first saved voice
            spec["voice"] = a.voice
        if a.size:
            spec["avatar"]["size"] = a.size
        if a.title:
            spec["title"] = a.title
            spec["captions"].append({"kind": "title", "text": a.title, "start": 0.3, "end": 3.5})
        if a.lower:
            spec["captions"].append({"kind": "lower", "text": a.lower, "start": 3.5 if a.title else 0.5, "end": 9.0 if a.title else 6.0})
        if a.dry_run:
            print(json.dumps(spec, indent=2, ensure_ascii=False))
            return 0

        st = th.real(spec)
    except ToonHouseError as e:
        print("real avatar failed: %s" % e, file=sys.stderr)
        return 1
    if st.get("warning"):
        print("warning: %s" % st["warning"], file=sys.stderr)
    if not st.get("render_path"):
        print("finished without render_path: %s" % json.dumps(st)[:400], file=sys.stderr)
        return 1
    print(st["render_path"])
    return 0


if __name__ == "__main__":
    sys.exit(main())
