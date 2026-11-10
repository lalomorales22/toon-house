---
name: toon-studio
description: Create talking cartoon videos, realistic 3D presenters, multi-scene episodes, playable HTML games, Real Avatar videos (the user, filmed once on a green screen, lip-synced to a script in their own voice over a screen recording), scene pictures, video clips, and speech audio with the local Toon House app (Voice Swap, Toon Lab, Showrunner, Game Creator, Real Avatar). Use for animated characters speaking about a topic; talking heads; news and explainer clips; anime, comic, sketch, clay, 3D ASCII, matrix, pixel, neon, noir, blueprint, or classic styles; lip-sync, subtitles, and captions; Blender-built realistic people; multi-scene shows; games starring the user's toons; the user themself presenting over a screen recording or demo; backgrounds; and reading text in the user's own cloned voice. Requires the Toon House app on the user's Mac or a configured reachable server.
---

# Toon Studio (Voice Swap + Toon Lab + Showrunner + Game Creator + Real Avatar)

Everything runs on this Mac: Kokoro text-to-speech (or any speech model in Ollama), Seed-VC voice conversion (the user's own voice), wav2vec2 lip-sync, a headless-Chrome renderer that records the Toon Lab page to MP4, and optionally Z-Image Turbo (pictures) and Wan 2.2 (video clips) through MLX, and MuseTalk 1.5 (lip-sync on the user's own green-screen footage, for Real Avatar). You write a **spec** (JSON); the app does the rest.

App folder (contains `index.php`): `/Users/megabrain/Desktop/new-OS-Apps/toon-house`.
If the app was moved, or you're another agent on another machine, use the folder you were given and set it in `APP` below.

```bash
APP="${TOON_STUDIO:-/Users/megabrain/Desktop/new-OS-Apps/toon-house}"
php "$APP/index.php" options            # every valid option, preset, saved voice, TTS voice and font, as JSON
php "$APP/index.php" make spec.json --wait   # speak → lip-sync → render; prints JSON with render_path at the end
php "$APP/index.php" make --prompt "…" --wait            # the local Director writes the spec first
php "$APP/index.php" show episode.json --wait            # a multi-scene episode (see "Episode spec"); or show --prompt "…" --wait
php "$APP/index.php" game spec.json --wait               # a playable HTML game (see "Game spec"); or game --prompt "…" --wait
php "$APP/index.php" real spec.json --wait               # the user (green-screen footage) over a screen recording, in their voice (see "Real Avatar spec")
php "$APP/index.php" paint "a newsroom at night" --style comic --aspect 16:9 --provider local   # a scene picture → scenes/; prints file + path
php "$APP/index.php" clip "slow drift through a server room" --image <scene file> --seconds 3   # a Wan 2.2 clip → clips/ (minutes)
php "$APP/index.php" speak "text, or notes.md" --voice NAME --reader am_michael --speed 1.0      # audio only; prints the MP3 path
php "$APP/index.php" status <id> --wait      # follow a make, game, show or real id started without --wait
php "$APP/index.php" extras                  # is the local painter / video / lip-sync installed; extras install lipsync installs MuseTalk (~6 GB)
php "$APP/index.php" person person.json      # build a new realistic 3D person in Blender (~10 s); prints its avatar id
```

The CLI starts the app server on http://127.0.0.1:8777 if it isn't running. Progress and the job id go to stderr; **stdout is only the final JSON**, so `… --wait | jq .` and `json.loads(stdout)` work. Without `--wait` stdout is `{"id", "status"}`.

`options` is big (pipe it to a file). Its top-level keys: `presets` (name → character fields), `character_options` (field → allowed values; palettes list colors but any `#rrggbb` works), `character_defaults`, `saved_voices` (names for `voice`), `tts_voices` (`[id, label]` pairs for `tts_voice`), `fonts`, `subtitle_styles`, `caption_kinds`, `aspects`, `cameras` (for `motion.camera`), `scenes`, `clips`, `music`, `avatars` (for `style: "real"` only), `painter`, `video`, `renderer`, and for Real Avatar `footage` and `broll` (file → title), `real_fonts` and `lipsync` (a string: installed or not).

