<template>
  <AuthenticatedLayout
    :menu="false"
    :showFooter="false"
    :title="`Cases – ${props.project?.name ?? ''}`"
  >
    <template #main>
      <div class="p-4 space-y-4 text-sm">
        <div class="max-w-5xl">
          <h1 class="text-lg font-semibold">Cases</h1>
          <p class="text-foreground/70">
            Group sources into cases – people, sites, organizations, episodes or
            whatever your study compares – and compare codes, concepts or
            propositions across them. An empty cell means "not examined";
            absence is only shown where you recorded it.
          </p>
        </div>

        <nav class="flex gap-2 border-b" role="tablist">
          <button
            v-for="t in tabs"
            :key="t.value"
            role="tab"
            :aria-selected="tab === t.value"
            :class="[
              'px-3 py-1 -mb-px border-b-2',
              tab === t.value
                ? 'border-primary font-semibold'
                : 'border-transparent',
            ]"
            @click="openTab(t.value)"
          >
            {{ t.label }}
          </button>
        </nav>

        <!-- ------------------------------------------------------ cases -->
        <div v-if="tab === 'cases'" class="flex gap-4 items-start">
          <aside class="w-72 shrink-0 space-y-2">
            <div class="flex gap-1">
              <input
                v-model="newName"
                :class="inputClass"
                placeholder="New case name"
                @keyup.enter="create"
              />
              <Button size="sm" :disabled="!newName.trim()" @click="create"
                >Add</Button
              >
            </div>
            <ul class="divide-y border rounded">
              <li
                v-for="c in cases"
                :key="c.id"
                :class="[
                  'p-2 cursor-pointer',
                  selectedId === c.id && 'bg-accent',
                ]"
                @click="select(c)"
              >
                <div class="font-medium">{{ c.name }}</div>
                <div class="text-xs text-foreground/60">
                  {{ c.sourceIds.length }} source(s)
                </div>
              </li>
              <li v-if="!cases.length" class="p-2 italic text-xs">
                No cases yet.
              </li>
            </ul>
            <p class="text-xs text-foreground/60">
              {{ unassigned.length }} of {{ sources.length }} sources belong to
              no case.
            </p>
          </aside>
          <main v-if="form.id" class="grow max-w-3xl space-y-3">
            <input
              v-model="form.name"
              :class="inputClass + ' text-base font-semibold'"
            />
            <label class="block">
              <span class="font-medium">Description</span>
              <textarea
                v-model="form.description"
                rows="3"
                :class="inputClass"
              ></textarea>
            </label>
            <div>
              <div class="font-medium">
                Sources ({{ form.sourceIds.length }})
              </div>
              <input
                v-model="sourceFilter"
                :class="inputClass + ' mt-1'"
                placeholder="Filter sources"
              />
              <ul
                class="max-h-64 overflow-auto border rounded mt-1 divide-y text-xs"
              >
                <li
                  v-for="s in filteredSources"
                  :key="s.id"
                  class="p-1.5 flex items-center gap-2"
                >
                  <input
                    v-model="form.sourceIds"
                    type="checkbox"
                    class="rounded"
                    :value="s.id"
                  />
                  <span class="grow">{{ s.name }}</span>
                  <span class="text-foreground/50">{{
                    casesOf(s.id)
                      .filter((c) => c.id !== form.id)
                      .map((c) => c.name)
                      .join(', ')
                  }}</span>
                </li>
              </ul>
            </div>
            <div class="flex gap-2">
              <Button size="sm" :disabled="saving" @click="save">Save</Button>
              <span class="grow"></span>
              <Button size="sm" variant="ghost" @click="remove">Delete</Button>
            </div>
            <TargetMemos
              :project-id="projectId"
              type="case"
              :target="form.id"
              :memos="memoList"
              @changed="(list) => (memoList = list)"
            />
          </main>
          <main v-else class="grow text-foreground/60 italic">
            Select or add a case.
          </main>
        </div>

        <!-- ----------------------------------------------------- matrix -->
        <div v-else class="space-y-3">
          <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2"
              >Rows
              <select
                v-model="rows"
                class="text-sm rounded border-input bg-surface"
                @change="loadMatrix"
              >
                <option value="code">Codes</option>
                <option value="concept">Concepts</option>
                <option value="proposition">Propositions</option>
              </select></label
            >
            <label v-if="rows === 'code'" class="flex items-center gap-1"
              ><input
                v-model="subcodes"
                type="checkbox"
                class="rounded"
                @change="loadMatrix"
              />
              include subcodes</label
            >
            <span class="grow"></span>
            <span class="text-xs text-foreground/60">
              number = segments found · ✓ present · ✕ absent · ? unclear (your
              judgement) · empty = not examined</span
            >
            <Button size="sm" variant="outline" @click="exportCsv">CSV</Button>
          </div>
          <p v-if="!matrix.cases.length" class="italic">
            Add cases on the Cases tab first.
          </p>
          <p v-else-if="!matrix.rows.length" class="italic">
            No
            {{
              {
                code: 'codes',
                concept: 'concepts',
                proposition: 'propositions',
              }[rows]
            }}
            yet.
          </p>
          <div v-else class="overflow-auto border rounded max-h-[70vh]">
            <table class="text-xs border-collapse">
              <thead class="sticky top-0 bg-surface">
                <tr>
                  <th class="text-left p-1.5 border-b min-w-[12rem]"></th>
                  <th
                    v-for="c in matrix.cases"
                    :key="c.id"
                    class="p-1.5 border-b border-l font-medium"
                    :title="`${c.sources} source(s)`"
                  >
                    {{ c.name }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in matrix.rows" :key="r.id">
                  <th
                    class="text-left p-1.5 border-b font-normal truncate max-w-[16rem]"
                  >
                    <span
                      class="inline-block w-2 h-2 rounded-full me-1"
                      :style="{ background: r.color || 'transparent' }"
                    ></span
                    >{{ r.name }}
                  </th>
                  <td
                    v-for="c in matrix.cases"
                    :key="c.id"
                    :class="[
                      'p-1.5 border-b border-l text-center cursor-pointer hover:bg-accent min-w-[4rem]',
                      cellClass(cellOf(r.id, c.id)),
                    ]"
                    :title="cellTitle(cellOf(r.id, c.id))"
                    @click="openCell(r, c)"
                  >
                    {{ cellText(cellOf(r.id, c.id)) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- drill-down -->
          <section
            v-if="cell.open"
            class="border rounded p-3 space-y-2 max-w-4xl"
          >
            <div class="flex items-center gap-2">
              <h3 class="font-semibold">
                {{ cell.row.name }} in {{ cell.case.name }}
              </h3>
              <span class="grow"></span>
              <Button size="sm" variant="ghost" @click="cell.open = false"
                >Close</Button
              >
            </div>
            <ul
              v-if="cell.items.length"
              class="divide-y border rounded text-xs max-h-72 overflow-auto"
            >
              <li v-for="(i, n) in cell.items" :key="n" class="p-2">
                <div>“{{ i.text }}”</div>
                <div class="text-foreground/60">
                  {{ i.sourceName }} · {{ i.label }}
                  <span v-if="i.note">· {{ i.note }}</span> ·
                  <a
                    href="#"
                    class="underline"
                    @click.prevent="openInSource(i.sourceId, i.start, i.end)"
                    >open in source</a
                  >
                </div>
              </li>
            </ul>
            <p v-else class="text-xs italic">
              No {{ rows === 'code' ? 'coded segments' : 'evidence' }} in this
              case's sources.
            </p>
            <div class="flex flex-wrap items-center gap-2 text-xs">
              <span class="font-medium">Your judgement:</span>
              <label
                v-for="s in states"
                :key="s.value ?? 'none'"
                class="flex items-center gap-1"
                ><input v-model="cell.state" type="radio" :value="s.value" />{{
                  s.label
                }}</label
              >
              <input
                v-model="cell.note"
                class="text-xs rounded border-input bg-surface grow"
                placeholder="Note, e.g. how you checked"
              />
              <Button size="sm" @click="assess">Save</Button>
            </div>
          </section>
        </div>
      </div>
    </template>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout.vue';
import Button from '../Components/interactive/Button.vue';
import { flashMessage } from '../Components/notification/flashMessage.js';
import TargetMemos from './analysis-objects/TargetMemos.vue';
import { openInSource } from './analysis-objects/openInSource.js';
import {
  downloadBlob,
  safeFilename,
} from '@openqda/visualization/src/mindmap/exporters.js';

const props = defineProps({
  projectId: { type: [String, Number], required: true },
  project: { type: Object, default: null },
  cases: { type: Array, default: () => [] },
  sources: { type: Array, default: () => [] },
  codes: { type: Array, default: () => [] },
  concepts: { type: Array, default: () => [] },
  propositions: { type: Array, default: () => [] },
  memos: { type: Array, default: () => [] },
});

const inputClass = 'w-full text-sm rounded border-input bg-surface';
const tabs = [
  { value: 'cases', label: 'Cases' },
  { value: 'matrix', label: 'Case matrix' },
];
const tab = ref('cases');
const cases = ref(props.cases);
const memoList = ref(props.memos);
const newName = ref('');
const selectedId = ref(null);
const saving = ref(false);
const sourceFilter = ref('');
const form = reactive({ id: null, name: '', description: '', sourceIds: [] });

const fail = (e) =>
  flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });

