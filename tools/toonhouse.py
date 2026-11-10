#!/usr/bin/env python3
"""Python client for the Toon House local server (http://127.0.0.1:8777).

Stdlib only (urllib, json, subprocess, time, pathlib); Python 3.9+.

Quickstart::

    from toonhouse import ToonHouse
    th = ToonHouse()                      # starts `php index.php` if the port is closed
    th.speak("Hello from Python.")        # -> {"id": ..., "mp3": "/abs/path.mp3", ...}
    th.make({"preset": "hacker", "style": "ascii", "script": "Local AI is here."})
    th.paint("a newsroom at night", style="comic")       # -> scene file name
    th.game({"prompt": "a space penguin platformer"})    # -> {"play_url": ..., "path": ...}
    th.real({"script": "Hi, it's me.", "broll": "/abs/demo.mov"})   # you on green screen -> {"render_path": ..., "lipsync": ...}

Command line::

    python3 tools/toonhouse.py speak "Hello" --reader am_michael
    python3 tools/toonhouse.py make spec.json
    python3 tools/toonhouse.py paint "a newsroom at night" --style comic
    python3 tools/toonhouse.py real spec.json         # a Real Avatar video (you over a screen recording)
    python3 tools/toonhouse.py footage --json         # your green-screen clips; broll: screen recordings
    python3 tools/toonhouse.py status <id>            # any kind of job
    python3 tools/toonhouse.py jobs --json

Every action is `GET/POST ?a=<action>` returning JSON. Errors are
`{"error": "..."}` with a non-2xx code and raise :class:`ToonHouseError`.
Long jobs are polled with :meth:`ToonHouse.wait`; progress goes to stderr,
results to stdout, so the CLI pipes cleanly.
"""

import argparse
import json
import mimetypes
import os
import random
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

__all__ = ["ToonHouse", "ToonHouseError", "FINAL_STATES", "main"]

#: Job states that end polling.
FINAL_STATES = ("done", "error", "cancelled")

_STATUS_ACTIONS = {
    "render": "make_status",
    "scene": "scene_status",
    "music": "music_status",
    "clip": "clip_status",
    "person": "person_status",
    "game": "game_status",
    "show": "show_status",
    "recipe": "recipe_status",
    "real": "real_status",
    "footage": "footage_status",
    "broll": "broll_status",
}


class ToonHouseError(Exception):
    """Raised when the server answers `{"error": ...}`, a non-2xx code, or a job fails."""


def _port_open(host, port, timeout=0.5):
    """Return True if something is listening on host:port."""
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


def _encode_multipart(fields=None, files=None):
    """Hand-rolled multipart/form-data encoder.

    `fields` is a dict of str -> str/number/bool/list (lists repeat the key,
    e.g. ``{"songs[]": [path1, path2]}`` for file lists). `files` is a dict of
    field name -> path (or list of paths). Returns (body_bytes, content_type).
    """
    boundary = "----ToonHouse%016x" % random.getrandbits(64)
    out = []
    for name, value in (fields or {}).items():
        if value is None:
            continue
        values = value if isinstance(value, (list, tuple)) else [value]
        for v in values:
            if isinstance(v, bool):
                v = "1" if v else "0"
            elif isinstance(v, (dict, list)):
                v = json.dumps(v)
            out.append(("--%s\r\n" % boundary).encode())
            out.append(('Content-Disposition: form-data; name="%s"\r\n\r\n' % name).encode())
            out.append(str(v).encode("utf-8"))
            out.append(b"\r\n")
    for name, paths in (files or {}).items():
        if paths is None:
            continue
        for p in (paths if isinstance(paths, (list, tuple)) else [paths]):
            p = Path(p)
            ctype = mimetypes.guess_type(p.name)[0] or "application/octet-stream"
            out.append(("--%s\r\n" % boundary).encode())
            out.append(('Content-Disposition: form-data; name="%s"; filename="%s"\r\n'
                        % (name, p.name.replace('"', "'"))).encode("utf-8"))
            out.append(("Content-Type: %s\r\n\r\n" % ctype).encode())
            out.append(p.read_bytes())
            out.append(b"\r\n")
    out.append(("--%s--\r\n" % boundary).encode())
    return b"".join(out), "multipart/form-data; boundary=%s" % boundary


