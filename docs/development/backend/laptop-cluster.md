# Laptop Cluster (collaboration of the Laptop Edition)

How laptop installations of OpenQDA replicate projects among themselves. For the user steps see
[Laptop Collaboration](../../installation/laptop-collaboration.md).

## Overview

```
 browser ──WS /_live──► Caddy ─► Go live hub ◄── presence, change events, co-editing, cursors
    │ pages ─────────► FrankenPHP (channels local / lan / shared, unchanged)
 Go node agent ── tsnet node "openqda-<computer>" ── tailnet :80 ── one WebSocket link per peer
    │  control API 127.0.0.1:<control> (token)  ◄── PHP: Tailscale sign-in, new ops, presence
    └─ storage API 127.0.0.1:<collab> (channel "collab", token) ─► PHP: apply, ops, vv, files, roster
```

The two halves split the work like this:

- **Go** (`desktop/launcher/cluster`) owns the network: the embedded Tailscale node, the links, heartbeats and
  presence, fan-out, catch-up and file transfer.
- **PHP** (`web/app/Services/Collab`) owns the data: it captures changes, signs them as operations, merges them
  and writes SQLite.

They talk over loopback only. Both directions use the installation's token (`OPENQDA_CONTROL_TOKEN`, from
`config.json`).

| Cluster idea | Implementation |
|---|---|
| Membership and heartbeat | Every 3 s each link sends `hb` with the presence of the browsers on that laptop. A peer is offline after 10 s of silence. |
| Replication pipeline | New local ops go at once to the online members of the project; ops that are new to a peer are forwarded to its other links (gossip). |
| Re-replication | Version vectors per project (highest contiguous sequence per origin node). A returning laptop catches up from any online member. |
| Checksummed blocks | Files are addressed by SHA-256 and copied in 256 KB chunks, resuming from a `.part` file. They are checked before PHP places them. |
| Authority | Primaries, through the roster. Data availability never depends on them. |

## Identity and links

- **Node key.** Each installation has an Ed25519 key, created by the launcher (`config.json` → `OPENQDA_NODE_KEY`).
  The node id is `"n" + hex(sha256(pub)[:10])`, computed the same way in Go (`cluster.NodeID`) and PHP
  (`NodeIdentity::nodeIdOf`). Cross-language test vectors are in `vectors_test.go` and
  `CollabApiTest::test_cross_language_vectors`.
- **Link.** `GET ws://<tailnet ip>:80/_cluster/v1/link`.
- **Handshake.** `hello` → `hello+sig` → `auth` → `ready`/`err`. Each side signs the other's nonce bound to both node
  ids. The acceptor admits members of a shared project whose key matches the roster, or a node presenting an
  invitation, which PHP checks (`POST /_collab/local/join`). Tailscale identities are not used for authorization.
- **Dialing.** The node with the smaller id dials; the other waits 2 s (it still dials if no link appears).
  Duplicate links resolve to the one dialed by the smaller id. OpenQDA nodes whose address changed are found by
  probing `openqda-*` hosts.
- **Frames.** `hb`, `vv`, `ops`, `bget`, `bdat`, `berr`, `y` (co-editing update, fast path), `aw` (cursor
  state). Ops are opaque to Go.

## Operations

```json
{"v":1,"id":"<node>:<seq>","p":"<project gid>","o":"<node>","s":12,"h":"0001732451234567-0003-<node>",
 "t":"codes","r":"<row gid>","k":"put","f":{"name":"Trust"},"b":{"name":["<op id>"]},"a":["<op id>"],"sig":"…"}
```