const casesOf = (sourceId) =>
  cases.value.filter((c) => c.sourceIds.includes(sourceId));
const unassigned = computed(() =>
  props.sources.filter((s) => !casesOf(s.id).length)
);
const filteredSources = computed(() => {
  const f = sourceFilter.value.trim().toLowerCase();
  return f
    ? props.sources.filter((s) => s.name.toLowerCase().includes(f))
    : props.sources;
});

const select = (c) => {
  selectedId.value = c.id;
  Object.assign(form, {
    id: c.id,
    name: c.name,
    description: c.description ?? '',
    sourceIds: [...c.sourceIds],
  });
};
const create = async () => {
  try {
    const { data } = await axios.post(
      route('cases.store', { project: props.projectId }),
      { name: newName.value.trim() }
    );
    cases.value = data.cases;
    const created = data.cases.find((c) => c.name === newName.value.trim());
    newName.value = '';
    if (created) select(created);
  } catch (e) {
    fail(e);
  }
};
const save = async () => {
  saving.value = true;
  try {
    const { data } = await axios.put(
      route('cases.update', { project: props.projectId, case: form.id }),
      {
        name: form.name,
        description: form.description,
        source_ids: form.sourceIds,
      }
    );
    cases.value = data.cases;
    flashMessage('Case saved', { type: 'success' });
  } catch (e) {
    fail(e);
  } finally {
    saving.value = false;
  }
};
const remove = async () => {
  if (!confirm('Delete this case? Its sources and their coding stay.')) return;
  try {
    const { data } = await axios.delete(
      route('cases.destroy', { project: props.projectId, case: form.id })
    );
    cases.value = data.cases;
    form.id = null;
    selectedId.value = null;
  } catch (e) {
    fail(e);
  }
};

