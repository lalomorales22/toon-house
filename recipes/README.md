# Recipes

Recipes are small Python scripts that drive Toon House end to end: news clips, albums in your voice, character sheets, games, episodes, Real Avatar shorts. Each one imports the client in [`tools/toonhouse.py`](../tools/toonhouse.py) (stdlib only, Python 3.9+), which talks to the local server at `http://127.0.0.1:8777` and starts it (`php -S … index.php`, detached, log in `server.log`) if the port is closed. Progress goes to stderr, the result (usually a file path) to stdout, and the exit code is non-zero when something failed, so they chain in shell scripts and in the app's Recipes panel, which lists every file here by the first line of its docstring and runs it with `?a=recipe_run`. Run any of them with `--help` for the full argument list.

| Recipe | Command |
|---|---|
| News-anchor toon reading today's top story | `python3 recipes/daily_news_toon.py --topic "local AI" --style comic --aspect 9:16 [--voice NAME]` |
| Re-sing a folder of songs in your voice | `python3 recipes/album_in_my_voice.py ~/Music/album --voice NAME [--steps 25] [--out DIR]` |
| One character in all 11 2-D styles, tiled into a JPG | `python3 recipes/character_sheet.py --preset hacker [--toon NAME] [--camera medium]` |
| A playable game from a hero, enemies and a boss | `python3 recipes/game_from_cast.py --hero hacker --enemies "green slime,cave bat" --boss "lava crab" --levels 3 [--music "chiptune"]` |
| Smoke test: fixed render vs. a baseline frame (run after every change) | `python3 recipes/regression_render.py [--update]` |
| N random realistic 3D people built in Blender | `python3 recipes/batch_people.py --count 3 [--seed 7] [--prompt "newsroom crew"]` |
| Multi-scene episode from a markdown outline | `python3 recipes/episode_from_outline.py outline.md [--broll] [--bgm "lofi beat"]` |
| Read a .txt/.md aloud in your voice, MP3 saved next to it | `python3 recipes/speak_file.py notes.md [--voice NAME] [--reader am_michael]` |
| You (green-screen footage) over a screen recording, in your voice: a Real Avatar short | `python3 recipes/real_avatar.py --notes "why I run AI locally" --broll ~/Desktop/demo.mov --title "LOCAL AI" --lower "Lalo · Mac mini" [--voice NAME] [--footage FILE] [--export-avatar]` |

The client also has its own CLI: `python3 tools/toonhouse.py speak|make|paint|clip|music|game|show|real|person|status|jobs|options|footage|broll|reals [--json]`.

## Writing a new recipe

```python
#!/usr/bin/env python3
"""One sentence: what this recipe makes (the app lists recipes by this line).

Args and outputs go here."""
import argparse, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError

ap = argparse.ArgumentParser(description=__doc__.split("\n")[0]); ap.add_argument("--topic", default="local AI"); a = ap.parse_args()
th = ToonHouse()                                              # starts the server if needed
st = th.make({"preset": "hacker", "style": "ascii", "script": "Hello from a recipe about %s." % a.topic})
print(st["render_path"])                                      # stdout = result; stderr = progress; exit 1 on ToonHouseError
```

Save it as `recipes/<name>.py`, make it executable, and it shows up in the app.
