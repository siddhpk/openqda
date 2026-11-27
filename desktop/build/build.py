#!/usr/bin/env python3
"""Build the OpenQDA laptop edition.

    python desktop/build/build.py --target windows-x86_64   # OpenQDA-Setup-<v>.exe (needs NSIS; any OS)
    python desktop/build/build.py --target macos-arm64      # OpenQDA-<v>-macos-arm64.dmg (run on a Mac)
    python desktop/build/build.py --target linux-x86_64     # openqda-<v>-linux-x86_64.tar.gz
    python desktop/build/build.py --check --target windows-x86_64   # only check the prerequisites

Requirements on the build machine (checked before every build): Python 3.9+,
PHP 8.2+ with the openssl and zip extensions and Composer 2, Node 20.19+ or
22.12+ with npm (not needed with --reuse-frontend), Go 1.21+ (it downloads
the version in desktop/launcher/go.mod by itself), git (for the version label); for the
Windows installer also NSIS 3 (makensis; available for Windows, macOS and
Linux), for macOS the Xcode command line tools (codesign, hdiutil).

The runtime (FrankenPHP: web server + PHP) is downloaded from its GitHub
release and checked against the SHA-256 sums pinned below. Nothing else is
downloaded except Composer and npm packages from their lock files.
Output goes to desktop/dist/.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WEB = ROOT / "web"
DESKTOP = ROOT / "desktop"
CACHE = DESKTOP / "build" / ".cache"
STAGE = DESKTOP / "build" / ".stage"
DIST = DESKTOP / "dist"
MANIFEST = WEB / "public" / "build" / "manifest.json"
FRONTEND_MARK = MANIFEST.parent / ".desktop-build"  # written after a build with FRONTEND_ENV

# Vite bakes VITE_* variables into the bundle at build time, and reads them from
# web/.env. A development .env must not leak into the laptop edition: Reverb over
# plain http makes resources/js/collab/useEcho.js throw on import in a production
# build, which leaves every page after login empty. Real-time collaboration is off
# in the laptop edition (BROADCAST_DRIVER=log), so these get fixed values; Vite
# gives variables from the process environment precedence over .env files.
# The (unused) Echo client still connects once per page: without a host, pusher-js
# falls back to Pusher's cloud (wss://ws-.pusher.com), so it is pointed at a closed
# local port instead; the connection fails at once and Echo stops.
FRONTEND_ENV = {
    "VITE_REVERB_SCHEME": "https",
    "VITE_REVERB_APP_KEY": "",
    "VITE_REVERB_HOST": "127.0.0.1",
    "VITE_REVERB_PORT": "9",
    "VITE_DEBUG_CLIENT": "",
    "VITE_NOTIFICATION": "",
}

# Default install locations, used when a tool is not on PATH (for example in
# a terminal that was opened before the tool was installed).
TOOL_DEFAULTS = {
    "go": (r"C:\Program Files\Go\bin\go.exe", "/usr/local/go/bin/go", "/opt/homebrew/bin/go"),
    "makensis": (r"C:\Program Files (x86)\NSIS\makensis.exe", r"C:\Program Files\NSIS\makensis.exe"),
}
INSTALL_HINTS = {
    "php": "https://www.php.net/downloads (Windows: https://windows.php.net/download)",
    "composer": "https://getcomposer.org/download/",
    "node": "https://nodejs.org/ (Windows: winget install OpenJS.NodeJS.LTS)",
    "npm": "comes with Node.js: https://nodejs.org/",
    "go": "https://go.dev/dl/ (Windows: winget install GoLang.Go)",
    "makensis": "Windows: winget install NSIS.NSIS; Debian/Ubuntu: apt install nsis; macOS: brew install makensis",
    "git": "https://git-scm.com/downloads",
}

FRANKENPHP_VERSION = "1.13.5"  # PHP 8.5, Caddy 2.11
# SHA-256 of the release assets as re-uploaded upstream on 2026-11-19
# (matches the digests GitHub publishes for the v1.13.5 release assets).
RUNTIME = {
    "windows-x86_64": ("frankenphp-windows-x86_64.zip", "80ec80de9722e44341c476fe53bc8fad744866fa027f9a4c19bd4c0c554e3631"),
    "macos-arm64": ("frankenphp-mac-arm64", "2de72f9e9c5ad6bac2057a4fa74ad6fa7f5f260d5528b75b9f2f24569aa9c982"),
    "linux-x86_64": ("frankenphp-linux-x86_64", "1eac8bbaca19c6ffd751baba70a2390eaa826484a57db9cc46439662b68ea952"),
}
# Microsoft Visual C++ runtime for the Windows PHP build (needs 14.44 or newer).
# Shipped next to php.exe/frankenphp.exe (app-local deployment of the
# redistributable DLLs), so PCs without a recent VC++ Redistributable work too.
VC_RUNTIME = (
    "https://files.pythonhosted.org/packages/c2/6e/7ba5696e9d8c1bcb01352f06e441497c308e84f75aba598400646ab31ca7/"
    "msvc_runtime-14.51.36231-cp313-cp313-win_amd64.whl",
    "f3c3ac4f733a774f6ae209567e533bfde6f72dc1c10977733bfe09ce01e5b624",
)
VC_DLLS = ("vcruntime140.dll", "vcruntime140_1.dll", "msvcp140.dll")
GO_TARGET = {"windows-x86_64": ("windows", "amd64"), "macos-arm64": ("darwin", "arm64"), "linux-x86_64": ("linux", "amd64")}

# Not needed at run time; keeps the installer small.
APP_EXCLUDES = {
    ".git", ".github", "node_modules", "vendor", "tests", "stories", "cypress", "coverage",
    ".phpunit.cache", "docker", "local-dev", ".storybook", "plugins", "scripts",
}
APP_EXCLUDE_FILES = {
    ".env", ".env.testing", ".env.example", "hot", "phpunit.xml", "cypress.config.js", "vite.config.js",
    "safarivite.config.js", "eslint.config.js", "jsdoc.conf.json", "package.json", "package-lock.json",
    "plugins.js", "docker-compose.yml", "start_debug_services.sh", "pint.json", ".prettierrc",
    ".editorconfig", ".gitattributes", ".gitignore", ".dockerignore", FRONTEND_MARK.name,
}


def log(msg: str) -> None:
    print(f"==> {msg}", flush=True)


def run(cmd: list[str], cwd: Path | None = None, env: dict | None = None) -> None:
    log(" ".join(str(c) for c in cmd))
    exe = shutil.which(cmd[0]) or cmd[0]
    try:
        subprocess.run([exe, *cmd[1:]], cwd=cwd, env={**os.environ, **(env or {})}, check=True)
    except FileNotFoundError:
        sys.exit(f"{cmd[0]} was not found; install it or add it to PATH (open a new terminal after installing)")


def locate(name: str) -> str | None:
    """Find a build tool on PATH, then in its default install locations."""
    return shutil.which(name) or next((d for d in TOOL_DEFAULTS.get(name, ()) if Path(d).is_file()), None)


def find_tool(name: str) -> str:
    found = locate(name)
    if not found:
        sys.exit(f"{name} was not found; run with --check to see all missing prerequisites")
    return found


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def download(url: str, dest: Path, expected: str) -> Path:
    dest.parent.mkdir(parents=True, exist_ok=True)
    if not dest.exists() or sha256(dest) != expected:
        log(f"download {url}")
        tmp = dest.with_suffix(dest.suffix + ".part")
        with urllib.request.urlopen(url, timeout=600) as r, tmp.open("wb") as f:
            shutil.copyfileobj(r, f)
        tmp.replace(dest)
    actual = sha256(dest)
    if actual != expected:
        dest.unlink()
        sys.exit(f"checksum mismatch for {dest.name}: expected {expected}, got {actual}")
    return dest


def app_version() -> str:
    try:
        out = subprocess.run(["git", "describe", "--tags", "--always"], cwd=ROOT, capture_output=True, text=True, check=True)
        return out.stdout.strip().lstrip("v") or "0.0.0"
    except Exception:
        return "0.0.0"


def frontend_stamp() -> str:
    return f"{sha256(MANIFEST)} {json.dumps(FRONTEND_ENV, sort_keys=True)}"


def desktop_frontend_ready() -> bool:
    """True when web/public/build is a frontend build made by this script with
    the current FRONTEND_ENV, not one made with the settings in web/.env."""
    return MANIFEST.is_file() and FRONTEND_MARK.is_file() and FRONTEND_MARK.read_text().strip() == frontend_stamp()


def build_frontend(reuse: bool) -> None:
    if reuse and desktop_frontend_ready():
        log("reusing the existing desktop frontend build")
        return
    if reuse:
        log("web/public/build was not built by this script (it may use settings from web/.env): building it again")
    ziggy = WEB / "vendor" / "tightenco" / "ziggy" / "dist" / "vue.m.js"
    if not ziggy.is_file():
        log("installing Composer dependencies needed by the frontend")
        run(["composer", "install", "--prefer-dist", "--no-interaction", "--no-scripts", "--no-progress",
             "--ignore-platform-reqs"], cwd=WEB, env={"COMPOSER_ALLOW_SUPERUSER": "1"})
        if not ziggy.is_file():
            sys.exit("Composer did not install the Ziggy frontend dependency")
    run(["npm", "ci", "--no-audit", "--no-fund"], cwd=WEB)
    run(["npm", "run", "build"], cwd=WEB, env=FRONTEND_ENV)
    if not MANIFEST.exists():
        sys.exit("frontend build did not produce public/build/manifest.json")
    FRONTEND_MARK.write_text(frontend_stamp())


def stage_app(dest: Path) -> None:
    log(f"copy the application to {dest}")

    def ignore(directory: str, names: list[str]) -> set[str]:
        rel = Path(directory).relative_to(WEB)
        skip = {n for n in names if n in APP_EXCLUDES or n in APP_EXCLUDE_FILES}
        if rel == Path("resources"):
            skip |= {n for n in names if n in {"js", "css"}}
        if rel.parts[:1] == ("storage",) or rel == Path("bootstrap/cache"):
            # keep the folder skeleton, drop runtime files (logs, sessions, uploads)
            skip |= {n for n in names if (Path(directory) / n).is_file() and n != ".gitignore"}
            skip |= {n for n in names if n in {"projects", "debugbar", "profile-photos"}}
        return skip

    if dest.exists():
        shutil.rmtree(dest)
    shutil.copytree(WEB, dest, ignore=ignore, symlinks=False)
    # The app runs on the PHP bundled with FrankenPHP, not on the build machine's
    # PHP, so Composer must not check the lock file against the local PHP version
    # or extensions (otherwise a PHP 8.5 build machine rejects packages capped at
    # 8.4, and a missing local ext-gd fails the build).
    run(["composer", "install", "--no-dev", "--prefer-dist", "--optimize-autoloader", "--no-interaction", "--no-scripts", "--no-progress",
         "--ignore-platform-reqs"],
        cwd=dest, env={"COMPOSER_ALLOW_SUPERUSER": "1"})
    for git_dir in (dest / "vendor").rglob(".git"):
        shutil.rmtree(git_dir, ignore_errors=True)
    # package test suites and docs are never loaded in production; dropping them
    # keeps paths short (Windows MAX_PATH) and the installer small
    for package in (dest / "vendor").glob("*/*"):
        for extra in ("tests", "Tests", "test", "docs", "doc", ".github"):
            if (package / extra).is_dir():
                shutil.rmtree(package / extra, ignore_errors=True)
    # source maps are only useful while developing
    for source_map in (dest / "public" / "build").rglob("*.map"):
        source_map.unlink()
    # a stale public/storage link from a development checkout must not ship
    link = dest / "public" / "storage"
    if link.is_symlink() or link.exists():
        link.unlink() if link.is_symlink() or link.is_file() else shutil.rmtree(link)


def build_launcher(target: str, out: Path, version: str) -> None:
    goos, goarch = GO_TARGET[target]
    flags = f"-s -w -X main.version={version}"
    if goos == "windows":
        flags += " -H=windowsgui"
    go = find_tool("go")
    run([go, "build", "-trimpath", "-ldflags", flags, "-o", str(out), "."], cwd=DESKTOP / "launcher",
        env={"GOOS": goos, "GOARCH": goarch, "CGO_ENABLED": "0"})


def install_runtime(target: str, runtime_dir: Path) -> None:
    name, digest = RUNTIME[target]
    url = f"https://github.com/php/frankenphp/releases/download/v{FRANKENPHP_VERSION}/{name}"
    file = download(url, CACHE / f"{FRANKENPHP_VERSION}-{name}", digest)
    runtime_dir.mkdir(parents=True, exist_ok=True)
    if name.endswith(".zip"):
        # frankenphp.exe plus the official PHP build (php.exe, DLLs, ext/)
        with zipfile.ZipFile(file) as z:
            z.extractall(runtime_dir)
        url, digest = VC_RUNTIME
        wheel = download(url, CACHE / url.rsplit("/", 1)[1], digest)
        with zipfile.ZipFile(wheel) as z:
            for member in z.namelist():
                if member.endswith("/Scripts/" + Path(member).name) and Path(member).name in VC_DLLS:
                    (runtime_dir / Path(member).name).write_bytes(z.read(member))
        missing = [d for d in VC_DLLS if not (runtime_dir / d).exists()]
        if missing:
            sys.exit(f"Visual C++ runtime files missing: {missing}")
    else:
        target_bin = runtime_dir / "frankenphp"
        shutil.copy2(file, target_bin)
        target_bin.chmod(target_bin.stat().st_mode | stat.S_IXUSR | stat.S_IXGRP | stat.S_IXOTH)


def numeric_version(version: str) -> str:
    """Windows file versions must be four numbers: 1.0.8-desktop.1 -> 1.0.8.0"""
    parts = [int(p) for p in re.findall(r"\d+", version.split("-")[0])][:3]
    return ".".join(str(p) for p in (parts + [0, 0, 0])[:3]) + ".0"


def package_windows(stage: Path, version: str) -> Path:
    makensis = find_tool("makensis")
    DIST.mkdir(parents=True, exist_ok=True)
    out = DIST / f"OpenQDA-Setup-{version}.exe"
    size_kb = sum(f.stat().st_size for f in stage.rglob("*") if f.is_file()) // 1024
    run([makensis, "-V2", f"-DAPP_VERSION={version}", f"-DVI_VERSION={numeric_version(version)}", f"-DINSTALL_SIZE_KB={size_kb}",
         f"-DSOURCE_DIR={stage}", f"-DASSETS_DIR={DESKTOP / 'assets'}", f"-DOUT_FILE={out}",
         str(DESKTOP / "installer" / "openqda.nsi")])
    return out


def package_macos(bundle: Path, version: str) -> Path:
    plist = (DESKTOP / "installer" / "Info.plist").read_text().replace("@VERSION@", version)
    (bundle / "Contents" / "Info.plist").write_text(plist)
    shutil.copy2(DESKTOP / "assets" / "openqda.icns", bundle / "Contents" / "Resources" / "OpenQDA.icns")
    if shutil.which("codesign"):
        # ad-hoc signature: required for native code on Apple silicon (not notarized)
        run(["codesign", "--force", "--deep", "--sign", "-", str(bundle)])
    DIST.mkdir(parents=True, exist_ok=True)
    out = DIST / f"OpenQDA-{version}-macos-arm64.dmg"
    if not shutil.which("hdiutil"):
        log("hdiutil not found (not on macOS): leaving the .app in the stage folder")
        return bundle
    dmg_root = bundle.parent / "dmg"
    if dmg_root.exists():
        shutil.rmtree(dmg_root)
    dmg_root.mkdir()
    shutil.move(str(bundle), dmg_root / bundle.name)
    (dmg_root / "Applications").symlink_to("/Applications")
    if out.exists():
        out.unlink()
    run(["hdiutil", "create", "-volname", "OpenQDA", "-srcfolder", str(dmg_root), "-ov", "-format", "UDZO", str(out)])
    return out


def package_linux(stage: Path, version: str) -> Path:
    DIST.mkdir(parents=True, exist_ok=True)
    out = DIST / f"openqda-{version}-linux-x86_64.tar.gz"
    with tarfile.open(out, "w:gz") as tar:
        tar.add(stage, arcname=f"openqda-{version}")
    return out


def tool_version(exe: str, args: list[str]) -> tuple[int, ...] | None:
    """Run a tool's version command; return the first x.y[.z] number it prints."""
    try:
        out = subprocess.run([exe, *args], capture_output=True, text=True, timeout=120)
    except (OSError, subprocess.SubprocessError):
        return None
    m = re.search(r"(\d+)\.(\d+)(?:\.(\d+))?", f"{out.stdout}\n{out.stderr}")
    return tuple(int(g) for g in m.groups() if g is not None) if m else None