class ToonHouse:
    """Client for one Toon House server.

    Args:
        base: server URL, default ``http://127.0.0.1:8777``.
        app_dir: folder containing ``index.php``; defaults to the parent of
            the ``tools/`` folder this file lives in. Used to start the server
            and to resolve relative paths the server returns.
        verbose: print ``NN% stage`` progress lines to stderr while waiting.
        autostart: call :meth:`ensure_server` on construction.
    """

    def __init__(self, base="http://127.0.0.1:8777", app_dir=None, verbose=True, autostart=True):
        self.base = base.rstrip("/")
        self.app_dir = Path(app_dir) if app_dir else Path(__file__).resolve().parents[1]
        self.verbose = verbose
        u = urllib.parse.urlparse(self.base)
        self._host, self._port = u.hostname or "127.0.0.1", u.port or 80
        self._options = None
        if autostart:
            self.ensure_server()

    # ------------------------------------------------------------------ server

    def log(self, msg):
        """Print a progress line to stderr when verbose."""
        if self.verbose:
            sys.stderr.write(str(msg) + "\n")
            sys.stderr.flush()

    def ensure_server(self, timeout=15.0):
        """Make sure the server answers ``?a=engine``; start it detached if not.

        Starts ``php -d upload_max_filesize=1G -d post_max_size=1G -S host:port
        index.php`` with nohup in ``app_dir`` (stdout to ``app_dir/server.log``)
        and waits up to `timeout` seconds. Returns the engine dict.
        """
        if _port_open(self._host, self._port):
            try:
                return self.get("engine")
            except ToonHouseError:
                pass
        index = self.app_dir / "index.php"
        if not index.exists():
            raise ToonHouseError("Toon House server is not running and %s does not exist" % index)
        self.log("starting Toon House server in %s ..." % self.app_dir)
        logf = open(self.app_dir / "server.log", "ab")
        cmd = ["nohup", "php", "-d", "upload_max_filesize=1G", "-d", "post_max_size=1G",
               "-S", "%s:%d" % (self._host, self._port), "index.php"]
        subprocess.Popen(cmd, cwd=str(self.app_dir), stdout=logf, stderr=subprocess.STDOUT,
                         stdin=subprocess.DEVNULL, start_new_session=True)
        deadline = time.time() + timeout
        while time.time() < deadline:
            if _port_open(self._host, self._port):
                try:
                    return self.get("engine")
                except ToonHouseError:
                    pass
            time.sleep(0.4)
        raise ToonHouseError("server did not answer on %s within %.0fs (see %s)"
                             % (self.base, timeout, self.app_dir / "server.log"))

    # --------------------------------------------------------------- transport

    def _url(self, action, params=None):
        q = {"a": action}
        for k, v in (params or {}).items():
            if v is None:
                continue
            q[k] = "1" if v is True else ("0" if v is False else str(v))
        return "%s/?%s" % (self.base, urllib.parse.urlencode(q))

    def _request(self, url, data=None, headers=None, timeout=600, raw=False):
        req = urllib.request.Request(url, data=data, headers=headers or {}, method="POST" if data is not None else "GET")
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                body = r.read()
        except urllib.error.HTTPError as e:
            body = e.read()
            try:
                msg = json.loads(body.decode("utf-8", "replace")).get("error") or body[:200]
            except Exception:
                msg = body[:200].decode("utf-8", "replace") if body else e.reason
            raise ToonHouseError("%s (HTTP %d, %s)" % (msg, e.code, url)) from None
        except urllib.error.URLError as e:
            raise ToonHouseError("cannot reach %s: %s" % (url, e.reason)) from None
        if raw:
            return body
        try:
            out = json.loads(body.decode("utf-8"))
        except ValueError:
            raise ToonHouseError("non-JSON answer from %s: %r" % (url, body[:200])) from None
        if isinstance(out, dict) and out.get("error"):
            raise ToonHouseError(str(out["error"]))
        return out

    def get(self, action, **params):
        """``GET ?a=<action>&k=v...`` -> parsed JSON. Raises ToonHouseError on error."""
        return self._request(self._url(action, params))

    def get_bytes(self, action, **params):
        """``GET ?a=<action>...`` -> raw bytes (for MP3/MP4 downloads)."""
        return self._request(self._url(action, params), raw=True)

    def post(self, action, fields=None, files=None, json_body=None, timeout=600):
        """``POST ?a=<action>``.

        With `json_body`: the body is JSON with ``Content-Type: application/json``.
        Otherwise `fields` (+ optional `files`: name -> path or [paths]) are
        sent as multipart/form-data. Returns parsed JSON; raises ToonHouseError
        on ``{"error": ...}`` or a non-2xx code.
        """
        url = self._url(action)
        if json_body is not None:
            data = json.dumps(json_body).encode("utf-8")
            headers = {"Content-Type": "application/json"}
        else:
            data, ctype = _encode_multipart(fields or {}, files)
            headers = {"Content-Type": ctype}
        headers["Content-Length"] = str(len(data))
        return self._request(url, data=data, headers=headers, timeout=timeout)

    def download(self, url_or_action, dest, **params):
        """Save ``GET ?a=<action>...`` (or an absolute/relative URL) to `dest`. Returns the Path."""
        if url_or_action.startswith("http") or url_or_action.startswith("/"):
            url = url_or_action if url_or_action.startswith("http") else self.base + url_or_action
            body = self._request(url, raw=True)
        else:
            body = self.get_bytes(url_or_action, **params)
        dest = Path(dest)
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(body)
        return dest

    # ----------------------------------------------------------------- polling

    def wait(self, status_fn, timeout=3600, on_progress=None, interval=1.5):
        """Poll `status_fn()` every `interval` s until ``state`` is done/error/cancelled.

        `status_fn` returns a status dict (``state, stage, progress, error?``).
        `on_progress(status)` is called on each poll; when it is None and
        ``verbose`` is set, ``NN% stage`` lines are printed to stderr when they
        change. Returns the final status; raises ToonHouseError on
        error/cancelled or when `timeout` seconds pass.
        """
        deadline = time.time() + timeout
        last = None
        while True:
            st = status_fn() or {}
            state = st.get("state")
            if on_progress:
                on_progress(st)
            elif self.verbose:
                pct = int(round(float(st.get("progress") or 0) * 100))
                line = "%3d%% %s" % (pct, st.get("stage") or state or "")
                if line != last:
                    self.log(line)
                    last = line
            if state in FINAL_STATES:
                if state != "done":
                    raise ToonHouseError(st.get("error") or ("job %s" % state))
                return st
            if time.time() > deadline:
                raise ToonHouseError("timed out after %.0fs (last stage: %s)" % (timeout, st.get("stage")))
            time.sleep(interval)

    def status(self, kind, id):
        """Status dict for a job of `kind` (voice, render, scene, music, clip, person, game, show, recipe, real, footage, broll)."""
        if kind == "voice":
            return self.job(id)["status"]
        act = _STATUS_ACTIONS.get(kind)
        if not act:
            raise ToonHouseError("unknown job kind %r" % kind)
        return self.get(act, id=id)

    def status_any(self, id):
        """Find a job by id across every kind: try jobs_all, then each status action."""
        try:
            for j in self.jobs_all():
                if j.get("id") == id:
                    return j
        except ToonHouseError:
            pass
        for act in _STATUS_ACTIONS.values():
            try:
                st = self.get(act, id=id)
                if isinstance(st, dict) and st.get("state"):
                    st.setdefault("id", id)
                    return st
            except ToonHouseError:
                continue
        try:
            j = self.job(id)
            st = dict(j["status"])
            st.update({"id": id, "kind": "voice", "title": j.get("job", {}).get("title")})
            return st
        except ToonHouseError:
            pass
        raise ToonHouseError("no job with id %s" % id)

    # ----------------------------------------------------------------- lookups

    def options(self, refresh=False):
        """``?a=options``: presets, character options, voices, fonts, aspects, scenes, music, avatars, person_builder (cached)."""
        if self._options is None or refresh:
            self._options = self.get("options")
        return self._options

    def voices(self):
        """Saved (cloned) voices: ``[{name, file}]``."""
        return self.get("voices")

    def voice_file(self, name):
        """Resolve a saved voice name (or file) to its file name; raises if unknown."""
        if not name:
            return None
        vs = self.voices()
        for v in vs:
            if v.get("name") == name or v.get("file") == name:
                return v["file"]
        for v in vs:
            if str(v.get("name", "")).lower() == str(name).lower():
                return v["file"]
        raise ToonHouseError("no saved voice called %r (have: %s)" % (name, ", ".join(v["name"] for v in vs) or "none"))

    def jobs(self):
        """Voice Swap jobs (``?a=jobs``), newest first."""
        return self.get("jobs")

    def job(self, id):
        """One Voice Swap job ``{id, job, status, log}`` by id (``?a=jobs`` filtered client-side)."""
        for j in self.jobs():
            if j.get("id") == id:
                return j
        raise ToonHouseError("no voice job %s" % id)

    def jobs_all(self):
        """Every job of every kind (``?a=jobs_all``), newest first."""
        return self.get("jobs_all")

    def renders(self):
        """Rendered MP4s in renders/ (``?a=renders``)."""
        return self.get("renders")

    def games(self):
        """Saved games (``?a=games``)."""
        return self.get("games")

    def shows(self):
        """Episodes (``?a=shows``)."""
        return self.get("shows")

    def scenes(self):
        """Scene pictures (``?a=scenes``)."""
        return self.get("scenes")

    def tracks(self):
        """Music tracks: the ``tracks`` list of ``?a=music``."""
        m = self.get("music")
        return m.get("tracks", m) if isinstance(m, dict) else m

    def clips(self):
        """Video clips (``?a=clips``)."""
        return self.get("clips")

    def chars(self):
        """Saved toons (``?a=chars``)."""
        return self.get("chars")

    def people(self):
        """3D avatars (``?a=people``)."""
        return self.get("people")

    def footage(self):
        """Green-screen clips of you for Real Avatar (``?a=footage``): ``[{file, title, duration, index, preview, sheets}]``."""
        return self.get("footage")

    def broll(self):
        """Screen recordings and other background videos for Real Avatar (``?a=broll``): ``[{file, title, duration, index, sheet}]``."""
        return self.get("broll")

    def reals(self):
        """Real Avatar videos (``?a=reals``), newest first, each with its status."""
        return self.get("reals")

    def vision_models(self):
        """Ollama models that can look at pictures (``?a=vision_models``)."""
        return self.get("vision_models").get("models", [])

    def extras(self):
        """Optional engines: ``{painter: {...}, video: {...}, lipsync: {...}, uv, free_gb}``."""
        return self.get("extras")

    def extras_install(self, what, checkpoint=None):
        """Install an optional engine: `what` is ``painter``, ``video`` or ``lipsync``; `checkpoint` is a Wan2.2 folder."""
        return self.post("extras_install", {"what": what, "checkpoint": checkpoint})

    def news(self, q):
        """This week's news stories on `q`: ``[{title, source, date, url, body}]``."""
        return self.get("news", q=q).get("stories", [])

    def director(self, prompt, model=None):
        """Ask the local Director for a Toon Lab spec from one sentence. Returns the spec dict."""
        return self.post("director", {"prompt": prompt, "model": model}).get("spec")

    def game_director(self, prompt, model=None):
        """Ask the Game Director for a game plan. Returns the plan dict."""
        return self.post("game_director", {"prompt": prompt, "model": model}).get("plan")

    def show_director(self, prompt, model=None):
        """Ask the Show Director for an episode. Returns the episode dict."""
        return self.post("show_director", {"prompt": prompt, "model": model}).get("episode")

    # ------------------------------------------------------------------- voice

    def speak(self, text, voice=None, reader="am_michael", speed=1.0, wait=True, out=None, timeout=1800):
        """Read `text` out loud; returns ``{id, status, file, mp3}``.

        `voice` None -> AI reader only (``ai_voice=1``, fast). A saved voice
        name -> read then converted into that cloned voice (``saved_voice``).
        `reader` is the Kokoro / ollama TTS id, `speed` its pace.
        With `wait`, the MP3 is downloaded to `out` (default
        ``jobs/<id>/<name>.mp3`` under app_dir, i.e. the server's own copy when
        local) and its path is in ``mp3``.
        """
        fields = {"text": text, "mode": "speech", "tts_voice": reader, "tts_speed": speed}
        if voice:
            fields["saved_voice"] = self.voice_file(voice)
            fields["clean_voice"] = 1
        else:
            fields["ai_voice"] = 1
        ids = self.post("create", fields).get("ids") or []
        if not ids:
            raise ToonHouseError("create returned no job id")
        res = {"id": ids[0]}
        if not wait:
            return res
        st = self.wait(lambda: self.job(ids[0])["status"], timeout=timeout)
        return self._voice_result(ids[0], st, out)

    def _voice_result(self, id, st, out=None, prefer=("mix", "vocals")):
        files = st.get("files") or {}
        name = next((files[k] for k in prefer if files.get(k)), None)
        res = {"id": id, "status": st, "files": files, "file": name}
        if name:
            local = self.app_dir / "jobs" / id / name
            if out:
                res["mp3"] = str(self.download("file", out, id=id, f=name))
            elif local.exists():
                res["mp3"] = str(local)
            else:
                res["mp3"] = str(self.download("file", Path.cwd() / name, id=id, f=name))
        return res

    def convert_song(self, path, voice, separate=True, steps=25, auto_octave=True, semitones=0,
                     similarity=0.7, vocal_vol=100, music_vol=100, orig_vol=0, clean_voice=True,
                     wait=True, out=None, timeout=7200):
        """Re-sing a song in a saved voice. Returns ``{id, status, files, file, mp3}``.

        `path` is the song (mp3/wav/m4a/flac/mp4). `voice` a saved voice name.
        `separate` when the song has music (split first). With `wait` the mix is
        downloaded to `out` (default: the server's own jobs/<id> copy).
        """
        fields = {
            "saved_voice": self.voice_file(voice), "clean_voice": clean_voice, "mode": "sing",
            "steps": int(steps), "separate": separate, "auto_octave": auto_octave, "semitones": semitones,
            "similarity": similarity, "vocal_vol": vocal_vol, "music_vol": music_vol, "orig_vol": orig_vol,
        }
        ids = self.post("create", fields, files={"songs[]": [path]}, timeout=1800).get("ids") or []
        if not ids:
            raise ToonHouseError("create returned no job id")
        if not wait:
            return {"id": ids[0]}
        st = self.wait(lambda: self.job(ids[0])["status"], timeout=timeout)
        return self._voice_result(ids[0], st, out)

    def voice_file_url(self, id, name):
        """URL of a Voice Swap result file."""
        return self._url("file", {"id": id, "f": name})

    # ---------------------------------------------------------------- toon lab

    def make(self, spec, wait=True, timeout=3600):
        """Render a Toon Lab spec (see skills/toon-studio/SKILL.md).

        Returns the status dict with ``render_path`` (MP4), ``audio_path``,
        ``toon_lab`` and ``id``. With ``wait=False`` returns ``{id, spec, renderer}``.
        """
        r = self.post("make", json_body=spec)
        if not wait:
            return r
        st = self.wait(lambda: self.get("make_status", id=r["id"]), timeout=timeout)
        st.setdefault("id", r["id"])
        return st

    def make_status(self, id):
        """``?a=make_status``."""
        return self.get("make_status", id=id)

    def paint(self, prompt, style="classic", aspect="16:9", provider="local", wait=True, timeout=1800):
        """Paint a scene picture. Returns the scene file name (in scenes/).

        ``provider="local"`` answers ``{job}`` and is polled with
        ``scene_status``; ``hf`` and ``free`` answer with the file directly.
        With ``wait=False`` on local, returns the raw ``{job}`` dict.
        """
        r = self.post("scene_gen", {"prompt": prompt, "style": style, "aspect": aspect, "provider": provider})
        if r.get("file"):
            return r["file"]
        job = r.get("job") or r.get("id")
        if not job:
            raise ToonHouseError("scene_gen returned neither file nor job: %r" % r)
        if not wait:
            return r
        st = self.wait(lambda: self.get("scene_status", id=job), timeout=timeout)
        if not st.get("file"):
            raise ToonHouseError("scene job finished without a file: %r" % st)
        return st["file"]

    def scene_upload(self, path):
        """Upload a JPG/PNG/WebP as a scene. Returns ``{file, title}``."""
        return self.post("scene_upload", files={"image": path})

    def scene_path(self, file):
        """Absolute path of a scene file under app_dir/scenes."""
        return self.app_dir / "scenes" / file

    def music(self, prompt, seconds=20, wait=True, timeout=1800):
        """Compose a music track with MusicGen. Returns the track file name (in music/)."""
        r = self.post("music_gen", {"prompt": prompt, "seconds": int(max(4, min(30, seconds)))})
        if not wait:
            return r
        st = self.wait(lambda: self.get("music_status", id=r["id"]), timeout=timeout)
        return st.get("file") or r.get("file")

    def music_upload(self, path):
        """Upload an MP3/WAV as a music track. Returns ``{file, title}``."""
        return self.post("music_upload", files={"audio": path})

    def clip(self, prompt, image=None, seconds=3, aspect="16:9", steps=None, wait=True, timeout=7200):
        """Make a short video clip with Wan 2.2 (slow: minutes). Returns the clip file name.

        `image` is an optional scene file name to animate. Bytes are at
        ``GET /clips/<file>``; see :meth:`clip_path`.
        """
        fields = {"prompt": prompt, "image": image, "seconds": int(max(2, min(5, seconds))), "aspect": aspect, "steps": steps}
        r = self.post("clip_gen", fields)
        if not wait:
            return r
        st = self.wait(lambda: self.get("clip_status", id=r["id"]), timeout=timeout)
        return st.get("file") or r.get("file")

    def clip_path(self, file):
        """Absolute path of a clip under app_dir/clips."""
        return self.app_dir / "clips" / file

    def person(self, spec, wait=True, timeout=1800):
        """Build a realistic 3D person in Blender. Returns the status with ``avatar`` (the id)."""
        r = self.post("person_make", json_body=spec)
        if not wait:
            return r
        st = self.wait(lambda: self.get("person_status", id=r["id"]), timeout=timeout)
        st.setdefault("id", r["id"])
        return st

    # ------------------------------------------------------------------- games

    def game(self, spec_or_prompt, wait=True, timeout=3600, **kw):
        """Build a playable game. `spec_or_prompt` is a game spec dict or a one-line prompt.

        Extra keywords (``voice``, ``tts_voice``, ``model``) go into a prompt
        body. Returns the status with ``game_id``, ``path`` and ``play_url``.
        """
        body = {"prompt": spec_or_prompt} if isinstance(spec_or_prompt, str) else dict(spec_or_prompt)
        body.update({k: v for k, v in kw.items() if v is not None})
        r = self.post("game_make", json_body=body)
        if not wait:
            return r
        st = self.wait(lambda: self.get("game_status", id=r["id"]), timeout=timeout)
        st.setdefault("id", r["id"])
        if st.get("play_url") and st["play_url"].startswith("/"):
            st["play_url"] = self.base + st["play_url"]
        return st

    def game_get(self, id):
        """A saved game's game.json."""
        return self.get("game_get", id=id)

    # ------------------------------------------------------------------- shows

    def show(self, episode_or_prompt, wait=True, timeout=7200, **kw):
        """Render a multi-scene episode. `episode_or_prompt` is an episode dict or a prompt string.

        Returns the status with ``render_path`` and per-scene states.
        """
        body = {"prompt": episode_or_prompt} if isinstance(episode_or_prompt, str) else dict(episode_or_prompt)
        body.update({k: v for k, v in kw.items() if v is not None})
        r = self.post("show_make", json_body=body)
        if not wait:
            return r
        st = self.wait(lambda: self.get("show_status", id=r["id"]), timeout=timeout)
        st.setdefault("id", r["id"])
        return st

    def show_get(self, id):
        """An episode's spec."""
        return self.get("show_get", id=id)

    # ------------------------------------------------------------- real avatar

    def real(self, spec, wait=True, timeout=3600):
        """Render a Real Avatar spec (see skills/toon-studio/SKILL.md, "Real Avatar spec").

        Your green-screen footage, lip-synced to ``script`` in your own voice,
        over ``broll``. Returns the status dict with ``render_path`` (MP4),
        ``duration``, ``lipsync`` (``musetalk``, or ``passthrough`` with a
        ``warning`` when the engine is not installed), ``diagnostics``,
        ``synced``, ``avatar`` and ``id``. With ``wait=False`` returns ``{id}``.
        """
        r = self.post("real_make", json_body=spec)
        if not wait:
            return r
        st = self.wait(lambda: self.get("real_status", id=r["id"]), timeout=timeout)
        st.setdefault("id", r["id"])
        return st

    def real_status(self, id):
        """``?a=real_status``."""
        return self.get("real_status", id=id)

    def real_get(self, id):
        """A Real Avatar video's validated spec (``?a=real_get``)."""
        return self.get("real_get", id=id)

    def real_delete(self, id):
        """Delete a Real Avatar video's folder in reals/ (its MP4 in renders/ stays)."""
        return self.post("real_delete", {"id": id})

    def real_dir(self, id):
        """Absolute path of a Real Avatar video's folder (synced.mp4, diagnostics, avatar.webm)."""
        return self.app_dir / "reals" / id

    def real_script(self, notes, seconds=45, tone="conversational", model=None):
        """Rough notes -> what you say to camera, written by the local model. Returns the script text.

        `seconds` (15-180) sets the length at about 2.6 words per second;
        `tone` is conversational, energetic, calm, funny or serious.
        """
        return self.post("real_script", {"notes": notes, "seconds": int(seconds), "tone": tone, "model": model}).get("script")

    def _video_upload(self, kind, path, wait, timeout):
        r = self.post(kind + "_upload", files={"video": path}, timeout=1800)
        if not wait or not r.get("job"):
            return r
        st = self.wait(lambda: self.get(kind + "_status", id=r["job"]), timeout=timeout)
        r["index"] = st.get("index")
        return r

    def footage_upload(self, path, wait=True, timeout=1800):
        """Upload a green-screen clip of yourself as Real Avatar footage (file field ``video``).

        The server re-encodes it (25 fps, mono) into footage/ and indexes it in
        the background: pauses, the screen color, a keyed preview and contact
        sheets. Returns ``{file, title, duration, job}``; with `wait` the index
        job is followed (``footage_status``) and ``index`` is added. The face
        analysis (talking stretches, neutral frames, face box) runs in the
        browser on the Real Avatar page; a spec works without it.
        """
        return self._video_upload("footage", path, wait, timeout)

    def footage_index(self, file, color=None, similarity=None, blend=None, despill=None, wait=True, timeout=900):
        """Re-index a footage file with new chroma-key settings. Returns the index (``{job}`` with wait=False)."""
        r = self.post("footage_index", {"file": file, "color": color, "similarity": similarity, "blend": blend, "despill": despill})
        if not wait:
            return r
        return self.wait(lambda: self.get("footage_status", id=r["job"]), timeout=timeout).get("index")

    def footage_delete(self, file):
        """Delete a footage file with its index, preview and sheets."""
        return self.post("footage_delete", {"file": file})

    def footage_path(self, file):
        """Absolute path of a footage file (or one of its sheets) under app_dir/footage."""
        return self.app_dir / "footage" / file

    def broll_upload(self, path, wait=True, timeout=1800):
        """Upload a screen recording (or any video) as a Real Avatar background (file field ``video``).

        Returns ``{file, title, duration, job}``; with `wait`, ``index`` is
        added: ``corners`` (an edge-energy score per corner, lower is cleaner),
        ``recommend`` (the corner to put yourself in) and ``sheet``.
        """
        return self._video_upload("broll", path, wait, timeout)

    def broll_delete(self, file):
        """Delete a B-roll file with its index and sheet."""
        return self.post("broll_delete", {"file": file})

    def broll_path(self, file):
        """Absolute path of a B-roll file (or its frames sheet) under app_dir/broll."""
        return self.app_dir / "broll" / file

    def vision_check(self, image, question="Describe this picture.", model=None):
        """Ask a local Ollama vision model about a picture; returns its answer (30-90 s).

        `image` is a path relative to the app: ``footage/<x>.jpg``,
        ``broll/<x>.jpg`` or ``reals/<id>/<x>.jpg`` (an absolute path under
        app_dir is accepted too). `model` defaults to the first vision model.
        """
        image, root = str(image), str(self.app_dir)
        if image.startswith(root):
            image = image[len(root):].lstrip("/")
        return self.post("vision_check", {"image": image, "question": question, "model": model}, timeout=600).get("answer")

    # ----------------------------------------------------------------- recipes

    def recipes(self):
        """Recipes the server knows: ``[{name, file, doc, cmd}]``."""
        return self.get("recipes")

    def recipe_run(self, name, args="", wait=True, timeout=7200):
        """Run a recipe server-side; returns ``{state, log}`` when done (or ``{id}`` with wait=False)."""
        r = self.post("recipe_run", {"name": name, "args": args})
        if not wait:
            return r
        return self.wait(lambda: self.get("recipe_status", id=r["id"]), timeout=timeout)


