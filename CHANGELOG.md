# OpenQDA Changelog

> This changelog is ordered ascending, newest additions are always on top.

## Unreleased (`feature/ai-autocoding-qda-methods`, compared with 1.0.8)

Every commit of this branch is an add-on for the original OpenQDA 1.0.8 and depends on nothing else from the branch.
Each one can be installed on its own, in any combination and in any order:

```bash
git switch -c my-openqda 5d52841          # OpenQDA 1.0.8 (main)
git cherry-pick <commit> [<commit> ...]   # the modules you want, in any order
cd web && php artisan migrate && npm run build
```

Modules that meet each other work together: they check at runtime whether the other one is installed (for example
`class_exists()` in PHP, `import.meta.glob()` in the client) and otherwise leave that part out. Modules are described
in the plugin terms of [docs/plugins/overview.md](docs/plugins/overview.md): a backend (PHP) module, a frontend (Vue)
module, an external service, or a mix of these.

| Commit | Module | Kind | Switch | Migrations |
|---|---|---|---|---|
| f0a427a | [Live Team Coding](#module-live-team-coding) | backend + frontend, Reverb | `BROADCAST_DRIVER=reverb` | none |
| 55be3a8 | [Embeddings & Autocoding](#module-embeddings--autocoding) | backend + frontend, Ollama | `AI_ENABLED`, then owner consent per project | yes |
| 624c981 | [Analysis Toolkit with Mind Map](#module-analysis-toolkit-with-mind-map) | backend + frontend, visualization plugin | none | yes |
| b96a09a | [Matrix and Co-occurrence visualizations](#module-matrix-and-co-occurrence-visualizations) | frontend visualization plugins | none | none |
| 272cca9 | [Laptop Edition](#module-laptop-edition) | distribution + backend module | `OPENQDA_DESKTOP` (set by the launcher) | none |
| c3cc2c1, 018c5d6, 55da7df | [Core fixes and settings](#core-fixes-and-settings) | core | none | none |

### Module: Live Team Coding
- **Provides:** a *Coded by* filter and coder names on highlights; coded segments, code changes and who has a
  source open update live over Reverb (private channels `coding.{source}` and `coding-project.{project}`, members
  only). Best effort: coding keeps working when the WebSocket server is down.
- **Install:** `git cherry-pick f0a427a`, `npm run build`. For live updates: `BROADCAST_DRIVER=reverb`, the `REVERB_*` /
  `VITE_REVERB_*` settings and a running Reverb server.
- **Docs:** [docs/development/backend/live-coding.md](docs/development/backend/live-coding.md)

### Module: Embeddings & Autocoding
- **Provides:** code suggestions for sentences or paragraphs of one or many sources, with overlapping codes,
  similarity or one-vs-rest logistic models trained in the browser, source-grouped nested evaluation and a model
  card. Embeddings come from a local [Ollama](https://ollama.com) server, the only provider.
- **Safeguards:** off by default; per-project owner consent, local-only mode, pseudonymization, an encrypted vector
  cache with daily pruning (`ai:prune`), usage logs without content, and an audit record of runs and decisions.
- **Install:** `git cherry-pick 55be3a8`, `php artisan migrate`, `npm run build`; install Ollama, pull a model and set
  the `AI_*` variables (see `web/.env.example`). Routes: `routes/autocoding.php`.
- **With other modules:** offers a reload to live coders after bulk acceptance (Live Team Coding); passes each
  code's type (Analysis Toolkit); can be switched on from the Sharing page (Laptop Edition).
- **Docs:** [docs/plugins/autocoding.md](docs/plugins/autocoding.md)

### Module: Analysis Toolkit with Mind Map
- **Provides:** method-neutral analysis tools: a per-project vocabulary (code types, relationship types, memo
  types, statuses, custom code fields), a versioned research design with an analysis plan, typed relationships
  between codes, typed memos, concepts and propositions with supporting and challenging evidence and revision
  history, cases with a case matrix, and the *Mind Map* (focus view, relationship network, `.mm` import/export,
  SVG/PNG) as its own page and as a visualization plugin on the Analysis page.
- **Install:** `git cherry-pick 624c981`, `php artisan migrate`, `npm run build`. Routes: `routes/analysis.php`; the
  Mind Map registers in `web/plugins.js`.
- **With other modules:** *Rank by similarity* appears with Embeddings & Autocoding.
- **Docs:** [docs/plugins/analysis-toolkit.md](docs/plugins/analysis-toolkit.md)

### Module: Matrix and Co-occurrence visualizations
- **Provides:** two default visualization plugins on the Analysis page: **Code × Source Matrix** (per source or by a
  source variable; counts or excerpts; CSV) and **Code Co-occurrence** (overlap or proximity, optional Jaccard;
  CSV).
- **Install:** `git cherry-pick b96a09a`, `npm run build`.
- **With other modules:** suggests recording a pairing as a relationship on the Mind Map (Analysis Toolkit).
- **Docs:** [docs/plugins/visualization.md](docs/plugins/visualization.md)

### Module: Laptop Edition
- **Provides:** installers for Windows, macOS (Apple silicon) and Linux with a Go launcher, FrankenPHP and SQLite;
  sharing through Tailscale Funnel or the local network behind an access code; document conversion in PHP or with
  pandoc (`PANDOC_PATH`, on any installation); a GitHub Actions workflow that builds the installers.
- **Core changes it brings:** the existing migrations run on SQLite as well as MySQL (MySQL schemas are unchanged);
  navigation entries can declare a `visible(page)` condition; a source's conversion status is kept when the queue
  runs synchronously, as it always does in the laptop edition (this also fixes local development with
  `QUEUE_CONNECTION=sync`).
- **Install:** nothing to do on a server installation (`git cherry-pick 272cca9` leaves it inactive). Build the
  installers as described in [desktop/README.md](desktop/README.md). Routes: `routes/desktop.php`.
- **With other modules:** the Sharing page switches autocoding on with the local Ollama when Embeddings &
  Autocoding is installed.
- **Docs:** [docs/installation/laptop-edition.md](docs/installation/laptop-edition.md)

### Core fixes and settings
- Text can be selected for coding on phones and tablets (long press no longer cancels the selection; the *Menu*
  button keeps it). (c3cc2c1)
- `ADMINS` may be empty. (018c5d6)
- `TRUSTED_PROXIES` for running behind an HTTPS tunnel or reverse proxy; unset, no proxy is trusted, as before.
  (55da7df)

## Pre-Release
- code quality tools for client added ([#20](https://github.com/openqda/openqda/pull/20))
- readme/docs added
- milestone created

## Before Pre-release

This has been a prototype development, which is why we did not publish it.
The list may be incomplete or confusing.

8eef0bf (HEAD -> main, origin/main, origin/HEAD) Merge branch 'Staging'
822cdeb optimize security
ebc84b2 (origin/Staging) Update convertrtftohtml.php
445f98b build: remove generic fetch service (test-setup)
cfcc1b8 (upstream/main) Merge branch 'main' of github.com:openqda/openqda
cfa04f8 Merge branch 'main' of gitlab.informatik.uni-bremen.de:zemki -dev/meresearch-prototype
28c0f0d docs: CITATION File format updated
1988c71 Initial commit
40cb69f Better .env and more comments
d789a7c bugfix and improve user deletion
803b1ba Merge branch 'main' of gitlab.informatik.uni-bremen.de:zemki-dev/meresearch-prototype
9ff3755 bugfix change owner
2b003b7 empty teams now deleted
137d2e9 Update ProjectController.php
f1ce07b optimization
d0863e4 Create SetDeletedAtInDeletedTeam.php
d57fab9 Update DeleteUserForm.vue
92bfd65 Update DeleteUserForm.vue
185a497 Update DeleteUserForm.vue
f27360c Update DeleteUserForm.vue
862b276 Update DeleteUserForm.vue
0502c7e rights to change owner
500eaca setting new owner
34e2031 implement change owner
ed61fa9 Merge branch 'Staging'
2a6e516 commit before changing for docs
18ea57f Update DeleteUser.php
5dc1e16 Update RemoveTeamMember.php
a2349d8 Update DeleteRelatedSources.php
1a7fbf4 Delete users working as intended
3ac4c31 Merge branch 'Staging'
4f0f0be Update AppLayout.vue
bcc46be won against footer
5fd176b Merge branch 'laravel-11' into 'main'
760ef29 Package Upgrade
caceed6 small text changes
efd453b altcha package
92c6d14 altcha installed
29aa2e9 bugfix delete user
4264575 Update reverb.php
7177e5d Update reverb.php
10c6966 Update reverb.php
428fbae Update web.php
5784586 Package Upgrade
2e42c76 admin area extension
f0a3f72 Update ConvertFileToHtmlJob.php
f51ed39 Update ConvertFileToHtmlJob.php
1d92c88 Update ConvertFileToHtmlJob.php
6afa8b3 a
988d303 Update ConvertFileToHtmlJob.php
8050cfc Update ConvertFileToHtmlJob.php
d03078e Update ConvertFileToHtmlJob.php
52bcfb4 Update ConvertFileToHtmlJob.php
cf8ff40 Update ConvertFileToHtmlJob.php
365611f Update SourceController.php
b919872 Update SourceController.php
511998c Update Welcome.vue
0681163 Update FilesImporter.vue
bdf5c11 Merge branch 'Staging'
c0252a4 bugfix + cleanup
fd68b13 build: update citation file
1623585 build: citation file updated
d3c9620 Merge branch 'main' of gitlab.informatik.uni-bremen.de:zemki -dev/meresearch-prototype
ed3201d Update Footer.