// ---- matrix ----------------------------------------------------------------------
const rows = ref('code');
const subcodes = ref(false);
const matrix = reactive({ rows: [], cases: [], cells: {} });
const loadMatrix = async () => {
  try {
    const { data } = await axios.get(
      route('cases.matrix', {
        project: props.projectId,
        rows: rows.value,
        subcodes: subcodes.value ? 1 : 0,
      })
    );
    Object.assign(matrix, data);
  } catch (e) {
    fail(e);
  }
};
const openTab = (value) => {
  tab.value = value;
  if (value === 'matrix') loadMatrix();
};
const cellOf = (rowId, caseId) => matrix.cells?.[rowId]?.[caseId] ?? null;
const MARK = { present: '✓', absent: '✕', unclear: '?' };
const cellText = (cell) => {
  if (!cell) return '';
  const parts = [];
  if (cell.count) {
    parts.push(
      rows.value === 'code'
        ? String(cell.count)
        : `${cell.supports ?? 0}+ ${cell.challenges ?? 0}−`
    );
  }
  if (cell.assessment) parts.push(MARK[cell.assessment.state]);
  return parts.join(' ');
};
const cellClass = (cell) => {
  if (cell?.assessment?.state === 'absent' && cell.count)
    return 'bg-destructive/10'; // judged absent but segments exist
  if (cell?.assessment?.state === 'absent') return 'text-destructive';
  return '';
};
const cellTitle = (cell) => {
  if (!cell) return 'Not examined: no segments and no judgement recorded';
  const lines = [];
  if (cell.count)
    lines.push(
      rows.value === 'code'
        ? `${cell.count} coded segment(s)`
        : `${cell.count} evidence segment(s): ${cell.supports ?? 0} supporting, ${cell.challenges ?? 0} challenging, ${cell.qualifies ?? 0} qualifying, ${cell.illustrates ?? 0} illustrating`
    );
  if (cell.assessment)
    lines.push(
      `Judged ${cell.assessment.state}${cell.assessment.note ? ': ' + cell.assessment.note : ''}`
    );
  if (cell.assessment?.state === 'absent' && cell.count)
    lines.push('Segments exist although judged absent – worth a second look.');
  return lines.join('\n');
};