def go_required() -> tuple[int, int]:
    """The Go version the launcher needs, from the `go 1.x` line of its go.mod.
    Go 1.21 and later download that toolchain by themselves (GOTOOLCHAIN=auto,
    the default), so an older installed Go still builds it."""
    go_mod = DESKTOP / "launcher" / "go.mod"
    m = re.search(r"^go\s+(\d+)\.(\d+)", go_mod.read_text(), re.M) if go_mod.is_file() else None
    return (int(m[1]), int(m[2])) if m else (1, 22)


def check_prerequisites(target: str, frontend: bool, package: bool, git_label: bool) -> bool:
    """Check the tools and source files the build needs, before anything is
    deleted or built. Prints one line per item and returns False when a
    required item is missing; warnings do not stop the build."""
    log(f"check the build prerequisites for {target}")
    missing = 0

    def report(status: str, item: str, detail: str = "") -> None:
        nonlocal missing
        if status == "MISSING":
            missing += 1
        print(f"    {status:<8} {item}" + (f": {detail}" if detail else ""), flush=True)

    def tool(name: str, item: str, version_args: list[str], accept=lambda v: True, required: bool = True) -> str | None:
        status = "MISSING" if required else "warning"
        exe = locate(name)
        if not exe:
            report(status, item, f"not found. Install: {INSTALL_HINTS[name]}")
            return None
        version = tool_version(exe, version_args)
        shown = ".".join(map(str, version)) if version else "unknown version"
        if version is None or not accept(version):
            report(status, item, f"found {shown} ({exe}). Install: {INSTALL_HINTS[name]}")
            return None
        report("ok", item, f"{shown} ({exe})")
        return exe

    python = sys.version_info[:3]
    report("ok" if python >= (3, 9) else "MISSING", "Python 3.9+", ".".join(map(str, python)))

    php = tool("php", "PHP 8.2+ (runs Composer)", ["-n", "-r", "echo PHP_VERSION;"], lambda v: v >= (8, 2))
    if php:
        modules = set(subprocess.run([php, "-m"], capture_output=True, text=True, timeout=120).stdout.lower().split())
        if "openssl" in modules:
            report("ok", "PHP extension openssl")
        else:
            report("MISSING", "PHP extension openssl", "Composer needs it to download packages; enable extension=openssl in php.ini")
        if "zip" in modules or shutil.which("unzip") or shutil.which("7z"):
            report("ok", "PHP extension zip (or unzip/7z)")
        else:
            report("warning", "PHP extension zip", "Composer falls back to cloning every package with git (slow); enable extension=zip in php.ini")
    tool("composer", "Composer 2+", ["--version", "--no-ansi"], lambda v: v >= (2, 0))

    if frontend:
        tool("node", "Node 20.19+ or 22.12+ (required by Vite)", ["--version"], lambda v: v >= (22, 12) or (20, 19) <= v < (21,))
        tool("npm", "npm", ["--version"])
    else:
        report("ok", "frontend", f"reusing the desktop build in {MANIFEST.parent.relative_to(ROOT)}, Node and npm not needed")

    go_min = go_required()
    fetches = os.environ.get("GOTOOLCHAIN", "auto") != "local"
    tool("go", f"Go {go_min[0]}.{go_min[1]}+ (builds the launcher; Go 1.21+ downloads it on first use)", ["version"],
         lambda v: v >= go_min or (fetches and v >= (1, 21)))

    if package and target.startswith("windows"):
        tool("makensis", "NSIS 3 (makensis, builds the installer)", ["-VERSION"], lambda v: v >= (3, 0))
    if package and target.startswith("macos"):
        for name, effect in (("codesign", "the app is not ad-hoc signed, which Apple silicon requires"),
                             ("hdiutil", "no .dmg is made; the .app stays in the stage folder")):
            exe = shutil.which(name)
            report("ok" if exe else "warning", name, exe or f"not found (Xcode command line tools); {effect}")
    if git_label:
        tool("git", "git (version label; or pass --version)", ["--version"], required=False)

    files = [WEB / "composer.json", WEB / "composer.lock", WEB / "artisan", DESKTOP / "launcher" / "go.mod"]
    if frontend:
        files += [WEB / "package.json", WEB / "package-lock.json"]
    if target.startswith("linux"):
        files += [DESKTOP / "assets" / "openqda.png"]
    if package and target.startswith("windows"):
        files += [DESKTOP / "installer" / "openqda.nsi", DESKTOP / "assets" / "openqda.ico"]
    if package and target.startswith("macos"):
        files += [DESKTOP / "installer" / "Info.plist", DESKTOP / "assets" / "openqda.icns"]
    absent = [f for f in files if not f.is_file()]
    for f in absent:
        report("MISSING", str(f.relative_to(ROOT)), "file not found")
    if not absent:
        report("ok", "source files", ", ".join(f.name for f in files))

    if missing:
        print(f"    {missing} required item(s) missing: install them, open a new terminal and run again", flush=True)
    return missing == 0


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--target", choices=sorted(RUNTIME), default=None)
    parser.add_argument("--version", default=None, help="version label (default: git describe)")
    parser.add_argument("--reuse-frontend", action="store_true",
                        help="reuse web/public/build if this script built it (otherwise it is built again)")
    parser.add_argument("--no-package", action="store_true", help="stop after staging (no installer)")
    parser.add_argument("--check", action="store_true", help="only check the build prerequisites, then exit")
    args = parser.parse_args()

    target = args.target or {
        ("Windows", "AMD64"): "windows-x86_64", ("Darwin", "arm64"): "macos-arm64", ("Linux", "x86_64"): "linux-x86_64",
    }.get((platform.system(), platform.machine()))
    if not target:
        sys.exit("unsupported build platform; pass --target")
    version = args.version or app_version()
    log(f"OpenQDA laptop edition {version} for {target}")

    frontend = not (args.reuse_frontend and desktop_frontend_ready())
    ready = check_prerequisites(target, frontend=frontend, package=not args.no_package, git_label=args.version is None)
    if args.check or not ready:
        sys.exit(0 if ready else 1)

    if target == "macos-arm64":
        top = STAGE / target / "OpenQDA.app"
        root = top / "Contents" / "Resources"
        launcher = top / "Contents" / "MacOS" / "OpenQDA"
    else:
        top = STAGE / target / "OpenQDA"
        root = top
        launcher = top / ("OpenQDA.exe" if target.startswith("windows") else "OpenQDA")
    if (STAGE / target).exists():
        shutil.rmtree(STAGE / target)
    root.mkdir(parents=True)
    launcher.parent.mkdir(parents=True, exist_ok=True)

    build_frontend(args.reuse_frontend)
    stage_app(root / "app")
    install_runtime(target, root / "runtime")
    build_launcher(target, launcher, version)
    if target.startswith("linux"):
        shutil.copy2(DESKTOP / "assets" / "openqda.png", root / "openqda.png")

    if args.no_package:
        log(f"staged in {top}")
        return
    if target.startswith("windows"):
        out = package_windows(top, version)
    elif target.startswith("macos"):
        out = package_macos(top, version)
    else:
        out = package_linux(top, version)
    log(f"done: {out}")


if __name__ == "__main__":
    main()