- **Fields.** `k` is `put`, `reject` or `yupd`. `b` lists the heads the change replaced, per field. `a` (on a
  Primary's put) lists accepted review items. `h` is a hybrid logical clock that sorts as a string and is
  stamped after everything its node has seen.
- **Signatures.** `sig` is Ed25519 over the canonical JSON of the op without `sig` (`Canonical::encode`). Relays
  cannot forge ops; only PHP verifies them.
- **Virtual tables.** `_members` (roster, row = node id) and `_users` (published display names, row = user gid).
- **Global ids.** UUID rows keep their key. Numeric keys (projects, codebooks, notes, users) are mapped to UUIDs
  (`collab_ids`). Tables with natural keys get UUIDv5 gids, so concurrent creations merge: `variables`
  (source, name), link tables, one-per-project tables. Foreign and polymorphic references are translated in
  both directions (`SyncSchema`).

## Capture

SQLite triggers on every replicated table insert `(tbl, pk)` into `collab_dirty`.

- **When they are quiet.** They skip writes while `collab_ctx.applying = 1` (set inside the applier's
  transaction) and while no project is collaborative.
- **Why triggers.** They see bulk query-builder deletes and foreign-key cascades, which Eloquent events miss.
- **Flushing.** `ChangeCapture::flush()` diffs each noted row against its shadow (`collab_rows`) and appends one op
  with the changed fields. It runs in the `CollabCapture` terminable middleware after each request and when the
  agent asks (every 30 s). Its result goes to the agent (`POST /v1/ops`).
- **Installing the triggers.** They are (re)created by `InstallCollabTriggers` on `MigrationsEnded`, on the laptop
  edition with SQLite only. The same listener turns on WAL; `config/database.php` sets a busy timeout.

## Merge rules

`Merge\RowFolder::fold()` is pure: a row's state is a function of the set of its ops and the roster. Every
laptop therefore converges whatever the arrival order. `tests/Unit/Collab/RowFolderTest` checks this with
random concurrent histories.

- **Fields.** Each field keeps its concurrent latest writes (heads). A write replaces the heads it saw (`b`). The
  visible value is the head with the highest `(rank, hlc, op id)`, with Primary ranked above Assistant.
- **Deletion.** The field `_deleted`, under the same rule.
- **Review.** An Assistant's put on a row created by another node is `pending` and does not enter the heads.
  - Accepting is a Primary put with `a: [ids]`.
  - Rejecting is a `reject` op. The Assistant's laptop then restores the row from the shadow; until then it
    shows its own pending values (`self_pending`).
  - When two Primaries decide concurrently, the earlier decision stands.
- **Roles over time.** A role applies from the HLC of its roster op (`Roster::roleAt`). Who may change the roster:
  - the founder registers itself;
  - only Primaries add members, remove them or change roles;
  - a member may update its own name and address, or leave.
- **Missing or deleted parents.** A row whose parent has not arrived waits (`state = waiting`). A row whose parent
  was deleted is an orphan, held back for review. Both are materialized when the parent appears
  (`Engine::drainWaiting`).
- **Unique collisions.** A collision (concept or case names, a relation's `dedupe_key`) aliases the incoming gid
  to the existing row.

## Files

Sources replicate `_file`/`_ext` (the original) and `_html` (the converted text) as SHA-256 hashes instead of
local paths.

- Missing files are reported by `need_blobs` and by the roster's `missing` list.
- The agent fetches them from any member (`bget`/`bdat`), verifies them and `PUT`s them to
  `/_collab/local/blob/{sha}`. `Blobs::place` writes them to the usual local path and sets the source status
  to `converted:html`.
- A file is served only to members of a project that contains it.

## Co-editing

Memo content and the text of an unlocked source are shared Yjs documents.

- **Two paths for each update.** It goes to the other open editors at once (`y` frames through the agents).
  It is also stored as a signed `yupd` op (`Docs::append`), so editors that were offline merge character by
  character.
- **Seeding.** A document without updates is seeded with Yjs client id 0 from the current content, which is
  identical on every laptop.
- **Saving.** One editor saves the text (the save leader, the smallest `node:session`), with
  `X-OpenQDA-YDoc: <doc>@<generation>`. The save records `_ygen`/`_yhash`, so the next editor continues the same
  generation. A text changed some other way starts a new generation from its content.
- **Who may edit live.** Primaries and the node that created the text. The agents enforce this for fast-path
  updates (`GET /_collab/local/doc`), and PHP enforces it for stored updates.

## Threat model

- **No public endpoint.** Only the tsnet node listens, on tailnet port 80. Links need a valid roster key and a
  signed handshake; every op is signed by its origin and checked against the roster and roles.
- **Invitations.** The token has 256 bits, is single-use, expires after 7 days and is stored hashed. The joiner
  pins the inviter's key from the invitation. Optional Tailscale keys are single-use, pre-approved and tagged.
- **Loopback APIs.** They bind to 127.0.0.1 and require the token. The storage API also requires the `collab`
  channel set by Caddy, and that channel serves no pages. Browser tokens for `/_live` are short-lived HMACs
  issued only to sessions that may open the project, so the sharing feature's access-code gate also covers it.
- **Not covered.** A removed member keeps their copy (no remote wipe). A member's laptop is trusted with the
  project's data.

## Tests

- Go: `cd desktop/launcher && go test ./...` uses a fake network on loopback sockets. It covers:
  - handshake refusals
  - fan-out, relaying and catch-up
  - joins
  - checked file copies
  - presence and co-editing
  - token and control checks
- PHP: `tests/Unit/Collab/RowFolderTest.php`, plus `tests/Feature/Collab/*`, which run two laptops as two
  SQLite databases (`CollabTestCase`). They need the sodium and pdo_sqlite extensions.
- JS: `resources/js/domain/collab/*.test.js` (textarea binding, seeding, leader election, live client).