# ---------------------------------------------------------------------- CLI

def _load_spec(arg):
    """A CLI spec argument: a JSON file path, '-' for stdin, or inline JSON."""
    if arg == "-":
        return json.load(sys.stdin)
    p = Path(arg)
    if p.exists():
        return json.loads(p.read_text())
    try:
        return json.loads(arg)
    except ValueError:
        raise SystemExit("spec must be a JSON file, '-' or inline JSON: %r" % arg)


def _emit(result, as_json, plain=None):
    if as_json or plain is None:
        print(json.dumps(result, indent=2, ensure_ascii=False))
    else:
        print(plain)


def main(argv=None):
    """CLI entry point: ``python3 tools/toonhouse.py <command> [args] [--json]``."""
    ap = argparse.ArgumentParser(prog="toonhouse", description=__doc__.split("\n\n")[0])
    ap.add_argument("--base", default=os.environ.get("TOONHOUSE_URL", "http://127.0.0.1:8777"))
    ap.add_argument("--app-dir", default=os.environ.get("TOON_STUDIO"))
    ap.add_argument("--json", action="store_true", help="print the raw JSON result")
    ap.add_argument("-q", "--quiet", action="store_true", help="no progress on stderr")
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("speak", help="read text aloud (AI reader or a saved voice)")
    p.add_argument("text", help="text, or @file.txt")
    p.add_argument("--voice", help="saved voice name (default: AI reader only)")
    p.add_argument("--reader", default="am_michael")
    p.add_argument("--speed", type=float, default=1.0)
    p.add_argument("--out", help="where to save the MP3")

    p = sub.add_parser("make", help="render a Toon Lab spec (JSON file, '-' or inline) or --prompt")
    p.add_argument("spec", nargs="?")
    p.add_argument("--prompt", help="let the local Director write the spec")
    p.add_argument("--no-wait", action="store_true")

    p = sub.add_parser("paint", help="paint a scene picture")
    p.add_argument("prompt")
    p.add_argument("--style", default="classic")
    p.add_argument("--aspect", default="16:9")
    p.add_argument("--provider", default="local", choices=["local", "hf", "free"])

    p = sub.add_parser("clip", help="make a short video clip (Wan 2.2)")
    p.add_argument("prompt")
    p.add_argument("--image", help="scene file to animate")
    p.add_argument("--seconds", type=int, default=3)
    p.add_argument("--aspect", default="16:9")

    p = sub.add_parser("music", help="compose a music track")
    p.add_argument("prompt")
    p.add_argument("--seconds", type=int, default=20)

    p = sub.add_parser("game", help="build a game from a spec file or --prompt")
    p.add_argument("spec", nargs="?")
    p.add_argument("--prompt")
    p.add_argument("--voice")
    p.add_argument("--no-wait", action="store_true")

    p = sub.add_parser("show", help="render an episode from a file or --prompt")
    p.add_argument("spec", nargs="?")
    p.add_argument("--prompt")
    p.add_argument("--no-wait", action="store_true")

    p = sub.add_parser("real", help="render a Real Avatar spec: you (green-screen footage) over a screen recording")
    p.add_argument("spec", nargs="?")
    p.add_argument("--no-wait", action="store_true")

    p = sub.add_parser("person", help="build a 3D person from a person JSON")
    p.add_argument("spec")

    p = sub.add_parser("status", help="status of any job id (optionally wait)")
    p.add_argument("id")
    p.add_argument("--kind", choices=["voice"] + sorted(_STATUS_ACTIONS))
    p.add_argument("--wait", action="store_true")

    sub.add_parser("jobs", help="every job of every kind")
    sub.add_parser("options", help="all options as JSON")
    sub.add_parser("voices", help="saved voices")
    sub.add_parser("renders", help="rendered MP4s")
    p = sub.add_parser("footage", help="green-screen clips for Real Avatar (or --upload one)")
    p.add_argument("--upload", metavar="VIDEO", help="upload and index a clip of yourself")
    p = sub.add_parser("broll", help="screen recordings for Real Avatar (or --upload one)")
    p.add_argument("--upload", metavar="VIDEO", help="upload and index a recording")
    sub.add_parser("reals", help="Real Avatar videos")
    p = sub.add_parser("news", help="news stories on a topic")
    p.add_argument("q")
    p = sub.add_parser("director", help="ask the Director for a spec")
    p.add_argument("prompt")

    a, rest = ap.parse_known_args(argv)   # --json and -q are accepted after the subcommand too (`jobs --json`)
    for x in rest:
        if x == "--json":
            a.json = True
        elif x in ("-q", "--quiet"):
            a.quiet = True
        else:
            ap.error("unrecognized arguments: %s" % x)
    try:
        th = ToonHouse(a.base, a.app_dir, verbose=not a.quiet)
        c = a.cmd
        if c == "speak":
            text = a.text
            if text.startswith("@"):
                text = Path(text[1:]).read_text()
            r = th.speak(text, voice=a.voice, reader=a.reader, speed=a.speed, out=a.out)
            _emit(r, a.json, r.get("mp3"))
        elif c == "make":
            spec = th.director(a.prompt) if a.prompt else _load_spec(a.spec or "-")
            if a.prompt and not spec:
                raise ToonHouseError("the Director returned no spec")
            r = th.make(spec, wait=not a.no_wait)
            _emit(r, a.json, r.get("render_path") or r.get("audio_path") or r.get("id"))
        elif c == "paint":
            f = th.paint(a.prompt, style=a.style, aspect=a.aspect, provider=a.provider)
            _emit({"file": f, "path": str(th.scene_path(f))}, a.json, str(th.scene_path(f)))
        elif c == "clip":
            f = th.clip(a.prompt, image=a.image, seconds=a.seconds, aspect=a.aspect)
            _emit({"file": f, "path": str(th.clip_path(f))}, a.json, str(th.clip_path(f)))
        elif c == "music":
            f = th.music(a.prompt, seconds=a.seconds)
            _emit({"file": f, "path": str(th.app_dir / "music" / f)}, a.json, str(th.app_dir / "music" / f))
        elif c == "game":
            body = a.prompt if a.prompt else _load_spec(a.spec or "-")
            r = th.game(body, wait=not a.no_wait, voice=a.voice)
            _emit(r, a.json, "%s\n%s" % (r.get("path", ""), r.get("play_url", "")) if r.get("path") else r.get("id"))
        elif c == "show":
            body = a.prompt if a.prompt else _load_spec(a.spec or "-")
            r = th.show(body, wait=not a.no_wait)
            _emit(r, a.json, r.get("render_path") or r.get("id"))
        elif c == "real":
            r = th.real(_load_spec(a.spec or "-"), wait=not a.no_wait)
            if r.get("warning"):
                th.log("warning: %s" % r["warning"])
            _emit(r, a.json, r.get("render_path") or r.get("id"))
        elif c == "person":
            r = th.person(_load_spec(a.spec))
            _emit(r, a.json, r.get("avatar"))
        elif c == "status":
            if a.kind:
                fn = lambda: th.status(a.kind, a.id)  # noqa: E731
            else:
                fn = lambda: th.status_any(a.id)  # noqa: E731
            r = th.wait(fn) if a.wait else fn()
            _emit(r, True)
        elif c == "jobs":
            try:
                _emit(th.jobs_all(), True)
            except ToonHouseError as e:
                th.log("jobs_all unavailable (%s); showing Voice Swap jobs only" % e)
                _emit([{"kind": "voice", "id": j["id"], "title": j.get("job", {}).get("title"),
                        **{k: j.get("status", {}).get(k) for k in ("state", "stage", "progress")}} for j in th.jobs()], True)
        elif c == "options":
            _emit(th.options(), True)
        elif c == "voices":
            _emit(th.voices(), True)
        elif c == "renders":
            _emit(th.renders(), True)
        elif c == "footage":
            _emit(th.footage_upload(a.upload) if a.upload else th.footage(), True)
        elif c == "broll":
            _emit(th.broll_upload(a.upload) if a.upload else th.broll(), True)
        elif c == "reals":
            _emit(th.reals(), True)
        elif c == "news":
            _emit(th.news(a.q), True)
        elif c == "director":
            _emit(th.director(a.prompt), True)
    except ToonHouseError as e:
        sys.stderr.write("error: %s\n" % e)
        return 1
    except KeyboardInterrupt:
        sys.stderr.write("\ninterrupted\n")
        return 130
    return 0


if __name__ == "__main__":
    sys.exit(main())
