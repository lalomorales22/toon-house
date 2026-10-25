#!/usr/bin/env bash
# Toon House installer — macOS (Apple Silicon or Intel) and Debian/Ubuntu.
#
#   curl -fsSL https://raw.githubusercontent.com/lalomorales22/toon-house/main/install.sh | bash
#   or, from inside a clone:   ./install.sh
#
# It installs the few system tools the app needs (PHP, ffmpeg, Python 3.11, git), gets the app,
# and runs the engine install (PyTorch, Seed-VC, Demucs, Kokoro and their models: ~3 GB, about 10 minutes).
# Nothing runs as root on macOS. On Linux, apt-get needs sudo and asks for it.
#
# Flags:  --yes          answer yes to every question
#         --no-engine    skip the engine (you can click "Install engine" in the app later)
#         --ollama       also set up Ollama + a small model for the AI Director and voxel buddies
#         --dir <path>   where to put the app (default: the current clone, or ~/toon-house)
#         --no-start     don't start the app at the end
set -euo pipefail

REPO="https://github.com/lalomorales22/toon-house.git"
YES=0; ENGINE=1; OLLAMA=0; START=1; DIR=""
while [ $# -gt 0 ]; do
  case "$1" in
    --yes|-y) YES=1 ;;
    --no-engine) ENGINE=0 ;;
    --ollama) OLLAMA=1 ;;
    --no-start) START=0 ;;
    --dir) DIR="$2"; shift ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) echo "Unknown flag: $1 (try --help)"; exit 1 ;;
  esac
  shift
done

bold() { printf '\033[1m%s\033[0m\n' "$*"; }
step() { printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }
ask() {   # ask "question" [default y|n] — reads from the terminal even when piped through bash
  local q="$1" def="${2:-y}" a
  if [ "$YES" = 1 ] || [ ! -r /dev/tty ] || [ ! -w /dev/tty ]; then [ "$def" = y ]; return; fi   # --yes (or no terminal): take the default
  printf '  %s [%s] ' "$q" "$([ "$def" = y ] && echo Y/n || echo y/N)" >/dev/tty
  read -r a </dev/tty || a=""
  a="${a:-$def}"; [[ "$a" =~ ^[Yy] ]]
}
have() { command -v "$1" >/dev/null 2>&1; }

bold "Toon House installer"
echo "Put your voice on any song, build talking toons, design games. Everything runs on this machine."

OS="$(uname -s)"
case "$OS" in
  Darwin) PLATFORM=mac; [ "$(uname -m)" = arm64 ] && ok "macOS on Apple Silicon" || warn "macOS on Intel: it works, but voice conversion is slow (no GPU)" ;;
  Linux)  PLATFORM=linux; have apt-get || die "This script supports Debian/Ubuntu on Linux (apt-get). On other distros, install php, ffmpeg, python3.11 and git yourself, then run: php index.php"; ok "Linux ($(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME" || echo apt))" ;;
  *) die "Unsupported OS: $OS" ;;
esac

