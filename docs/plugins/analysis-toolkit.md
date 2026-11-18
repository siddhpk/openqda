# Analysis Toolkit module

An add-on module for OpenQDA 1.0.8 with method-neutral analysis tools: a per-project vocabulary, a versioned research
design with an analysis plan, relationships between codes, typed memos, concepts and propositions with evidence,
cases with a case matrix, and a FreeMind-style mind map. OpenQDA ships no methodology profiles; a project configures
the tools to fit its approach.

| | |
|---|---|
| Kind | backend + frontend module, plus the *Mind Map* visualization plugin |
| Plugs into | new *Research design*, *Mind Map*, *Concepts* and *Cases* pages; Coding page; Analysis page |
| Switch | none: active once the migrations have run |
| Migrations | `codes.kind`, `codes.meta`, `notes.memo_type`, `code_relations`, `project_vocabularies`, `research_designs`, `research_design_revisions` and the analysis tables below |
| Depends on | OpenQDA 1.0.8 only |

## Install

1. Apply the module's commit on top of OpenQDA 1.0.8.
2. Run `php artisan migrate` and rebuild the client (`npm run build`).

The module adds its routes in `routes/analysis.php` and registers the *Mind Map* visualization in `web/plugins.js`,
the client plugin registry (see [Visualization plugins](./visualization.md)).

With the Embeddings & Autocoding module enabled for a project, *Rank by similarity* orders candidate evidence by
embedding similarity. Without that module the button stays hidden.

## Analysis tools

There are no built-in methodologies, stages or categories. Every project has its own vocabulary and analysis plan;
the defaults are plain starter sets that can be renamed, extended, archived or replaced.

### Vocabulary

Stored per project in `project_vocabularies` (versioned, audited), edited on *Research design → Vocabulary*
(`app/Services/Qda/Vocabulary.php`, `resources/js/domain/qda/vocabulary.js`).

| Section | Each entry | Starter set |
|---|---|---|
| Code types | key, label, description | code, category, theme |
| Relationship types | key, label, short label, directed?, valence?, description | leads to, precedes, condition for, consequence of, part of, is a, associated with, contradicts, custom |
| Memo types | key, label, targets, template | memo, reflexive, summary, definition, decision |
| Statuses | key, label | draft, developing, stable, retired |
| Code fields | key, label, type (text, choice, boolean), options | origin (a priori, emergent, in vivo) |

- Entries in use cannot be deleted, only archived (still valid for existing data, not offered for new data).
- Stored data refers to keys (`^[a-z][a-z0-9_]{0,39}$`); labels can change freely. `code` and `custom` always exist.
- A key that data uses but the vocabulary lacks is added back with a generic label, so existing data stays valid.
- *Reset to starter set* keeps custom entries that are in use.

Codes, relationships and memos are validated against the project's vocabulary, which is shared with the client as
the Inertia prop `vocabulary`.

### Research design

*Research design* (`/projects/{project}/design`) records questions, aims, approach, positioning and rationale. Each
change creates a version (`research_design_revisions`, optional reason). The *Analysis plan* is a free list of steps;
the *Analysis overview* on the Coding page shows it beside counts of codes, relationships and memos.

### Data model

- `codes.kind` (code type) and `codes.meta` (definition, inclusion, exclusion, example, properties, status, custom
  fields; validated by `CodeMeta`).
- `code_relations`: typed links with valence and description. Directedness comes from the type. `dedupe_key`
  (pair, in either order when undirected, plus type or custom label) is unique per project. Deleting a code
  removes its relationships and their memos.
- `notes.memo_type`. Notes can target a relationship, concept, proposition or case of the same project, if the
  memo type allows it.
- `project_vocabularies`, `research_designs`, `research_design_revisions`, and the tables below.

### UI

- Coding page: *Analysis overview*, *Memos* (typed templates, filters, Markdown export), and per code *Details* and
  *Relationships*.
- Analysis page: the *Mind Map* visualization with relationships and relationship memos.

### Concepts, propositions, evidence and cases

Capabilities, not stages: use any of them, in any order.

- **Concepts**: name, definition, status, properties with dimensions, color and informing codes.
- **Propositions**: a statement relating concepts, with researcher-named roles, conditions, mechanism and scope.
- **Evidence**: coded segments linked as *supporting*, *challenging*, *qualifying* or *illustrating*, with a note.
  The excerpt is copied, so evidence stays readable if the segment is removed (then marked). *Open in source*
  selects the passage (`?focus=start-end`). Challenging evidence carries a resolution (open, accounted for, led to
  a revision, unresolved); lists show open challenges.
- **History**: every change keeps the previous version with an optional reason (`analytic_revisions`).
- **Rank by similarity** (Embeddings & Autocoding module, enabled for the project): orders candidate segments by similarity to the definition and
  supporting evidence, under the same consent, pseudonymization and cache.
- **Cases**: people, sites, episodes… each made of sources. The **case matrix** crosses codes, concepts or
  propositions with cases, showing segments or evidence and the researcher's judgement (present, absent, unclear).
  An empty cell means "not examined"; a cell judged absent despite segments is flagged. Exports as CSV.

Tables: `concepts`, `concept_code`, `propositions`, `proposition_concept`, `analytic_evidence`,
`analytic_revisions`, `cases`, `case_source`, `case_assessments`. Everything is scoped to the project; objects of
other projects are refused.

| Route | Purpose |
|---|---|
| `GET /projects/{project}/concepts` | concepts and propositions page (`?tab=propositions`, `?focus=<id>`) |
| `GET/POST/PUT/DELETE /projects/{project}/concepts[/{concept}]` | concepts |
| `GET/POST/PUT/DELETE /projects/{project}/propositions[/{proposition}]` | propositions |
| `GET /projects/{project}/evidence/candidates` | segments that could serve as evidence |
| `POST/PUT/DELETE /projects/{project}/evidence[/{evidence}]` | evidence links |
| `GET /projects/{project}/cases` | cases page |
| `POST/PUT/DELETE /projects/{project}/cases[/{case}]` | cases and their sources |
| `GET /projects/{project}/cases/matrix`, `.../cases/cell` | matrix and drill-down |
| `PUT /projects/{project}/cases/assessment` | record or clear a judgement |

## Mind map

`/projects/{project}/map` (*Mind Map*), plus a read-only display on the Analysis page. The renderer lives in
`web/plugins/visualization/src/mindmap/`.

- Central node with balanced branches, curved edges in code colors, fold toggles, memo and kind badges, zoom/pan.
- Drag a code onto another to make it a subcode (same codebook; cycles prevented), onto the center for top level.
  **Shift/Alt-drag** or **R** creates a relationship. Keys: arrows, Space, Enter/F2, Insert/Tab, Delete.
- Views: *Mind map*, *Focus view* (related codes grouped by relationship type) and *Relationship network*.
- Export FreeMind `.mm`, SVG and PNG. Import `.mm` (FreeMind, Freeplane) as a new codebook, notes as memos
  (`POST /projects/{project}/map/import`, max 2000 nodes, 12 levels).

## Tests

```bash
./vendor/bin/sail artisan test --filter='CodeRelationController|MapController|CodeMeta|VocabularyController|ResearchDesignController|AnalysisObjects|NoteTest'
./vendor/bin/sail npm run ci:unit -- mindmap
```
