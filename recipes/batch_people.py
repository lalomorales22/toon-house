#!/usr/bin/env python3
"""Build N random realistic 3D people in Blender from the person-builder options.

Reads options().person_builder.options, picks a random value for every option
(a random integer between min and max for numeric ones), and calls person_make
for each spec in turn. Needs Blender; the first build installs MPFB (~280 MB).

Args:
  --count N      how many people to build (default: 3)
  --seed N       random seed so a batch is reproducible (default: random)
  --prompt TEXT  only used to name them, e.g. "newsroom crew" -> "Newsroom Crew 1"
  --dry-run      print the specs without building anything

Output: prints one line per person: avatar id and the spec summary.
Exit code 1 if any build failed.
"""

import argparse
import json
import random
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from toonhouse import ToonHouse, ToonHouseError  # noqa: E402


def random_spec(options, rng, name):
    """One random person spec from the person_builder option table."""
    spec = {"name": name}
    for key, choices in options.items():
        if isinstance(choices, dict) and "min" in choices and "max" in choices:
            spec[key] = rng.randint(int(choices["min"]), int(choices["max"]))
        elif isinstance(choices, (list, tuple)) and choices:
            spec[key] = rng.choice(list(choices))
    # keep the outfit's cut consistent with the body, when the table has gendered outfits
    outfits = options.get("outfit") or []
    body = spec.get("body")
    if body in ("man", "woman") and outfits:
        prefix = "male_" if body == "man" else "female_"
        fitting = [o for o in outfits if str(o).startswith(prefix)]
        if fitting:
            spec["outfit"] = rng.choice(fitting)
    return spec


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--count", type=int, default=3)
    ap.add_argument("--seed", type=int, default=None)
    ap.add_argument("--prompt", default="Person")
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()

    seed = a.seed if a.seed is not None else random.randrange(1 << 30)
    rng = random.Random(seed)
    print("seed %d" % seed, file=sys.stderr)

    th = ToonHouse()
    pb = th.options().get("person_builder") or {}
    options = pb.get("options") or {}
    if not options:
        print("options().person_builder.options is empty; is the engine installed?", file=sys.stderr)
        return 1
    if not pb.get("blender") and not a.dry_run:
        print("warning: options().person_builder.blender is empty; Blender may not be installed", file=sys.stderr)

    base = " ".join(w.capitalize() for w in a.prompt.split()) or "Person"
    failed = 0
    for i in range(1, max(1, a.count) + 1):
        spec = random_spec(options, rng, "%s %d" % (base, i))
        summary = "%(body)s, %(age)s, %(build)s, %(ethnicity)s, hair %(hair)s, %(outfit)s" % {
            k: spec.get(k, "?") for k in ("body", "age", "build", "ethnicity", "hair", "outfit")}
        if a.dry_run:
            print(json.dumps(spec))
            continue
        print("[%d/%d] %s: %s" % (i, a.count, spec["name"], summary), file=sys.stderr)
        try:
            st = th.person(spec)
            print("%s\t%s\t%s" % (st.get("avatar", "?"), spec["name"], summary))
        except ToonHouseError as e:
            failed += 1
            print("FAILED\t%s\t%s" % (spec["name"], e))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