# ── 1. system tools ──
step "System tools"
if [ "$PLATFORM" = mac ]; then
  if ! have brew; then
    warn "Homebrew is not installed. It is the package manager this installer uses (brew.sh)."
    ask "Install Homebrew now? (it will ask for your Mac password)" y || die "Homebrew is needed. Install it from https://brew.sh and run this again."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" </dev/tty
    for p in /opt/homebrew/bin/brew /usr/local/bin/brew; do [ -x "$p" ] && eval "$("$p" shellenv)"; done
  fi
  ok "Homebrew $(brew --version | head -1 | awk '{print $2}')"
  NEED=()
  have php && php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' && ok "PHP $(php -r 'echo PHP_VERSION;')" || NEED+=(php)
  have ffmpeg && ok "ffmpeg $(ffmpeg -version 2>/dev/null | head -1 | awk '{print $3}')" || NEED+=(ffmpeg)
  have git && ok "git $(git --version | awk '{print $3}')" || NEED+=(git)
  PY=""; for c in python3.11 python3.10 python3.12; do have "$c" && PY="$c" && break; done
  [ -n "$PY" ] && ok "$PY" || NEED+=(python@3.11)
  if [ ${#NEED[@]} -gt 0 ]; then
    echo "  Missing: ${NEED[*]}"
    ask "Install with Homebrew?" y || die "Install them yourself (brew install ${NEED[*]}) and run this again."
    brew install "${NEED[@]}"
  fi
else
  NEED=()
  have php && php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' && ok "PHP $(php -r 'echo PHP_VERSION;')" || NEED+=(php-cli php-curl php-mbstring php-xml php-gd)
  have ffmpeg && ok "ffmpeg" || NEED+=(ffmpeg)
  have git && ok "git" || NEED+=(git)
  PY=""; for c in python3.11 python3.10 python3.12; do have "$c" && PY="$c" && break; done
  [ -n "$PY" ] && ok "$PY" || NEED+=(python3.11 python3.11-venv)
  if [ ${#NEED[@]} -gt 0 ]; then
    echo "  Missing: ${NEED[*]}"
    ask "Install with apt-get (uses sudo)?" y || die "Install them yourself (sudo apt-get install ${NEED[*]}) and run this again."
    sudo apt-get update && sudo apt-get install -y "${NEED[@]}"
  fi
fi
# PHP extensions the app uses (curl for the AI features, mbstring for text, gd for pictures)
for ext in curl mbstring json; do php -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null || warn "PHP extension '$ext' is missing; some features won't work (macOS: brew's php has it; Debian: sudo apt-get install php-$ext)"; done

# ── 2. the app ──
step "The app"
if [ -z "$DIR" ]; then
  if [ -f "./index.php" ] && grep -q "TOON HOUSE" ./index.php 2>/dev/null; then DIR="$(pwd)"; else DIR="$HOME/toon-house"; fi
fi
if [ -f "$DIR/index.php" ]; then
  ok "Found the app in $DIR"
  if [ -d "$DIR/.git" ] && ask "Pull the latest version?" y; then git -C "$DIR" pull --ff-only || warn "Couldn't pull (local changes?). Continuing with what's there."; fi
else
  ask "Clone Toon House into $DIR?" y || die "Choose a folder with --dir <path>."
  git clone --depth 1 "$REPO" "$DIR"
  ok "Cloned into $DIR"
fi
cd "$DIR"

# ── 3. the engine ──
step "The engine (PyTorch, Seed-VC, Demucs, Kokoro + models)"
if [ -f engine/.ready ] && [ -x engine/venv/bin/python ]; then
  ok "Already installed"
elif [ "$ENGINE" = 0 ]; then
  warn "Skipped (--no-engine). Click 'Install engine' in the app, or run: php index.php setup"
else
  FREE_GB=$(df -Pk . | awk 'NR==2 {printf "%d", $4/1048576}')
  [ "$FREE_GB" -lt 8 ] && warn "Only ${FREE_GB} GB free on this disk; the engine needs about 6 GB."
  echo "  About 3 GB of downloads, usually 10 minutes. You can also do this later from the app."
  if ask "Install the engine now?" y; then
    php index.php setup || die "The engine install failed. Scroll up for the error, fix it, then run: php index.php setup"
    ok "Engine ready"
  else
    warn "Skipped. Click 'Install engine' in the app, or run: php index.php setup"
  fi
fi

# ── 4. optional: a local model for the AI Director, news scripts, game design and voxel buddies ──
step "Local AI (optional)"
if have ollama; then
  ok "Ollama is installed"
  MODELS="$(ollama list 2>/dev/null </dev/null | awk 'NR>1 {print $1}' | head -5 | tr '\n' ' ')"
  if [ -z "$MODELS" ]; then
    if [ "$OLLAMA" = 1 ] || ask "Pull a small model (qwen3.5:2b, ~2 GB) for the AI Director?" n; then ollama pull qwen3.5:2b || warn "Couldn't pull (is the Ollama app running?)"; fi
  else ok "Models: $MODELS"; fi
elif [ "$OLLAMA" = 1 ] || ask "Install Ollama, so the Director and voxel buddies can use a local model?" n; then
  if [ "$PLATFORM" = mac ]; then brew install ollama && brew services start ollama 2>/dev/null || true; else curl -fsSL https://ollama.com/install.sh | sh; fi
  sleep 2; ollama pull qwen3.5:2b || warn "Couldn't pull the model yet. Start Ollama, then: ollama pull qwen3.5:2b"
else
  echo "  Skipped. The app works without it; install Ollama any time (ollama.com) and the AI features light up."
fi
if [ "$PLATFORM" = mac ] && [ ! -x /Applications/Blender.app/Contents/MacOS/Blender ]; then
  echo "  Blender (free, blender.org) is optional: it lets Toon Lab build new Realistic 3D people."
fi

# ── done ──
step "Done"
bold "Start it:   cd \"$DIR\" && php index.php"
echo "It opens http://127.0.0.1:8777 in your browser. Ctrl+C stops it."
if [ "$START" = 1 ] && ask "Start it now?" y; then exec php index.php; fi
