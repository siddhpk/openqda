# Embeddings & Autocoding module

An add-on module for OpenQDA 1.0.8: code suggestions for sentences or paragraphs of one or many sources, learned from
the researcher's own coding. Text embeddings are the only AI feature; they are off by default and computed by
[Ollama](https://ollama.com) on your own machine or network. All statistics run in the browser.

| | |
|---|---|
| Kind | backend + frontend module, uses an Ollama server |
| Plugs into | Coding page (chip icon), *Batch autocoding* page (`/projects/{project}/autocode`) |
| Switch | `AI_ENABLED=true`, then the project owner enables embeddings per project |
| Migrations | `ai_embeddings`, `ai_usage_logs`, `autocode_runs`, `ai_suggestion_decisions`, `project_ai_settings`, `selections.origin` |
| Depends on | OpenQDA 1.0.8 only |

## Install

1. Apply the module's commit on top of OpenQDA 1.0.8.
2. Run `php artisan migrate` and rebuild the client (`npm run build`).
3. Install Ollama and set the `AI_*` variables as described under [Configuration](#configuration).

While `AI_ENABLED` is unset, the autocoding controls stay hidden and every route except `ai/status` answers 404.

The module works with other modules when they are installed, and without them:

- **Live Team Coding:** after a bulk acceptance, the coders who have the source open are offered a reload.
- **Analysis Toolkit:** each code's type is passed to the autocoding page. The toolkit's *Rank by similarity* uses
  these embeddings, under the same consent, pseudonymization and cache.
- **Laptop Edition:** autocoding can be switched on from the *Sharing & autocoding* page.

## Embeddings

### Prerequisite: Ollama

Ollama is the only embedding provider. OpenQDA itself does not need it; only autocoding and the Analysis Toolkit's
*Rank by similarity* do.

1. Install Ollama from [ollama.com/download](https://ollama.com/download).
2. Download the model once: `ollama pull nomic-embed-text`.
3. Check the server: `curl http://localhost:11434/api/version` answers with a version number.

With Docker (Sail), OpenQDA reaches Ollama on the host through `host.docker.internal`. On Linux, Ollama listens
only on `127.0.0.1`, so start it with `OLLAMA_HOST=0.0.0.0` (systemd: `systemctl edit ollama`, add
`Environment="OLLAMA_HOST=0.0.0.0"`), or run Ollama as a container and use `OLLAMA_BASE_URL=http://ollama:11434`.

### Configuration

Add to `web/.env` (a commented copy is in `web/.env.example`), then run `php artisan config:clear`:

```dotenv
AI_ENABLED=true
AI_EMBEDDING_PROVIDER=ollama
AI_EMBEDDING_MODEL=nomic-embed-text
OLLAMA_BASE_URL=http://host.docker.internal:11434
```

The project owner then enables embeddings once per project (chip icon on the Coding page → *Enable for project*).
All options are in `web/config/ai.php`.

| Model (`AI_EMBEDDING_MODEL`) | Download | Vector size | Use it when |
|---|---|---|---|
| `nomic-embed-text` (default) | ≈ 274 MB | 768, can be truncated | English data; best balance |
| `all-minilm` | ≈ 46 MB | 384 | weak hardware; English only; less accurate |
| `qwen3-embedding:0.6b` | ≈ 640 MB | up to 1024 | multilingual data on a normal laptop |
| `bge-m3` | ≈ 1.2 GB | 1024 | multilingual data, if speed matters less |

Embedding models run well on a laptop CPU, and every text is embedded only once (project cache).
`AI_EMBEDDING_DIMENSIONS=256` keeps the leading components of models trained for it (Matryoshka truncation,
re-normalized). This makes the browser statistics about three times faster at a small cost in accuracy.

### Where Ollama may run

`AI_LOCAL_ONLY=true` (default) refuses an Ollama endpoint outside this machine or your network. Locality is decided
from the address: `localhost`, private and link-local networks (including 100.64/10 used by Tailscale), single-label
host names (e.g. a Docker service `ollama`) and the suffixes `.local`, `.internal`, `.lan` and `.home.arpa`.
`OLLAMA_IS_CLOUD=true` can mark a local endpoint as third party, never the reverse. `AI_LOCAL_HOSTS` declares
institutional hosts as your own (comma-separated). `AI_LOCAL_ONLY=false` allows a third-party endpoint.

### Data safety

- **Per-project opt-in by the owner**, tied to a fingerprint of model, vector size, endpoint, locality,
  pseudonymization mode and `AI_CONSENT_POLICY_VERSION`. Any change asks the owner to confirm again. A third-party
  endpoint additionally needs an explicit acknowledgement (participant consent, data processing agreement).
- **Short units only.** Single sentences or paragraphs are sent, never whole documents, codes, memos or names.
- **Pseudonymization** (`AI_REDACTION=cloud` default, `always`, `off`): emails, URLs, phone numbers, IBANs, card
  numbers and IP addresses are masked, plus a project list of terms (names, places) kept encrypted by the owner
  and excluded from the audit log.
- **Transport.** Remote endpoints must use HTTPS unless `AI_ALLOW_INSECURE_HTTP=true`. Token-like strings are
  scrubbed from error messages; the browser receives status information only.
- **Cache.** `ai_embeddings` holds vectors only, per project, keyed by hash and encrypted with the app key
  (`AI_CACHE_ENCRYPT`). Entries expire after `AI_CACHE_TTL_DAYS` (90) via the daily `ai:prune`; *Delete cache*,
  disabling embeddings or `php artisan ai:prune --all` remove them.
- **Usage log.** `ai_usage_logs` records counts, never content. `AI_RATE_LIMIT` (60/min per user), at most 256
  texts of 8000 characters per request, and caps on corpus size and bulk acceptance.
- **Suggestions only.** Nothing is written until a person accepts it. Accepted segments carry
  `selections.origin = "autocode:<run id>"` (a run of the same project). `autocode_runs` stores settings and
  quality figures (validated, marked `reported_by: client`), never text. Bulk-accepted segments are checked
  against the source text.
- **Decisions without text.** `ai_suggestion_decisions` records accepted / modified / rejected / deferred per
  suggestion with positions, code ids, scores and an HMAC of the segment.

### Architecture

```
app/Services/AI/
  AiManager.php          configuration, locality, consent fingerprint, driver
  EmbeddingService.php   batching, truncation, normalization, encrypted project cache
  PiiRedactor.php        pseudonymization
  Drivers/               HttpDriver (TLS, error scrubbing), OllamaDriver
app/Http/Controllers/AiController.php, AutocodeController.php
app/Console/Commands/PruneAiData.php               ai:prune (daily)
app/Services/Qda/SourceText.php                    checks segments against the source text
resources/js/domain/ai/useAi.js
resources/js/Pages/coding/autocode/                segmentation, corpus, worker, panel, batch page
resources/js/Pages/coding/autocode/stats/          similarity, one-vs-rest logistic, L-BFGS, validation, cut-offs
```

| Route | Purpose |
|---|---|
| `GET /projects/{project}/ai/status` | configuration and consent state |
| `POST /projects/{project}/ai/consent` | owner enables or disables embeddings |
| `PUT /projects/{project}/ai/redaction-terms` | owner edits the pseudonymization list |
| `DELETE /projects/{project}/ai/cache` | delete cached vectors |
| `POST /projects/{project}/ai/embed` | embeddings (base64 little-endian float32) |
| `GET /projects/{project}/autocode` | batch autocoding page |
| `POST /projects/{project}/autocode/corpus` | coded sources, selections and targets |
| `GET/POST/PATCH /projects/{project}/autocode/runs` | list and record runs |
| `POST /projects/{project}/autocode/runs/{run}/accept` | accept many suggestions |
| `POST /projects/{project}/autocode/runs/{run}/decisions` | record decisions |

With `AI_ENABLED` unset every route except `status` answers 404; until the project is enabled, 403.

## Autocoding

Open a source and click the chip icon (one source, live editor content), or use *Batch autocoding*
(`/projects/{project}/autocode`) to code many sources and review all suggestions in one list.

**Pipeline.**

1. **Units.** Sources are split into sentences (`Intl.Segmenter`) or paragraphs, in the editor's own index space
   (a hidden Quill instance), so accepted suggestions become correctly placed selections.
2. **Training data.** Each unit of a coded source gets every code whose selections cover at least half of the unit
   or of the selection, so a unit can carry several codes. Uncoded units of other coded sources are the
   "no code" examples.
3. **Embeddings** are fetched once per unit and cached.
4. **Model** in a Web Worker (below).
5. **Review.** Suggested codes appear as chips with a score; accept, change, reject or defer each unit.

| Method | How it works | Best when |
|---|---|---|
| **Similarity** | Per code: cosine to the code's centroid minus cosine to the "no code" centroid. | few examples (≈ 3 per code), many codes |
| **Logistic** | One ridge-penalised logistic regression per code (one-vs-rest, balanced weights), λ ∈ {0.1, 0.01} tuned per fold. Codes do not compete, which fits overlapping coding. | ≈ 15+ examples per code, close codes |
| **Auto** (default) | Evaluates both and keeps the higher held-out micro-F1 (ties → similarity). | always |

Each code has its own cut-off; every code above it is suggested (*Allow several codes* off keeps only the best).

**Evaluation.** Folds are whole sources by default, so units of one interview never sit on both sides (with fewer
than three coded sources, or by choice, folds are units stratified by code). Cut-offs and λ are tuned on an inner
split; reported precision, recall and F1 come from the outer folds only. The final model is refitted on all coded
units. Scores are 0–1 rankings, not calibrated probabilities. With fewer than 25 coded units nothing is evaluated
and default cut-offs apply. Codes below *Minimum coded units per code* (5) are merged into their parent or left
out. The model card shows method, evaluation, per-code figures and warnings (no "no code" examples, low macro-F1,
few held-out units, within-source evaluation).

**Speed** (2 CPU cores, 2,000 training and 3,000 target units, 8 codes, nested 4-fold evaluation): 256-dim vectors
take 0.1 s (similarity) / 3.2 s (logistic); 768-dim 0.4 s / 9.1 s. Creating the embeddings the first time is the
slow step; Ollama needs about 0.5–1 GB RAM for `nomic-embed-text`.

**Limitations.** Suggestions repeat patterns in your coding so far; they are prompts, never decisions. Codes applied
to long passages give noisy sentence labels (use paragraphs). Without other coded sources there are no "no code"
examples and the model over-suggests (the card warns).

## Tests

```bash
./vendor/bin/sail artisan test --filter='Ai|PiiRedactor|AutocodeController'
./vendor/bin/sail npm run ci:unit -- autocode     # statistics, segmentation, corpus
```

Embedding calls are faked with `Http::fake()`; no test talks to a real embedding service.
