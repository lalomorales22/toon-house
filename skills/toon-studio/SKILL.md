---
name: toon-studio
description: Make talking cartoon videos and speech audio locally with the Toon House app (Voice Swap + Toon Lab). A cartoon character (classic, anime, comic, sketch, clay, 3D ASCII, matrix, pixel, neon, noir or blueprint style) or a realistic 3D person says a script out loud, lip-synced, with subtitles and captions, rendered to MP4. It can also read any text in the user's own cloned voice. Use whenever the user asks to make, create or render a cartoon/toon/animated character video, a talking head, a character "talking about" a topic, a news-style or explainer clip with a character, an ASCII or anime style animation, lip-sync, a realistic / lifelike 3D human presenter (and building new 3D people in Blender), or to have text spoken in their voice (e.g. "go create a hacker on a computer in that weird 3d ascii style talking about local ai news").
---

# Toon Studio (Voice Swap + Toon Lab)

Everything runs on this Mac: Kokoro text-to-speech, Seed-VC voice conversion (the user's own voice), wav2vec2 lip-sync, and a headless-Chrome renderer that records the Toon Lab page to MP4. You write a **spec** (JSON); the app does the rest.

App folder (contains `index.php`): `/Users/megabrain/Desktop/new-OS-Apps/toon-house`.
The app also has a 🕹 Game Creator page (games built in the browser from the user's toons and voxel buddies). It has no CLI or API for building games, so agents don't drive it; point the user at it instead.
If the app was moved, or you're another agent on another machine, use the folder you were given and set it in `APP` below.

```bash
APP="${TOON_STUDIO:-/Users/megabrain/Desktop/new-OS-Apps/toon-house}"
php "$APP/index.php" options            # every valid option, preset, saved voice, TTS voice and font, as JSON
php "$APP/index.php" make spec.json --wait   # speak → lip-sync → render; prints JSON with render_path at the end
php "$APP/index.php" status <id> --wait      # follow a project started without --wait
php "$APP/index.php" person person.json      # build a new realistic 3D person in Blender (~10 s); prints its avatar id
```

The CLI starts the app server on http://127.0.0.1:8777 if it isn't running. Progress lines go to stderr. The final JSON goes to stdout.
**Always use `--wait`** from an agent shell: some sandboxes kill background processes when a command returns, and that would stop a render halfway. Give the command a long timeout, about 10 minutes.

## Workflow

1. **Understand the ask.** Who is the character, what's the style, what do they talk about, how long, and in whose voice.
2. **Get the content right.** If the topic needs facts or news ("talking about local AI news"), research it first with your own tools, such as web search. Then write the script yourself. The app's built-in Director uses a small local model and has no internet, so real content should come from you.
3. **Write the script** as the character speaking: first person, in character, plain sentences, no stage directions, no emoji, no markdown. Speaking pace is about **2.6 words per second**, so 60–150 words makes a 25–60 s video. Spell out things that are awkward to read, e.g. "seven billion parameters", not "7B params".
4. **Run `options`** if you need exact option names or saved voice names. Pick a `preset` close to the request, then override just the fields that differ.
5. **Write the spec** to a temp file and run `make … --wait`. Expect about 1–2 min for a 30 s clip: speech 10–70 s, lip-sync about 15 s, then real-time recording.
6. **Report** `render_path` (the MP4) to the user. Also give `toon_lab`, the link that opens the project in the editor to tweak and re-record.

## Spec

All fields are optional except one of `script` or `audio`. Anything invalid falls back to a default; you never get an error for a bad color or option name.

```json
{
  "title": "Local AI News",
  "preset": "hacker",
  "style": "ascii",
  "character": { "name": "Zero Cool", "topColor": "#111111" },
  "script": "Welcome back to the terminal. This week in local AI ...",
  "voice": "",
  "tts_voice": "am_michael",
  "speed": 1.0,
  "captions": [
    { "text": "LOCAL AI NEWS", "kind": "title", "start": 0, "end": 3, "font": "Press Start 2P", "color": "#00ff88" },
    { "text": "Zero Cool · terminal correspondent", "kind": "lower", "start": 3.2, "end": 7.5 }
  ],
  "subtitles": { "style": "karaoke", "font": "Press Start 2P", "hi": "#00ff88", "size": 0.8, "pos": "bottom", "box": false },
  "motion": { "camera": "medium", "energy": 1, "gestures": 0.6, "dance": false },
  "scene": "cluttered hacker basement at night with glowing monitors",
  "bgm": { "prompt": "dark synthwave beat, deep bass, steady drums", "volume": 0.25 },
  "aspect": "16:9",
  "render": true
}
```

| Field | Meaning |
|---|---|
| `preset` | `hacker`, `anchor` (news desk), `rapper` (mic), `anime`, `wizard`, `streamer` (neon + gamepad), `robot`, `cowboy` (guitar), `scientist` (coffee). |
| `style` | Art style: `classic` (bold cartoon), `anime`, `comic` (halftone comic book), `sketch` (pencil on paper, boiling lines), `clay` (soft 3D toy), `ascii` (3D ASCII art), `matrix` (green ASCII with code rain), `pixel` (8-bit), `neon` (glowing lines), `noir` (black-and-white film), `blueprint` (white lines on blue), `real` (realistic 3D person, see below). |
| `character.avatar` | With `style: "real"`: which 3D person plays the part. Ids are in `options` → `avatars`. Presets pick one automatically when it exists (anchor → walter, hacker → marcus, rapper → theo, streamer → ken, scientist → rosa). |
| `character` | Any character field. The main ones: `name`, `head`, `skin`, `eyes`, `eyeColor`, `brows`, `nose`, `ears`, `mood`, `hair`, `hairColor`, `hat`, `hatColor` (also tie, mug and headphone color), `facial`, `body`, `top`, `topColor`, `pants`, `shoes`, `acc` (list), `prop`, `bg`, `bgColor`. Colors are `#rrggbb`. Run `options` for every value. |
| `prop` | What the character holds or sits at: `mic`, `coffee`, `phone`, `guitar`, `gamepad`, `computer` (desk with code monitor), `laptop`, `newsdesk`. |
| `bg` | Background: `stage`, `sky`, `studio`, `hacker`, `city`, `news`, `matrix`, `space`, `synthwave`, `color`, `green` (green screen). |
| `script` | Text the character speaks. Markdown is cleaned up before reading. |
| `audio` | Instead of `script`: an absolute path to an audio file to lip-sync. Add `"music": true` if it has a backing track (the voice is isolated first). |
| `voice` | A saved voice name (from `options` → `saved_voices`). The script is then spoken in that person's cloned voice. Empty means the AI voice only, which is faster. Only use the user's own voice, or one they have permission for. |
| `tts_voice` | Reader voice: `am_*` and `bm_*` are male (US/UK), `af_*` and `bf_*` female. With `voice` set, this only sets pacing and accent, so match gender for the best result. |
| `captions` | `kind`: `title` (big text at the top), `lower` (lower-third name banner), `bubble` (speech bubble next to the head). Times are in seconds. |
| `subtitles` | Auto subtitles from the spoken words. `style`: `karaoke`, `subtitle`, `pop` (one word at a time), or `off`. |
| `motion.camera` | `full` (whole body), `medium` (chest up), `close` (face). |
| `aspect` | `16:9`, `9:16` (Shorts/TikTok), `1:1`. |
| `scene` | A background picture. A string (or `{"prompt": …}`) paints one with FLUX in the art style's look, about 5 s. `{"file": "/abs/path.jpg"}` uses your own. It sets `character.bg = "image"`. Describe a place, not people: the character stands in front of it. |
| `bgm` | Background music under the voice. A string (or `{"prompt": …, "seconds": 30}`) composes a track with MusicGen while the voice is made, adding about a minute. `{"file": "/abs/path.mp3"}` uses your own, and `{"file": "<name from options → music>"}` reuses one. Also `volume` (0–1, default 0.25) and `duck` (dip under the voice, default true). |
| `news` | With `prompt`: `true` (or a search string) finds this week's top DuckDuckGo News story and the Director writes the script from the article. The status shows it under `sources`. Prefer researching and writing the script yourself when you can. |
| `render` | `false` skips the video. You still get `audio_path` (the speech MP3) and the lip-sync, which is handy for "say this in my voice". |

## Recipes

**Text in the user's voice, no video:**
```json
{ "script": "…", "voice": "<their saved voice>", "render": false }
```
Use `audio_path` from the result.

**Vertical short:** set `"aspect": "9:16"` and `"motion": {"camera": "medium"}`, plus big `pop` subtitles: `"subtitles": {"style": "pop", "font": "Luckiest Guy", "size": 1.3}`.

**Let the local AI do everything** (no research, quick ideas):
```bash
php "$APP/index.php" make --prompt "a wizard explaining compound interest" --wait
```

**Fully automatic news clip** (topical story + scene + music, about 2–3 min):
```json
{ "prompt": "a hacker talking about local AI news", "news": "local AI models", "preset": "hacker",
  "scene": "cluttered hacker basement at night with glowing monitors", "bgm": "dark synthwave beat, deep bass" }
```

**Realistic 3D presenter:** `"style": "real"`, `"character": {"avatar": "walter", "hairColor": "#9a9a9a", "bg": "news"}`, `"motion": {"camera": "medium"}`.
In this style, hair/top/pants/shoe colors, `acc` glasses or shades, `bg`, camera, subtitles and captions apply. Props, hats and the cartoon face fields are ignored. Skin keeps the person's own texture unless you set a `skin` color.

**A new 3D person** (when no existing one fits, e.g. "an older Asian woman in a suit"): write `person.json` and run `person`. It needs Blender installed. The first run installs the MPFB extension into Blender and downloads ~280 MB of CC0 assets, so give it a 15-minute timeout.
```json
{ "name": "Mei", "body": "woman", "age": 62, "build": "slim", "height": "short", "ethnicity": "asian", "tone": "auto",
  "hair": "bob01", "hairColor": "#9a9a9a", "eyebrows": "eyebrow005", "eyes": "brown", "outfit": "female_elegantsuit01", "shoes": "shoes06" }
```
Valid values are in `options` → `person_builder.options` (`body`: man, woman, neutral; `build`: slim, average, athletic, heavy; `ethnicity` (face shape): african, asian, caucasian, mixed; `tone` (skin): auto, light, medium, tan, brown, dark; `hairColor`: any `#rrggbb`, used automatically when the person plays a part; `hair`: short01–04, afro01, bob01/02, braid01, ponytail01, long01, none; `outfit`: male_casualsuit04 blue tee + jeans, 06 white tee + jeans, 02 long-sleeve tee + jeans, 01 navy shirt + dark jeans, 03 striped shirt + jeans, 05 jacket + jeans, male_elegantsuit01 black suit + tie, male_worksuit01 overalls, female_casualsuit01 fitted tee + jeans, female_casualsuit02 tee + denim shorts, female_elegantsuit01 blouse + skirt, female_sportsuit01 crop top + leggings; any outfit fits any body). The result's `avatar` is the id for `character.avatar`.

## HTTP API (for agents without a shell)

The server must be running. The user starts it with `php index.php`.

- `POST http://127.0.0.1:8777/?a=make` with the spec as a JSON body returns `{"id": …}`.
- `GET ?a=make_status&id=<id>` returns `state` (`running`, `done` or `error`), `progress`, `stage`, `render_path`, `audio_path`, `error` and `toon_lab`.
- `GET ?a=options` returns all options.
- `POST ?a=person_make` with a person JSON body returns `{"id": …}`; `GET ?a=person_status&id=…` returns `state`, `stage`, `progress` and `avatar`.
- `POST ?a=director` with form field `prompt` returns `{"spec": …}`, a spec written by the local LLM for you to edit before calling `make`.

## Troubleshooting

- **"Engine not installed yet"**: the user opens http://127.0.0.1:8777 once and clicks *Install engine*, or runs `php index.php setup`.
- **"No saved voice called …"**: the error lists the saved voices. The user records one on the Voice Swap page (step 1 → *Save voice as…*).
- **No Chrome found**: the project still finishes with audio and lip-sync. Give the user the `toon_lab` link and have them press *Record video*.
- Renders land in `$APP/renders/`, speech jobs in `$APP/jobs/`, and project specs in `$APP/projects/`.
