#!/usr/bin/env python3
"""Build a playable game from a cast: a hero, some enemies and a boss.

Writes a game_make spec from the command line (no language model), sends it
to the Game Creator, waits for the build and prints the HTML path and the URL
to play it in the browser.

Args:
  --hero WHO        a preset or saved toon name, or `buddy:<prompt>` for a voxel
                    buddy (default: hacker)
  --enemies LIST    comma-separated voxel buddy prompts (default: three slimes/bats)
  --boss PROMPT     voxel buddy prompt for the boss, or 'none' (default: a lava crab)
  --mold KIND       platformer, topdown or shooter (default: platformer)
  --levels N        number of stages, 1-8 (default: 3)
  --title TEXT      game title (default: built from the hero)
  --voice NAME      saved voice for the voice lines (default: AI reader)
  --reader ID       TTS reader (default: am_michael)
  --music PROMPT    compose a music track, e.g. "chiptune boss fight" (default: none)
  --sfx STYLE       chip, arcade, soft or glitch (default: chip)
  --difficulty D    chill, normal, hard or brutal (default: normal)
  --bg NAME         backdrop for every stage (default: picks per stage)

Output: prints the game file path and the play URL on stdout.
Exit code 1 if the build failed.
"""

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402

BACKDROPS = ["stage", "sky", "city", "space", "synthwave", "matrix", "hacker", "studio"]
BEHAVIORS = ["patrol", "chase", "fly", "shoot"]


def cast_member(text, index=0):
    """Turn 'buddy:a green slime' or 'Slime' into a cast dict."""
    text = text.strip()
    if text.lower().startswith("buddy:"):
        prompt = text[6:].strip()
        return {"buddy": prompt, "name": name_from(prompt)}
    return {"toon": text}


def name_from(prompt):
    """A short display name from a buddy prompt: the last noun-ish word, capitalised."""
    words = [w.strip(",.") for w in prompt.split() if w.lower() not in ("a", "an", "the", "with", "and", "of")]
    return (words[-1] if words else "Buddy").capitalize()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--hero", default="hacker")
    ap.add_argument("--enemies", default="angry green slime,purple cave bat,rusty patrol robot")
    ap.add_argument("--boss", default="giant lava crab boss")
    ap.add_argument("--mold", default="platformer", choices=["platformer", "topdown", "shooter"])
    ap.add_argument("--levels", type=int, default=3)
    ap.add_argument("--title", default=None)
    ap.add_argument("--voice", default="")
    ap.add_argument("--reader", default="am_michael")
    ap.add_argument("--music", default=None)
    ap.add_argument("--sfx", default="chip", choices=["chip", "arcade", "soft", "glitch"])
    ap.add_argument("--difficulty", default="normal", choices=["chill", "normal", "hard", "brutal"])
    ap.add_argument("--bg", default=None)
    a = ap.parse_args()

    hero = cast_member(a.hero)
    hero_name = hero.get("name") or hero.get("toon", "Hero").capitalize()
    enemies = []
    for i, e in enumerate([x for x in a.enemies.split(",") if x.strip()][:3]):
        m = {"buddy": e.strip(), "name": name_from(e), "behavior": BEHAVIORS[i % len(BEHAVIORS)]}
        if a.mold == "shooter" and m["behavior"] == "patrol":
            m["behavior"] = "fly"
        enemies.append(m)
    boss = None if a.boss.strip().lower() in ("", "none", "no") else {"buddy": a.boss.strip(), "name": name_from(a.boss), "hp": 3}

    n = max(1, min(8, a.levels))
    levels = []
    for i in range(n):
        last = i == n - 1
        levels.append({
            "name": "Stage %d" % (i + 1) if not last else "Final Stage",
            "bg": a.bg or BACKDROPS[i % len(BACKDROPS)],
            "length": "short" if i == 0 else ("long" if last else "medium"),
            "difficulty": min(3, 1 + (i * 3) // max(1, n)),
            "key": i % 2 == 1,
            "boss": bool(boss) and last,
        })

    title = a.title or "%s's Quest" % hero_name
    spec = {
        "title": title,
        "pitch": "%s versus %s%s." % (hero_name, ", ".join(e["name"] for e in enemies) or "the void",
                                       (" and the mighty %s" % boss["name"]) if boss else ""),
        "mold": a.mold,
        "cast": {"hero": hero, "enemies": enemies, "boss": boss},
        "levels": levels,
        "rules": {"lives": 3, "difficulty": a.difficulty, "win": "boss" if boss else "goal", "timeLimit": 0},
        "sfx": a.sfx,
        "music": a.music or None,
        "voice": {
            "saved_voice": a.voice or "",
            "tts_voice": a.reader,
            "lines": {
                "start": "Let's go, %s!" % hero_name,
                "win": "We did it! %s saves the day." % hero_name,
                "lose": "Game over. Try again, %s." % hero_name,
                "hurt": "Ouch!",
            },
        },
    }

    th = ToonHouse()
    try:
        st = th.game(spec)
    except ToonHouseError as e:
        print("game build failed: %s" % e, file=sys.stderr)
        return 1
    print(st.get("path", ""))
    print(st.get("play_url", ""))
    return 0 if st.get("path") else 1


if __name__ == "__main__":
    sys.exit(main())
