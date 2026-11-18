# Live Team Coding module

An add-on module for OpenQDA 1.0.8: team members see who coded what, and each other's changes, without reloading.

| | |
|---|---|
| Kind | backend + frontend module, uses the Reverb WebSocket server |
| Plugs into | Coding page |
| Switch | `BROADCAST_DRIVER=reverb` |
| Migrations | none |
| Depends on | OpenQDA 1.0.8 only |

## Install

1. Apply the module's commit on top of OpenQDA 1.0.8.
2. Rebuild the client: `npm run build`.
3. For live updates, set `BROADCAST_DRIVER=reverb` and the `REVERB_*` / `VITE_REVERB_*` settings in `web/.env`, and
   run the Reverb server (see [Websocket](./websockets.md) and *Run the Collaboration Service* in the
   [Docker installation](../../installation/docker.md)).

The *Coded by* filter and the coder names work without Reverb. Without a running WebSocket server, changes still
save and appear on the next page load.

## What it does

- **Coded by** filter above the editor: everyone, me, or one team member. Highlight tooltips and the coding menu
  name the coder (`selections.creating_user_id`).
- **Live updates** through Laravel Reverb. Who has a source open, and changes to its segments, use the presence
  channel `coding.{source}` (event `selection.changed`: created, updated, deleted, or `stale` after a bulk change,
  which offers a reload). Code changes use the private channel `coding-project.{project}` (event `code.changed`).
  Both require project access.
- Events are sent with `toOthers()` and are best effort (`app/Services/Collab/LiveCoding.php`). The client applies
  events idempotently (`resources/js/collab/liveCodingSync.js`).

Other modules can ask the coders on a source to reload after a bulk change with
`LiveCoding::sourceStale($sourceId, $count)`. They guard the call with `class_exists(LiveCoding::class)`, so they
also work without this module.

## Files

```
app/Events/Coding/SelectionChanged.php, CodeChanged.php
app/Services/Collab/LiveCoding.php
routes/channels.php                                    channel authorization
resources/js/collab/                                   useLiveCoding.js, liveCodingSync.js
resources/js/Pages/coding/collab/                      CoderFilter.vue, LiveViewers.vue, useCoderFilter.js
```

## Tests

```bash
./vendor/bin/sail artisan test --filter=LiveCodingTest
./vendor/bin/sail npm run ci:unit -- liveCodingSync
```