`preset` is the *character* (who), `style` is the *art look* (how it's drawn); combine them freely: `{"preset": "wizard", "style": "anime"}` is the wizard drawn as anime, while `preset: "anime"` is Kai, a specific blue-haired character. A top-level `style` beats the preset's own. A preset's `avatar` only matters for `style: "real"`.
**Always use `--wait`** from an agent shell: some sandboxes kill background processes when a command returns, and that would stop a render halfway. Give the command a long timeout: about 10 minutes for `make`, 20 for `show`, longer for `clip`.

From Python, `$APP/tools/toonhouse.py` is a stdlib-only client with the same verbs (`ToonHouse().make(spec)`, `.show(...)`, `.game(...)`, `.real(spec)`, `.paint(...)`, `.clip(...)`, `.speak(...)`, `.real_script(notes)`, `.footage_upload(path)`, `.broll_upload(path)`, `.status_any(id)`), and `$APP/recipes/*.py` are ready-made scripts built on it (news toon, album in the user's voice, character sheet, game from a cast, episode from an outline, real avatar from notes, speak a file; see `recipes/README.md`). `TOONHOUSE_URL` overrides the server address.

## Which tool for what

- `make`: one talking clip (one character, one script) → MP4. Also audio only with `"render": false`.
- `show`: a multi-scene episode (title cards, several talking scenes, B-roll, music under it all) → one MP4.
- `game`: a playable game as one HTML file, starring toons or voxel buddies.
- `paint`: a scene picture → `scenes/`. `clip`: a short video → `clips/`. Both are usually used *inside* a `make` or `show` spec instead (`scene`, B-roll).
- `speak`: text → MP3 in the user's voice or a reader voice, nothing else.
- `person`: a new realistic 3D person for `style: "real"`.
- `real`: the user themself (their own green-screen footage) over a screen recording, saying a script in their own voice → MP4. Needs footage in `options → footage` (or an absolute path to a clip, imported for you) and, for the mouth to follow the words, the lip-sync engine; without it the result is `passthrough` with a `warning`.

## Workflow

1. **Understand the ask.** Who is the character, what's the style, what do they talk about, how long, and in whose voice.
2. **Get the content right.** If the topic needs facts or news ("talking about local AI news"), research it first with your own tools, such as web search. Then write the script yourself. The app's built-in Director uses a small local model and has no internet, so real content should come from you.
3. **Write the script** as the character speaking: first person, in character, plain sentences, no stage directions, no emoji, no markdown. Speaking pace is about **2.6 words per second**, so 60–150 words makes a 25–60 s video. Spell out things that are awkward to read, e.g. "seven billion parameters", not "7B params".
4. **Run `options`** if you need exact option names or saved voice names. Pick a `preset` close to the request, then override just the fields that differ.
5. **Write the spec** to a file you are allowed to write (your scratch folder or the current directory; `/tmp` is blocked in some sandboxes) and run `make … --wait`. A 20 s clip with the AI voice takes about 35–60 s (speech, a few seconds of lip-sync, then real-time recording); a saved voice adds 20–60 s, a painted scene about 2 min, composed music about a minute. Treat 1–2 min per 30 s as the upper bound.
6. **Report** `render_path` (the MP4) to the user, and check `duration` (seconds) against what was asked. Also give `toon_lab`, the link that opens the project in the editor to tweak and re-record. Captions (`title`, `lower`, `bubble`) all take `font`, `color` and `size`; an unknown font or color falls back silently, like every other field.

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
| `tts_voice` | Reader voice. Kokoro ids: `am_*` and `bm_*` are male (US/UK), `af_*` and `bf_*` female. Any speech model pulled into Ollama is listed too, as `ollama/<model>/<Voice>` (e.g. `ollama/phonon:latest/Alfie`; `ollama pull phonon` gives ~40 named voices, `phonon:40m-multilingual` Spanish, French, Portuguese and German ones). `options` → `tts_voices` has the exact ids. With `voice` set, this only sets pacing and accent, so match gender for the best result. |
| `captions` | `kind`: `title` (big text at the top), `lower` (lower-third name banner), `bubble` (speech bubble next to the head). Times are in seconds. |
| `subtitles` | Auto subtitles from the spoken words. `style`: `karaoke`, `subtitle`, `pop` (one word at a time), or `off`. |
| `motion.camera` | `full` (whole body), `medium` (chest up), `close` (face). |
| `aspect` | `16:9`, `9:16` (Shorts/TikTok), `1:1`. |
| `scene` | A background picture or clip. A string (or `{"prompt": …}`) paints one in the art style's look: with the local painter installed (`extras` → `painter.ready`) Z-Image Turbo on this Mac, about 1.5–2 min; otherwise FLUX through Hugging Face, about 5 s. `"provider": "local"|"hf"|"free"` forces one. `{"file": "/abs/path.jpg"}` uses your own. Add `"animate": true` (optional `"motion": "slow camera drift"`, `"seconds": 3`) and Wan 2.2 turns the picture into a 2–5 s clip that loops behind the toon: needs the video extra and takes several minutes. `{"clip": "<file from options → clips>"}` reuses a clip. It sets `character.bg = "image"`. Describe a place, not people: the character stands in front of it. |
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

**Animated background:** `"scene": {"prompt": "rainy neon street, puddles reflecting signs", "animate": true, "seconds": 3}`. Only when the video extra is installed (`php "$APP/index.php" extras` → `video.ready`); add 5–10 minutes to the render.

**Realistic 3D presenter:** `"style": "real"`, `"character": {"avatar": "walter", "hairColor": "#9a9a9a", "bg": "news"}`, `"motion": {"camera": "medium"}`.
In this style, hair/top/pants/shoe colors, `acc` glasses or shades, `bg`, camera, subtitles and captions apply. Props, hats and the cartoon face fields are ignored. Skin keeps the person's own texture unless you set a `skin` color.

**A new 3D person** (when no existing one fits, e.g. "an older Asian woman in a suit"): write `person.json` and run `person`. It needs Blender installed. The first run installs the MPFB extension into Blender and downloads ~280 MB of CC0 assets, so give it a 15-minute timeout.
```json
{ "name": "Mei", "body": "woman", "age": 62, "build": "slim", "height": "short", "ethnicity": "asian", "tone": "auto",
  "hair": "bob01", "hairColor": "#9a9a9a", "eyebrows": "eyebrow005", "eyes": "brown", "outfit": "female_elegantsuit01", "shoes": "shoes06" }
```
Valid values are in `options` → `person_builder.options` (`body`: man, woman, neutral; `build`: slim, average, athletic, heavy; `ethnicity` (face shape): african, asian, caucasian, mixed; `tone` (skin): auto, light, medium, tan, brown, dark; `hairColor`: any `#rrggbb`, used automatically when the person plays a part; `hair`: short01–04, afro01, bob01/02, braid01, ponytail01, long01, none; `outfit`: male_casualsuit04 blue tee + jeans, 06 white tee + jeans, 02 long-sleeve tee + jeans, 01 navy shirt + dark jeans, 03 striped shirt + jeans, 05 jacket + jeans, male_elegantsuit01 black suit + tie, male_worksuit01 overalls, female_casualsuit01 fitted tee + jeans, female_casualsuit02 tee + denim shorts, female_elegantsuit01 blouse + skirt, female_sportsuit01 crop top + leggings; any outfit fits any body). The result's `avatar` is the id for `character.avatar`.

**A 3D person who looks like a real one** (the user as a presenter): add `"photo": "/abs/path/front.jpg"` to the person spec. The front photo's face is lined up with the built head (MediaPipe, on this Mac) and painted onto the skin: beard, mouth, nose and forehead come from the photo; the skin tone is still `tone`, so pick the closest one. Add `"glasses": "frames"` when they wear glasses: black frames are built in 3D and the eye area keeps the built skin, so frames and glare in the photo are not painted on. `"outfitColor": "#16161a"` recolors the whole outfit (one color for top and trousers), and `"replace": true` rebuilds the person of the same `name` in place (the previous file is kept in `avatars/.builds/<id>/previous.glb`). Use a sharp, evenly lit, front-facing photo with the mouth closed; side and back views are not used. It is a likeness on a MakeHuman head, not a scan: the head shape stays generic. Only for the user's own face or one they have permission for. Adds about 15 s (a minute the first time, while `uv` fetches MediaPipe); the result has `"likeness": true`. A person built with frames wears them by default in `make`; `"acc": []` in `character` takes them off.
```json
{ "name": "Lalo", "replace": true, "body": "man", "age": 43, "build": "average", "ethnicity": "mixed", "tone": "tan", "hair": "long01", "hairColor": "#15110f",
  "outfit": "male_casualsuit04", "outfitColor": "#16161a", "glasses": "frames", "photo": "/Users/me/Pictures/me-front.png" }
```

## Episode spec (`show`)

An episode is a list of scenes rendered in order and joined into one MP4 with crossfades (`"transition": "fade"`, default) or cuts (`"cut"`). Up to 12 scenes. Three kinds: a **title** card (`text`, `sub`, `seconds` 1–10, `color`, `textColor`), a **talk** scene (any `make` spec: `preset` or `character`, `style`, `name`, `script`, `voice`, `tts_voice`, `scene`, `captions`, `subtitles`, `motion`, `bgm`; `aspect` and `render` are ignored, and a lower-third with the character's name is added automatically), and a **clip** of B-roll (`prompt` and/or `image` = a scene file for Wan 2.2, or `clip` = an existing file from `options → clips`; `seconds` 1–6; needs the video extra). `kind` is guessed when missing: `script` → talk, `text` → title, else clip. Episode `bgm` is a compose prompt, a track name from `options → music`, or `{"file"|"prompt", "volume": 0.12}`; it runs under everything, ducked, and fades out.

```json
{ "title": "Local AI News, Ep. 1", "aspect": "16:9", "transition": "fade",
  "bgm": { "prompt": "calm newsroom underscore, soft keys", "volume": 0.12 },
  "scenes": [
    { "kind": "title", "text": "LOCAL AI NEWS", "sub": "Episode 1", "seconds": 3, "color": "#111118", "textColor": "#ffd23f" },
    { "kind": "talk", "preset": "anchor", "style": "comic", "name": "Ron Newsly", "tts_voice": "am_michael",
      "script": "Good evening. Tonight: a seven billion parameter model that runs on a laptop, and what it means for your desk.",
      "scene": "a newsroom at night, city lights through the window", "subtitles": { "style": "subtitle" }, "motion": { "camera": "medium" } },
    { "kind": "clip", "prompt": "slow camera drift through a dark server room, blinking lights", "seconds": 3 },
    { "kind": "talk", "preset": "hacker", "style": "ascii", "name": "Zero Cool", "tts_voice": "ollama/phonon:latest/Alfie",
      "script": "Here is the catch nobody mentions. It is fast because it is small, and small forgets things." }
  ] }
```

`php "$APP/index.php" show episode.json --wait` prints the status with `render_path` (the MP4, also in `renders/`) and per-scene states. Or `show --prompt "a 3-scene episode where a hacker and a news anchor argue about local AI" --wait` and the local Director writes the episode (`POST ?a=show_director` returns it as `{"episode": …}` to edit first). A title card plus two talk scenes takes about 90 s; B-roll adds minutes per clip. Research and write the scripts yourself for anything factual, as with `make`.

## Game spec (`game`)

A loose spec; anything missing is filled in, and a full editor `game.json` (from `?a=game_get`) is accepted too. `mold`: `platformer`, `topdown`, `shooter`. Cast members are `{"toon": "<preset or saved toon name>"}` or `{"buddy": "<a sentence describing a voxel buddy>", "name": …}`; enemies add `behavior` (`patrol`, `chase`, `fly`, `shoot`), the boss `hp`. `levels` is a count or a list (`name`, `bg` = any Toon Lab background name or a scene file, `length` short/medium/long, `difficulty` 1–3, `key`, `boss`). `rules`: `lives`, `difficulty` chill/normal/hard/brutal, `win` goal/coins/boss/survive, `timeLimit`. `sfx`: chip/arcade/soft/glitch. `music`: a compose prompt, `{"file": "<name from options → music>"}` or null. `voice.lines` (`start`, `win`, `lose`, `hurt`) are spoken in `voice.saved_voice` or the `tts_voice` reader.

```json
{ "title": "Penguin Run", "pitch": "An ice-cave dash with a crabby ending.", "mold": "platformer",
  "cast": { "hero": { "buddy": "a chonky space penguin with a crown", "name": "Pingu" },
            "enemies": [ { "buddy": "angry green slime", "name": "Slime", "behavior": "patrol" }, { "buddy": "cave bat", "name": "Bat", "behavior": "fly" } ],
            "boss": { "buddy": "giant lava crab boss", "name": "Crabzilla", "hp": 3 } },
  "levels": [ { "name": "Ice Caves", "bg": "space", "length": "short", "difficulty": 1 }, { "name": "The Pit", "bg": "hacker", "length": "medium", "difficulty": 2, "key": true, "boss": true } ],
  "rules": { "lives": 3, "difficulty": "normal", "win": "goal", "timeLimit": 0 },
  "sfx": "chip", "music": "bouncy chiptune, fast",
  "voice": { "saved_voice": "", "tts_voice": "am_puck", "lines": { "start": "Waddle fast, Pingu!", "win": "The crown is safe.", "lose": "Frozen solid.", "hurt": "Ouch." } } }
```

`php "$APP/index.php" game spec.json --wait` speaks the lines, draws the sprites in a hidden Chrome window and prints `game_id`, `path` (the playable `games/<id>/index.html`) and `play_url`. About 8 s for a 2-stage platformer with two voice lines. `game --prompt "a space penguin platformer with a crown boss" --wait` lets the Director design it (`{"prompt": …, "voice"?: …, "tts_voice"?: …}` as the body).

## Real Avatar spec (`real`)

The user themself: a green-screen clip they filmed once, placed over a screen recording (or a scene picture, a clip, or a solid color), saying a script in their own cloned voice, with burned-in subtitles and captions. The worker speaks the script, chains the footage's talking stretches with crossfades until they cover the voice, lip-syncs them with MuseTalk 1.5 when the engine is installed, keys the green out (ffmpeg chromakey + despill), composites, draws the subtitles and captions with Pillow, and mixes the sound. One MP4 in `renders/`, plus `reals/<id>/` with `synced.mp4`, diagnostic sheets and, on request, `avatar.webm` (the keyed user alone, with transparency).

**Footage must exist.** `options → footage` lists the user's clips (file → title; `GET ?a=footage` has each clip's index: `talk` and `idle` windows, `neutral` frames, the `key` color, a `face` box when the user ran *Analyze face*). Without `footage` the first clip is used; with none at all the request fails with "Upload a green-screen clip of yourself first". You can import a clip yourself by passing an absolute path as `footage` (and a recording or picture as `broll`): it is normalized, indexed and used. The user's own voice is the default here (the first saved voice), because that is the point of the room.

All fields are optional except `script` or `audio`. Invalid values fall back to defaults, as in `make`; an unknown `voice` is an error that lists the saved voices.

```json
{ "title": "Why I run AI locally", "aspect": "9:16",
  "footage": "lalo-greenscreen-test-27f3.mp4", "broll": "/Users/me/Desktop/demo.mov", "fit": "blur", "bgColor": "#101418",
  "script": "Okay, so this is the part everyone asks about. I recorded myself once, on a green screen, and now the app can put me on top of any screen recording and have me say whatever I type, in my own voice.",
  "voice": "lalo", "tts_voice": "am_michael", "speed": 1.0, "steps": 25,
  "avatar": { "corner": "bl", "size": 0.4, "margin": 0.035, "crop": 0.8, "mirror": false, "shape": "none", "res": 720 },
  "key": { "color": "#04ad3e", "similarity": 0.12, "blend": 0.08, "despill": 0.6 }, "lipsync": "auto",
  "subtitles": { "style": "karaoke", "font": "Impact", "size": 1, "color": "#ffffff", "hi": "#ffd23f", "pos": "auto", "box": true },
  "captions": [ { "kind": "title", "text": "LOCAL AI, NO CLOUD", "start": 0.3, "end": 3.5, "color": "#ffd23f", "font": "Impact" },
                { "kind": "lower", "text": "Lalo · built on a Mac mini", "start": 3.5, "end": 9 } ],
  "broll_audio": 0.12, "bgm": null, "export_avatar": false }
```

| Field | Meaning |
|---|---|
| `footage` | A file from `options → footage`, or an absolute path to a green-screen clip (imported and indexed first). Default: the first clip. |
| `broll` | The background: a file from `options → broll` (a screen recording), a clip from `options → clips`, a picture from `options → scenes`, or an absolute path to a video or a JPG/PNG/WebP (imported). Omit it for a solid `bgColor` (default `#101418`). `fit`: `blur` (fitted, with blurred edges; default), `cover` (fill), `contain` (fit). |
| `script` / `audio` | What the user says (about 2.6 words per second), or an absolute path to an audio file of them saying it; Whisper then finds the words for the subtitles. |
| `voice` | A saved voice name. **Omitted = the first saved voice** (unlike `make`); `""` = the AI reader only. `tts_voice` (pace and accent), `speed` (0.6–1.6) and `steps` (10–50) as in `make`. |
| `aspect` | `9:16` (default), `16:9`, `1:1`. |
| `avatar` | Where the user goes. `corner`: `bl` (default), `br`, `tl`, `tr`, `bc`, `c`. `size`: their height as a fraction of the frame height, 0.15–1 (default 0.4 for 9:16, 0.45 otherwise). `margin`: 0–0.2 of the height (0.035). `crop`: a portrait crop around the face, 0.3–1 of the footage width (0.8). `mirror`: flip them. `shape`: `none` or `circle` (a round bubble). `res`: the footage is scaled to this height before lip-sync and keying, 480–1080 (720; lower is faster). |
| `key` | Chroma key. `color` defaults to the color found when the clip was indexed; `similarity` 0.03–0.5 (0.12; 0.08–0.15 is right, higher eats the person); `blend` (soft edge) 0–0.5 (0.08); `despill` 0–1 (0.6). A blue screen works: a key color with more blue than green is keyed as blue. `mode`: `auto` (default), `chroma` or `difference`. `difference` is for a dim or unevenly lit screen, where the normal key leaves the person see-through or the wall grey; `auto` picks it when the key color is dark. |
| `lipsync` | `auto` (MuseTalk when installed, otherwise the footage as-is) or `off`. |
| `subtitles` | `style`: `karaoke` (default), `subtitle`, `pop`, `off`. `font`: one of `options → real_fonts` (Impact, Arial Black, Helvetica, Avenir Next Condensed, Futura, Phosphate, Chalkboard, Comic Sans MS, Trebuchet MS, Verdana, Copperplate, Luminari). `size` 0.5–2, `color`, `hi` (the karaoke highlight), `pos`: `auto` (next to the user: above them when they sit in the lower half, below when at the top), `top`, `middle`, `bottom`; `box`: a dark box behind the text (true). |
| `captions` | `kind`: `title` (big, at the top; default color `#ffd23f`) or `lower` (a lower-third on the side the user is not on; default `#e63946`); `text` (up to 80 characters), `start` and `end` in seconds, `color`, `font` (from `real_fonts`). |
| `broll_audio` | The recording's own sound under the voice, 0–1 (0.12). `bgm`: as in `make`: a compose prompt, `{"file": …}` or `{"prompt": …, "volume": 0.2}`. |
| `export_avatar` | `true` also writes `reals/<id>/avatar.webm`: the keyed user alone, VP9 with transparency, for other editors. |

`php "$APP/index.php" real spec.json --wait` prints the status: `render_path` (the MP4, also in `renders/`), `duration`, `lipsync` (`musetalk` or `passthrough`), `warning`, `audio_path`, `words` (count), `pauses`, `diagnostics` (`reals/<id>/avatar-frames.jpg` and `composite-frames.jpg`, served at `GET /reals/<id>/…`; look at them, or ask a vision model, when the user wants the key checked), `synced` and `avatar`. Measured on an M4 Mac mini: a 15 s video without lip-sync takes about 95 s; with MuseTalk add its own time (not measured yet). Give the command a 15-minute timeout.

**`lipsync: "passthrough"`** in the result means the engine is not installed: the video is the footage as-is and the mouth does not follow the words. Deliver the file, pass the `warning` on, and tell the user how to fix it: Showrunner → ⚙ Engines → *Install lip-sync* (~6 GB: the musetalk-mac port, a Python 3.11 venv, the MuseTalk 1.5 weights), or `php "$APP/index.php" extras install lipsync`; then re-run the same spec. `extras` → `lipsync.ready` tells you in advance.

**Script from notes**: `POST ?a=real_script` with `notes`, `seconds` (15–180), `tone` (`conversational`, `energetic`, `calm`, `funny`, `serious`) and `model` returns `{"script": …}` from the local model, or `ToonHouse().real_script(notes)`. As with `make`, prefer writing the script yourself when the content matters, as the user talking to a friend: first person, plain sentences, no headings.

**Recipe**: `python3 "$APP/recipes/real_avatar.py" --notes "…" | --script "…" --broll <file or path> --title "…" --lower "Name · line" [--voice NAME] [--footage FILE] [--aspect 9:16] [--corner bl] [--size 0.4] [--no-subs] [--export-avatar]` builds this spec, waits and prints `render_path` (the warning goes to stderr).

## HTTP API (for agents without a shell)

The server must be running. The user starts it with `php index.php`. Every action is `GET`/`POST ?a=<action>`; JSON bodies where noted, form fields otherwise; errors are `{"error": …}` with a non-2xx code. Status objects share `state` (`queued`, `running`, `done`, `error`, `cancelled`), `stage`, `progress` (0–1) and `error`.

- `POST http://127.0.0.1:8777/?a=make` with the spec as a JSON body returns `{"id": …}`. `GET ?a=make_status&id=<id>` adds `render_path`, `audio_path` and `toon_lab`.
- `POST ?a=show_make` with an episode JSON body (or `{"prompt": …}`) returns `{"id": …}`. `GET ?a=show_status&id=…` adds `title`, `render_path` and `scenes: [{kind, state, stage, progress, render_path?}]`. `GET ?a=shows`, `GET ?a=show_get&id=…` (the spec), `POST ?a=show_delete` `id`, `POST ?a=show_director` `prompt` → `{"episode": …}`.
- `POST ?a=game_make` with a game JSON body (or `{"prompt": …}`) returns `{"id": …}`. `GET ?a=game_status&id=…` adds `game_id`, `path` and `play_url` when done. `GET ?a=games`, `GET ?a=game_get&id=…`, `POST ?a=game_director` `prompt` → `{"plan": …}`.
- `POST ?a=real_make` with a Real Avatar JSON body returns `{"id": …}`. `GET ?a=real_status&id=…` → `{state, stage, progress, render, render_path, duration, lipsync: "musetalk"|"passthrough", warning, audio_path, words, pauses, diagnostics: [], avatar, synced}`. `GET ?a=reals`, `GET ?a=real_get&id=…` (the validated spec), `POST ?a=real_delete` `id`, `POST ?a=real_script` `notes, seconds, tone, model` → `{script}`.
- Footage (the user's green-screen clips): `GET ?a=footage` → `[{file, title, duration, index: {talk, idle, neutral, silences, key, face?, …}, preview, sheets}]`. `POST ?a=footage_upload` file field `video` → `{file, title, duration, job}`; poll `GET ?a=footage_status&id=<job>` until `done` (it adds `index`). `POST ?a=footage_index` `file, color?, similarity?, blend?, despill?` → `{job}` (re-key the preview); `POST ?a=footage_save_index` `file, index` (JSON) → `{ok, job}`; `POST ?a=footage_delete` `file`. Backgrounds the same way: `GET ?a=broll` (`index.corners` = an edge-energy score per corner, `index.recommend`, `sheet`), `POST ?a=broll_upload` (`video`), `GET ?a=broll_status&id=…`, `POST ?a=broll_delete`. The files are served at `GET /footage/<file>`, `/broll/<file>` and `/reals/<id>/<file>`.
- `GET ?a=vision_models` → `{models: [...]}` (Ollama models with vision). `POST ?a=vision_check` `image` (`footage/<x>.jpg`, `broll/<x>.jpg` or `reals/<id>/<x>.jpg`), `question`, `model?` → `{answer}`; 30–90 s. Useful on `avatar-frames.jpg` ("is any part of the person missing or green?") before delivering.
- `POST ?a=scene_gen` fields `prompt, style, aspect, provider=local|hf|free` → `{file, title}` for hf/free, or `{job}` for local: poll `GET ?a=scene_status&id=…` until `done` for `file`. `POST ?a=scene_upload` file field `image`. `GET ?a=scenes`.
- `POST ?a=clip_gen` fields `prompt, image?, seconds (2–5), aspect` → `{id, file}`; `GET ?a=clip_status&id=…`; `GET ?a=clips`; the bytes at `GET /clips/<file>`.
- `POST ?a=create` fields `text`, `tts_voice`, `tts_speed`, `mode=speech`, and `saved_voice=<file>` + `clean_voice=1` or `ai_voice=1` → `{ids: [id]}`; then `GET ?a=jobs` and find the id (`status.files.vocals` or `mix`), `GET ?a=file&id=…&f=<name>` for the MP3. This is what `speak` does.
- `POST ?a=music_gen` `prompt, seconds (4–30)` → `{id, file}`; `GET ?a=music_status&id=…`; `GET ?a=music`.
- `GET ?a=options` returns all options (`tts_voices`, `scenes`, `clips`, `music`, `scene_providers`, `painter`, `video`, `footage`, `broll`, `real_fonts`, `lipsync`, …). `GET ?a=extras` → `{painter: {ready, …}, video: {ready, checkpoint, …}, lipsync: {ready, …}, uv, free_gb}`; `POST ?a=extras_install` `what=painter|video|lipsync`, `checkpoint?`.
- `GET ?a=jobs_all` → every queue in one list `[{kind, id, title, state, stage, progress, time, result?}]`.
- `GET ?a=recipes` → `[{name, file, doc, cmd}]`; `POST ?a=recipe_run` `name, args?` → `{id}`; `GET ?a=recipe_status&id=…` → `{state, log}`.
- `POST ?a=person_photo` file field `image` → `{photo}`, a name to pass as `photo` (or pass an absolute path). `POST ?a=person_make` with a person JSON body returns `{"id": …}`; `GET ?a=person_status&id=…` returns `state`, `stage`, `progress` and `avatar`.
- `POST ?a=director` with form field `prompt` returns `{"spec": …}`, a spec written by the local LLM for you to edit before calling `make`.

## Troubleshooting

- **"Engine not installed yet"**: the user opens http://127.0.0.1:8777 once and clicks *Install engine*, or runs `php index.php setup`.
- **"No saved voice called …"**: the error lists the saved voices. The user records one on the Voice Swap page (step 1 → *Save voice as…*).
- **No Chrome found**: the project still finishes with audio and lip-sync. Give the user the `toon_lab` link and have them press *Record video*. Games and episodes need Chrome.
- **"The local painter is not installed yet"** / **"The video engine is not installed yet"**: the user opens Showrunner → ⚙ Engines and clicks *Install the painter* (~7 GB) or *Install video* (Wan 2.2, 32 GB download or an existing checkpoint folder). Until then use `"provider": "hf"` or `"free"` for pictures and drop `animate` / clip scenes.
- **A `tts_voice` starting with `ollama/` is rejected or missing from `options`**: the model isn't pulled (`ollama pull phonon`) or Ollama isn't running.
- **"Upload a green-screen clip of yourself first"** (`real`): there is no footage. The user drops a clip on the Real Avatar page, or you pass an absolute path to one as `footage` and it is imported and indexed.
- **`lipsync: "passthrough"` with a `warning`** (`real`): MuseTalk isn't installed, so the video is the footage as-is. Showrunner → ⚙ Engines → *Install lip-sync*, or `php "$APP/index.php" extras install lipsync`, then re-run the spec.
- Renders land in `$APP/renders/`, episodes also in `$APP/shows/<id>/`, Real Avatar videos also in `$APP/reals/<id>/` (footage in `$APP/footage/`, screen recordings in `$APP/broll/`), games in `$APP/games/<id>/`, clips in `$APP/clips/`, pictures in `$APP/scenes/`, speech jobs in `$APP/jobs/`, and project specs in `$APP/projects/`.