const states = [
  { value: 'present', label: 'present' },
  { value: 'absent', label: 'absent' },
  { value: 'unclear', label: 'unclear' },
  { value: null, label: 'not recorded' },
];
const cell = reactive({
  open: false,
  row: null,
  case: null,
  items: [],
  state: null,
  note: '',
});
const openCell = async (row, c) => {
  try {
    const { data } = await axios.get(
      route('cases.cell', {
        project: props.projectId,
        item_type: rows.value,
        item_id: row.id,
        case_id: c.id,
        subcodes: subcodes.value ? 1 : 0,
      })
    );
    Object.assign(cell, {
      open: true,
      row,
      case: c,
      items: data.items,
      state: data.assessment?.state ?? null,
      note: data.assessment?.note ?? '',
    });
  } catch (e) {
    fail(e);
  }
};
const assess = async () => {
  try {
    const { data } = await axios.put(
      route('cases.assess', { project: props.projectId }),
      {
        item_type: rows.value,
        item_id: cell.row.id,
        case_id: cell.case.id,
        state: cell.state,
        note: cell.note || null,
      }
    );
    matrix.cells[cell.row.id] ??= {};
    const entry = (matrix.cells[cell.row.id][cell.case.id] ??= {});
    if (data.assessment) entry.assessment = data.assessment;
    else delete entry.assessment;
    flashMessage('Judgement saved', { type: 'success' });
  } catch (e) {
    fail(e);
  }
};

const exportCsv = () => {
  const esc = (v) => `"${String(v ?? '').replaceAll('"', '""')}"`;
  const header = ['', ...matrix.cases.map((c) => c.name)];
  const lines = [header.map(esc).join(',')];
  matrix.rows.forEach((r) => {
    const cells = matrix.cases.map((c) => {
      const x = cellOf(r.id, c.id);
      if (!x) return 'not examined';
      const parts = [];
      if (x.count) parts.push(`${x.count} segment(s)`);
      if (x.assessment) parts.push(x.assessment.state);
      return parts.join('; ');
    });
    lines.push([r.name, ...cells].map(esc).join(','));
  });
  downloadBlob(
    new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' }),
    safeFilename(`case-matrix-${rows.value}`, 'csv')
  );
};
</script>
