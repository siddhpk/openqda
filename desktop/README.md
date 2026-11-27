# OpenQDA laptop edition

OpenQDA as a normal program: install it and double-click it. On Windows it opens in an OpenQDA window; on macOS
and Linux it opens in your browser.
There is no Docker, no WSL and no database server. Colleagues can join from anywhere through a link plus an
access code, much like a remote-support tool, while your computer is on.

| | |
|---|---|
| Windows 10/11 (64-bit) | `OpenQDA-Setup-<version>.exe`, installs per user without administrator rights |
| macOS 12+ on Apple silicon (M1 and later) | `OpenQDA-<version>-macos-arm64.dmg` |
| Linux x86-64 | `openqda-<version>-linux-x86_64.tar.gz` (unpack and run `./OpenQDA`) |

Intel Macs are not supported, because FrankenPHP publishes no Intel build. Use the Docker setup there
(`docs/installation/docker.md`).

## Installing

**Windows.** Run the setup file. The installers are not code-signed yet, so Windows SmartScreen may say
*"Windows protected your PC"*: choose **More info → Run anyway**. Setup needs no administrator rights. It adds
*OpenQDA* and *Stop OpenQDA* to the Start menu and can start OpenQDA at the end. Uninstall it under *Settings → Apps*.

**macOS.** Open the `.dmg` and drag OpenQDA into *Applications*. The app is not notarized, so the first start is
blocked: open *System Settings → Privacy & Security* and click **Open Anyway** next to the OpenQDA message, then
start it again.

**First start** takes a few seconds while the database is created; then the window or browser opens
`http://127.0.0.1:8765`. Register the first account. Confirmation e-mails are not needed in the laptop edition.
Starting OpenQDA again while it runs opens the existing window on Windows or the browser on macOS and Linux.

**Stopping.** Closing the Windows window stops OpenQDA. You can also right-click the OpenQDA icon in the
notification area (it may be under the `^` arrow) and select **Exit OpenQDA**, use *Stop OpenQDA* in the Start
menu, or use the *Sharing & autocoding* page (globe icon) → **Shut down OpenQDA**. On macOS and Linux, closing
the browser tab leaves OpenQDA running; use **Shut down OpenQDA** or `OpenQDA --stop` to stop it. Exiting
disconnects collaborators and stops the local server. Background autostart does not open a window or browser tab.

The Windows window requires the Microsoft Edge WebView2 Runtime and keeps a separate browser profile in the
OpenQDA data folder. Sessions from your regular browser are not shared. If WebView2 is missing, install the
runtime from Microsoft.

## Your data

| | Windows | macOS | Linux |
|---|---|---|---|
| Data folder | `%LOCALAPPDATA%\OpenQDA` | `~/Library/Application Support/OpenQDA` | `~/.local/share/openqda` |

