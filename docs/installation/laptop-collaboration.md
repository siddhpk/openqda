# Laptop Collaboration module

An add-on for the [Laptop Edition](./laptop-edition.md). Everyone works on their own laptop, also offline. The
laptops of a team form a private cluster over the team's Tailscale network:

- While people are online, they see who else is working, and every change reaches the others within a second.
  Memos and unlocked document texts can be co-edited keystroke by keystroke, with each person's cursor shown.
- After time offline, a laptop catches up from any online teammate and the copies merge by themselves.
- Nothing is reachable from the internet. There is no internet fallback.

| | |
|---|---|
| Kind | launcher (Go node agent with embedded Tailscale) + backend module + frontend module |
| Plugs into | new *Collaboration* page (person at the computer only), presence in the sidebar, live updates in the coding view, co-editing in the memo panel and the preparation editor |
| Switch | active only in the laptop edition (`OPENQDA_DESKTOP`, `OPENQDA_NODE_KEY` and `OPENQDA_CONTROL_*`, all set by the launcher) |
| Migrations | `2026_11_24_000001_create_collab_tables` (empty tables on servers); SQLite capture triggers are created after each migration run, laptop edition only |
| Depends on | the Laptop Edition; it uses the Live Team Coding apply logic in the coding view when that module is installed and the Analysis Toolkit tables when they exist |

## Setting up a team (UI steps)

**The Primary, once**

1. Open **Collaboration** (people icon) and press **Connect to Tailscale**. Sign in with a free account (Google,
   Microsoft, GitHub or Apple). This Tailscale network becomes the team network. Only OpenQDA joins it, not
   the whole computer, and no admin rights are needed.
2. Optional, only for the *into my network* methods (**one-click invitations**): in the Tailscale admin console,
   add the access rules shown on the page. They let OpenQDA laptops reach only each other's port 80. Then create an OAuth client (scope *Auth
   Keys (write)*, tag `tag:openqda`), paste it into the page, and press **Tag this computer**.

```json
{
  "tagOwners": { "tag:openqda": ["autogroup:admin"] },
  "grants": [{ "src": ["tag:openqda"], "dst": ["tag:openqda"], "ip": ["tcp:80"] }]
}
```

If your policy still has the default rule that allows everything, the other devices of your network can reach
the OpenQDA laptops too. Keep that in mind, or replace the default rule with rules for your own devices.

**Per project:** press **Collaborate** next to a project; you become its first Primary. Then **Invite**: choose
the role and how the invited person reaches your laptop:

| Join method | Who administers what | Invited person needs |
|---|---|---|
| *they use their own Tailscale (I share this computer)*, the default | everyone administers only their own account; you share just your OpenQDA device | their own free Tailscale account; they accept your share link once |
| *into my network, with a one-time key* (one-click invitations) | you administer the network their OpenQDA joins | nothing but OpenQDA |
| *into my network, with a key I paste* | same | nothing but OpenQDA |
| *into my network, as a user I invite* | same; they become a user of your network (6 free) | their own Tailscale account |

**Guided dialog:** *Invite people* and *Join a project* open one dialog each. Steps on Tailscale's website open in a
small window (Tailscale's pages cannot be embedded), and the dialog continues by itself when they are done. The
simplest invitation is text, which you paste under *Join a project*. When one-click team-network invitations are
set up, *Invite without a Tailscale account* creates the invitation in one step.

**Sharing (default):** in the Tailscale admin console open your OpenQDA device (the page links to it), choose
*Share…*, create a single-use link and paste it into the invitation. The invited person connects OpenQDA to their
own Tailscale account, joins with the invitation, opens the share link and presses *Accept*. OpenQDA connects by
itself after that. Their laptop connects to yours; Assistants who are not shared with each other exchange changes
through a Primary's laptop while it is online. If you restricted your network's access rules, allow your OpenQDA
device on tcp:80.

The invitation is a text (`openqda-invite:…`). Send it privately: it can contain a key. It is valid for 7 days
and for one laptop. The key belongs to the project invitation, not to a person.

**The invited person:** install OpenQDA, create a local account, open **Collaboration**, paste the invitation
and press **Join**. With a key, OpenQDA joins the team network by itself. If the computer is in another
Tailscale network, OpenQDA asks before it moves. The project appears as soon as the inviting laptop is online.
A later invitation to someone already in the network needs no key.

## Roles

- **Primary** (several are allowed): their changes merge silently and win over concurrent changes of
  Assistants. Primaries invite people, change roles and review.
- **Assistant**: additions (codes, codings, memos, documents) and changes to their own items merge at once.
  Changing or deleting something someone else created waits in the Primaries' **Review** list. In the meantime
  the Assistant sees their own change marked as waiting.
- Codings on an item a Primary deleted elsewhere are held back for review: **Bring back** the item, or
  **Discard** them.
- A project always keeps at least one Primary. A removed member's later changes no longer count; the copy on
  their laptop stays there.

## What is shared

Projects (name, description), codebooks, codes, documents with their original file and converted text,
codings, attributes, team memos, and, with the Analysis Toolkit, relationships, concepts, propositions, cases,
the vocabulary and the research design.

These stay on each laptop: private memos ("Only me"), user accounts and passwords, teams, audit logs, settings
and preferences, and all AI data (embeddings, consent, autocoding runs). Deleting a project on one laptop means
leaving the collaboration; the others keep it.

## Cost and privacy

- Free. Tailscale's Personal plan counts users (6 per network), not devices, and it is meant for
  non-commercial use. Institutions should check Tailscale's terms.
- Traffic between laptops is WireGuard-encrypted end to end. When no direct path exists, Tailscale's relays carry
  it still encrypted. The embedded node does not upload diagnostic logs to Tailscale.

For the design (protocol, merge rules, threat model) see [Laptop Cluster](../development/backend/laptop-cluster.md).
