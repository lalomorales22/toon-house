#!/usr/bin/env python3
"""Make a news-anchor cartoon that reads today's top story on a topic.

Searches this week's news with the app (`news`), takes the top story and
writes a 90-130 word first-person news read from the story's title, body and
source, with no language model involved. Then it renders a Toon Lab video with
karaoke subtitles, a title caption, a lower-third and a painted newsroom scene.

Args:
  --topic TEXT      what to search (default: "local AI")
  --preset NAME     toon preset (default: anchor)
  --style NAME      art style (default: comic)
  --aspect RATIO    16:9, 9:16 or 1:1 (default: 9:16)
  --voice NAME      a saved voice name, or none for the AI reader (default: none)
  --reader ID       TTS reader voice (default: am_michael)
  --name TEXT       the anchor's on-screen name (default: Ron Newsly)
  --story N         pick the N-th story instead of the top one (0-based)
  --director        let the local Director write the script instead (needs Ollama)
  --no-scene        skip the painted newsroom (faster)
  --bgm PROMPT      compose background music under the voice

Output: prints the MP4 path (render_path) on stdout; progress on stderr.
Exit code 1 if no story was found or the render failed.
"""

import argparse
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402

WORDS_PER_SECOND = 2.6


def sentences(text):
    """Split body text into clean sentences."""
    text = re.sub(r"\s+", " ", text or "").strip()
    text = re.sub(r"https?://\S+", "", text)
    text = re.sub(r"\s*(\.\.\.|\u2026)\s*$", ".", text)  # a truncated teaser ends in an ellipsis
    parts = re.split(r"(?<=[.!?])\s+", text)
    out = []
    for p in parts:
        p = p.strip()
        if len(p.split()) < 4:
            continue
        if not p.endswith((".", "!", "?")):
            p += "."
        out.append(p)
    return out


def write_read(story, topic, name):
    """Build a 90-130 word first-person news read from a story dict (no LLM)."""
    title = re.sub(r"\s+", " ", story.get("title", "")).strip().rstrip(".")
    if not title.endswith(("!", "?")):
        title += "."
    source = story.get("source") or "the wire"
    date = story.get("date") or ""
    body = sentences(story.get("body", ""))
    out = ["Good evening, I'm %s, and this is your %s report." % (name, topic),
           "Our top story tonight: %s" % title]
    count = lambda: len(" ".join(out).split())  # noqa: E731
    for s in body:
        if count() + len(s.split()) > 105:
            break
        out.append(s)
    fillers = [
        "That is how %s is reporting it%s." % (source, (" as of %s" % date) if date else ""),
        "For anyone following %s, this is one to keep an eye on." % topic,
        "Details are still coming in, and we will keep following the story as it develops.",
        "We will have more on this, and the rest of the week in %s, in our next report." % topic,
        "As always, read the full piece yourself before you draw conclusions.",
    ]
    closing = "That report comes from %s. I'm %s. Stay curious, and we'll see you next time." % (source, name)
    for f in fillers:
        if count() + len(closing.split()) >= 90:
            break
        out.append(f)
    out.append(closing)
    text = " ".join(out)
    ws = text.split()
    if len(ws) > 130:  # trim the body, keep the closing
        text = " ".join(ws[:130 - len(closing.split()) - 1]).rstrip(",;:") + ". " + closing
    return text


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--topic", default="local AI")
    ap.add_argument("--preset", default="anchor")
    ap.add_argument("--style", default="comic")
    ap.add_argument("--aspect", default="9:16")
    ap.add_argument("--voice", default="none", help="saved voice name or 'none'")
    ap.add_argument("--reader", default="am_michael")
    ap.add_argument("--name", default="Ron Newsly")
    ap.add_argument("--story", type=int, default=0)
    ap.add_argument("--director", action="store_true")
    ap.add_argument("--no-scene", action="store_true")
    ap.add_argument("--bgm", default=None)
    a = ap.parse_args()

    th = ToonHouse()
    stories = th.news(a.topic)
    if not stories:
        print("no news stories found for %r" % a.topic, file=sys.stderr)
        return 1
    story = stories[min(a.story, len(stories) - 1)]
    print("story: %s  (%s, %s)" % (story.get("title"), story.get("source"), story.get("date")), file=sys.stderr)

    if a.director:
        spec = th.director("a news anchor reporting this story: %s. %s" % (story.get("title"), story.get("body", "")[:600])) or {}
        script = spec.get("script") or write_read(story, a.topic, a.name)
    else:
        script = write_read(story, a.topic, a.name)
    n_words = len(script.split())
    est = n_words / WORDS_PER_SECOND
    print("script: %d words, about %.0f s" % (n_words, est), file=sys.stderr)

    voice = "" if a.voice.lower() in ("", "none", "no", "ai") else a.voice
    spec = {
        "title": "%s news: %s" % (a.topic.title(), story.get("title", "")[:50]),
        "preset": a.preset,
        "style": a.style,
        "character": {"name": a.name},
        "script": script,
        "voice": voice,
        "tts_voice": a.reader,
        "captions": [
            {"text": a.topic.upper() + " NEWS", "kind": "title", "start": 0, "end": 3.5},
            {"text": "%s · %s desk" % (a.name, a.topic), "kind": "lower", "start": 3.8, "end": 9.0},
            {"text": "Source: %s" % (story.get("source") or ""), "kind": "lower",
             "start": max(10.0, est - 7.0), "end": max(13.0, est - 1.0)},
        ],
        "subtitles": {"style": "karaoke", "pos": "bottom"},
        "motion": {"camera": "medium", "energy": 1, "gestures": 0.6},
        "aspect": a.aspect,
        "render": True,
    }
    if not a.no_scene:
        spec["scene"] = "a modern television newsroom at night with a glowing world map and desks, no people"
    if a.bgm:
        spec["bgm"] = {"prompt": a.bgm, "volume": 0.2}

    try:
        st = th.make(spec)
    except ToonHouseError as e:
        print("render failed: %s" % e, file=sys.stderr)
        return 1
    if not st.get("render_path"):
        print("finished without a video (no Chrome?). audio: %s  editor: %s" % (st.get("audio_path"), st.get("toon_lab")), file=sys.stderr)
        return 1
    print(st["render_path"])
    return 0


if __name__ == "__main__":
    sys.exit(main())
