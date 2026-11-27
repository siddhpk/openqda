# Laptop Edition module

An add-on module for OpenQDA 1.0.8 that turns OpenQDA into an installable program for Windows, macOS (Apple silicon)
and Linux. A Go launcher runs FrankenPHP with SQLite, creates the database and secret key on first start, keeps data
in the per-user data folder. On Windows it opens an OpenQDA window; on macOS and Linux it opens the browser.
One computer can share its OpenQDA with colleagues through Tailscale Funnel or the local network, behind an access code.

Closing the Windows window stops OpenQDA and active sharing; the notification-area icon also provides an exit
option. The Windows window requires the Microsoft Edge WebView2 Runtime and uses its own browser profile, separate
from the user's regular browser. On macOS and Linux, closing the browser tab leaves OpenQDA running in the background.
Background autostart does not open a window or browser tab.

| | |
|---|---|
| Kind | distribution (launcher, build script, installers) + a backend module |
| Plugs into | the whole application; new *Sharing & autocoding* page for the person at the computer |
| Switch | `OPENQDA_DESKTOP`, set by the launcher |
| Migrations | none; the existing migrations now also run on SQLite (MySQL schemas are unchanged) |
| Depends on | OpenQDA 1.0.8 only; autocoding on the Sharing page needs the Embeddings & Autocoding module |

## On a server installation

Nothing to do: while `OPENQDA_DESKTOP` is unset, the module is inactive. One part applies to every installation:
`PANDOC_PATH` lets OpenQDA convert documents with a local pandoc instead of the transform service.

## Building the installers

- **On GitHub:** *Actions → Desktop installers → Run workflow*, or push a tag `desktop-v*`. The installers are
  attached to the run.
- **On your own machine:** `python desktop/build/build.py --target windows-x86_64` (or `macos-arm64`,
  `linux-x86_64`). `--check` only lists missing prerequisites. Output goes to `desktop/dist/`.

The full guide for users and builders is
[desktop/README.md](https://github.com/openqda/openqda/blob/main/desktop/README.md).

To let several laptops work on the same project, each on its own copy, see
[Laptop Collaboration](./laptop-collaboration.md).