It holds `openqda.sqlite` (the database), `storage/` (uploaded documents, logs) and `config.json` (this
installation's secret key; keep it private). Updates and uninstalling leave this folder alone.

**Back up** by quitting OpenQDA and copying the whole data folder; restore by copying it back. Keep backups
encrypted, since they contain interview data.

## Sharing with colleagues

Open **Sharing & autocoding** (globe icon). Only the person at this computer sees this page; people who come in through a
link never can.

1. **Share with a link (recommended).** One-time setup: install the free [Tailscale](https://tailscale.com/download)
   app on this computer and sign in. Colleagues need nothing but a browser. Press **Start sharing**. The first
   time, Tailscale asks you to allow *Funnel* on its website (a button appears). You get a link such as
   `https://your-laptop.tail1234.ts.net` and an access code such as `K7M-Q2R-9XA`. Send both with **Copy
   invitation**.
2. **Share on this network only.** For a room without internet: colleagues on the same Wi-Fi open
   `http://<your address>:8766`. This connection is not encrypted, so use it on trusted networks only.
   Your computer may ask once whether OpenQDA may accept connections; allow it for private networks.

Colleagues enter the code, then sign in with their own OpenQDA account or create one. They see only the projects
you add them to, as usual in OpenQDA.

**Safety built in:**
- The link is HTTPS, and its encryption ends on your computer. Tailscale's relays cannot read the traffic.
- Every start of sharing creates a new code and locks out everyone let in before. **Stop sharing** ends access
  immediately.
- Sharing stops after 8 hours unless you tick *Keep sharing on*. Five wrong codes per minute are allowed; after 20
  wrong codes sharing stops by itself.
- The code is stored encrypted, and visitors never reach the sharing controls or the shutdown button.

**A computer that stays on for others** (for example an office PC): tick *Start OpenQDA when I sign in* and
*Keep sharing on until I stop it*. The link stays the same; it works whenever that computer is on.

Sharing lets colleagues work on *this* computer's OpenQDA. For colleagues who work on *their own* laptops,
use collaboration (next section).

## Collaborating across laptops

Everyone installs OpenQDA and works on their own laptop, also offline. While you are online you see who else is
working (their initials in the sidebar), and every change reaches the others within a second. Memos and unlocked
document texts can be edited together, keystroke by keystroke, with everyone's cursor shown. After time offline,
the laptops catch up and merge by themselves. All of this runs over your team's private Tailscale network, built
into OpenQDA: nothing is reachable from the internet, and only OpenQDA (not the whole computer) joins the network.

1. **Invite:** open **Collaboration** (people icon) and press **Invite people**. One dialog guides you: sign in
   to Tailscale with your own free account (first time only), pick the project and the role, then share this
   computer if they need to use their own Tailscale account. When the one-click team-network setup is complete,
   press **Invite without a Tailscale account** and send the private invitation text.
2. **Join:** install OpenQDA and create your account, open **Collaboration**, choose **Join a project**, and paste
   the invitation. OpenQDA joins automatically when the invitation contains a one-time key. If it is a shared
   computer invitation instead, sign in to Tailscale with the invitee's account (first time only) and press
   *Accept* in the small window. The project arrives with all documents as soon as the inviting laptop is online.

If everyone keeps separate Tailscale accounts, nobody administers anyone else's account. Teams that prefer one
network you administer can set up one-click invitations under **Connection and settings**.

Under **Connection and settings**, **Disconnect** pauses collaboration and keeps this device's Tailscale
identity. **Connect to Tailscale** resumes it; restarting OpenQDA also reconnects automatically. While connected,
use **Sign out and forget this device** only when you want to leave the current Tailscale account or network.

**Roles:** a Primary's changes merge directly. An Assistant's additions, and changes to their own items, merge
directly too. Changes to or deletions of someone else's work wait in the Primaries' **Review** list.

**Stays private:** memos set to "Only me", accounts and passwords, AI settings and data. Deleting a project on
your laptop only means you stop collaborating on it.

The developer documentation (`docs/installation/laptop-collaboration.md`) has the access rules for the Tailscale
network and the details.

## Autocoding with AI embeddings (optional)

Needs the Embeddings & Autocoding module in the build (it is part of the full branch). Without it, the Sharing page
has no autocoding section.

Everything happens in the app, under **Sharing & autocoding** (globe icon) → *Autocoding (AI)*:

1. Install the free [Ollama](https://ollama.com/download) app on this computer and start it. Then click
   **Check again**.
2. Pick a model (`nomic-embed-text` for English, `qwen3-embedding:0.6b` for several languages) and click
   **Download model**. This takes a few minutes, once.
3. Click **Turn on autocoding**. The chip icon on the Coding page now opens the autocoding panel. Each project's
   owner switches it on once per project there.

Everything runs on this computer; nothing is sent to the internet. While autocoding is off, the chip icon is shown
grayed out and explains where to turn it on.

Advanced: `settings.env` in the data folder can set any `AI_*` option from `docs/plugins/autocoding.md` (and
other OpenQDA options, except the ones the launcher manages). A choice made on the settings page takes precedence.

## Building the installers

**On GitHub (easiest):** push the branch to your fork, open *Actions → Desktop installers → Run workflow*, and
download the three installers from the finished run. Pushing a tag `desktop-v*` does the same.

**On your own machine:**

```bash
python desktop/build/build.py --target windows-x86_64   # needs NSIS (makensis); runs on Windows, macOS or Linux
python desktop/build/build.py --target macos-arm64      # on an Apple-silicon Mac
python desktop/build/build.py --target linux-x86_64     # on Linux
```

You need Python 3.9+, PHP 8.2+ (with the openssl and zip extensions) with Composer 2, Node 20.19+ or 22.12+, Go 1.22+
and git. Before it builds anything, the script checks these and lists whatever is missing with install hints. To run
only the check, use `python desktop/build/build.py --check --target windows-x86_64`. Output goes to `desktop/dist/`.
The script installs Composer dependencies in `web/vendor` if Ziggy is missing for the frontend build. It downloads
FrankenPHP 1.13.5 from GitHub and checks it against pinned SHA-256 sums.

## How it works

```
OpenQDA launcher (Go, desktop/launcher)
 ├─ first start: config.json (APP_KEY, node key, ports), SQLite database, migrations
 ├─ FrankenPHP = Caddy web server + PHP 8.5, one process (runtime/)
 │   ├─ 127.0.0.1:8765   "local"  – you (Host must be 127.0.0.1/localhost)
 │   ├─ 127.0.0.1:<rand> "shared" – only reachable through Tailscale Funnel
 │   ├─ 0.0.0.0:8766     "lan"    – only while "share on this network" is on
 │   ├─ 127.0.0.1:<rand> "collab" – the node agent's storage API (no pages)
 │   └─ /_live on every listener → the node agent's live channel for browsers
 ├─ node agent (cluster/): embedded Tailscale node (data folder/tailscale), links to teammates' laptops,
 │   presence, replication, file copies; control API on 127.0.0.1 for PHP
 └─ watches state.json: sharing mode, autostart, shutdown request
Laravel app (web/, unchanged code paths on servers)
 ├─ config/desktop.php, app/Services/Desktop/* (Channel, SharingManager, Tailscale, DocumentConverter)
 ├─ DesktopGate middleware: access code for "shared"/"lan"; host-only routes answer 404 to visitors
 └─ documents converted in PHP (PhpWord, CommonMark); set PANDOC_PATH in settings.env to use pandoc instead
```

The web server sets the channel per listener (`OPENQDA_CHANNEL`); it never comes from a request header. Outside
the laptop edition (`OPENQDA_DESKTOP` unset) none of this is active.

## Known limitations

- The installers are not code-signed or notarized, so users see the warnings described above.
- On macOS and Linux there is no tray icon. Stop OpenQDA from the Sharing page.
- The Reverb WebSocket server is not part of the laptop edition. Live updates between laptops come from the
  collaboration agent instead; people sharing one computer through a link see others' changes after a reload.
- A computer belongs to one Tailscale network at a time; joining a project in another network moves OpenQDA
  there (it asks first).
- Installed size is about 250–400 MB, mostly the PHP runtime.
- Tested end to end in a browser on Linux and Windows. The macOS build comes from the same code and build script
  but has not run on a Mac yet.
- The Windows installer bundles Microsoft's Visual C++ runtime DLLs (14.51) next to PHP, so no separate
  *Visual C++ Redistributable* is needed.
