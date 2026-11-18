<template>
  <AuthenticatedLayout
    :menu="false"
    :showFooter="false"
    :title="`Concepts – ${props.project?.name ?? ''}`"
  >
    <template #main>
      <div class="p-4 space-y-4 text-sm">
        <div class="max-w-5xl">
          <h1 class="text-lg font-semibold">Concepts and propositions</h1>
          <p class="text-foreground/70">
            Build concepts from your codes and state how they relate. Link coded
            segments as evidence – supporting, challenging, qualifying or
            illustrating – and keep track of material that does not fit. Every
            change is kept in the history.
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
            @click="switchTab(t.value)"
          >
            {{ t.label }} ({{ t.count() }})
          </button>
        </nav>

        <div class="flex gap-4 items-start">
          <!-- ------------------------------------------------------- list -->
          <aside class="w-72 shrink-0 space-y-2">
            <div class="flex gap-1">
              <input
                v-model="newName"
                :class="inputClass"
                :placeholder="
                  tab === 'concepts' ? 'New concept name' : 'New proposition'
                "
                @keyup.enter="create"
              />
              <Button size="sm" :disabled="!newName.trim()" @click="create"
                >Add</Button
              >
            </div>
            <input v-model="filter" :class="inputClass" placeholder="Filter" />
            <ul class="divide-y border rounded">
              <li
                v-for="item in listed"
                :key="item.id"
                :class="[
                  'p-2 cursor-pointer',
                  selectedId === item.id && 'bg-accent',
                ]"
                @click="select(item.id)"
              >
                <div class="flex items-center gap-2">
                  <span
                    v-if="tab === 'concepts'"
                    class="w-2.5 h-2.5 rounded-full border"
                    :style="{ background: item.color || 'transparent' }"
                  ></span>
                  <span class="font-medium grow truncate">{{
                    item.name ?? item.title
                  }}</span>
                  <span
                    v-if="item.status"
                    class="text-[10px] px-1 rounded border"
                    >{{ statusLabel(item.status) }}</span
                  >
                </div>
                <div class="text-xs text-foreground/60">
                  {{ item.evidence.supports }} supporting ·
                  {{ item.evidence.challenges }} challenging
                  <span
                    v-if="item.evidence.openChallenges"
                    class="text-destructive"
                    >({{ item.evidence.openChallenges }} open)</span
                  >
                  <span v-if="tab === 'concepts'">
                    · {{ item.codesCount }} codes</span
                  >
                </div>
              </li>
              <li v-if="!listed.length" class="p-2 italic text-xs">
                {{
                  tab === 'concepts'
                    ? 'No concepts yet.'
                    : 'No propositions yet.'
                }}
              </li>
            </ul>
          </aside>

          <!-- ----------------------------------------------------- detail -->
          <main v-if="detail" class="grow max-w-3xl space-y-5">
            <!-- concept -->
            <section v-if="tab === 'concepts'" class="space-y-3">
              <div class="flex gap-2 items-center">
                <input
                  v-model="form.name"
                  :class="inputClass + ' text-base font-semibold'"
                />
                <input
                  v-model="form.color"
                  type="color"
                  class="w-9 h-9 rounded border"
                  title="Color"
                />
                <select v-model="form.status" :class="inputClass + ' w-40'">
                  <option :value="null">No status</option>
                  <option v-for="s in statuses()" :key="s.key" :value="s.key">
                    {{ s.label }}
                  </option>
                </select>
              </div>
              <label class="block">
                <span class="font-medium">Definition</span>
                <textarea
                  v-model="form.definition"
                  rows="4"
                  :class="inputClass"
                  placeholder="What the concept captures, in your words"
                ></textarea>
              </label>
              <div>
                <div class="font-medium">Properties and dimensions</div>
                <div
                  v-for="(p, i) in form.properties"
                  :key="i"
                  class="flex gap-1 mt-1"
                >
                  <input
                    v-model="p.name"
                    :class="inputClass"
                    placeholder="Property"
                  />
                  <input
                    v-model="p.low"
                    :class="inputClass"
                    placeholder="from (e.g. gradual)"
                  />
                  <input
                    v-model="p.high"
                    :class="inputClass"
                    placeholder="to (e.g. abrupt)"
                  />
                  <Button
                    size="sm"
                    variant="ghost"
                    @click="form.properties.splice(i, 1)"
                    >×</Button
                  >
                </div>
                <Button
                  size="sm"
                  variant="ghost"
                  @click="form.properties.push({ name: '', low: '', high: '' })"
                  >Add property</Button
                >
              </div>
              <div>
                <div class="font-medium">
                  Codes that inform it ({{ form.codes.length }})
                </div>
                <div class="flex flex-wrap gap-1 mt-1">
                  <span
                    v-for="id in form.codes"
                    :key="id"
                    class="text-xs px-2 py-0.5 rounded-full border"
                    :style="{ borderColor: codeColor(id) }"
                    >{{ codeName(id) }}
                    <button class="ms-1" @click="toggleCode(id)">×</button>
                  </span>
                </div>
                <select
                  :class="inputClass + ' mt-1'"
                  value=""
                  @change="
                    toggleCode($event.target.value);
                    $event.target.value = '';
                  "
                >
                  <option value="">Link a code…</option>
                  <option
                    v-for="c in codeOptions"
                    :key="c.id"
                    :value="c.id"
                    :disabled="form.codes.includes(c.id)"
                  >
                    {{ c.label }}
                  </option>
                </select>
                <p class="text-xs text-foreground/60 mt-1">
                  Segments of these codes and their subcodes are offered as
                  evidence.
                </p>
              </div>
              <div
                v-if="detail.propositions?.length"
                class="text-xs text-foreground/70"
              >
                Used in:
                <a
                  v-for="p in detail.propositions"
                  :key="p.id"
                  href="#"
                  class="underline me-2"
                  @click.prevent="openProposition(p.id)"
                  >{{ p.title }}</a
                >
              </div>
            </section>

            <!-- proposition -->
            <section v-else class="space-y-3">
              <div class="flex gap-2 items-center">
                <input
                  v-model="form.title"
                  :class="inputClass + ' text-base font-semibold'"
                />
                <select v-model="form.status" :class="inputClass + ' w-40'">
                  <option :value="null">No status</option>
                  <option v-for="s in statuses()" :key="s.key" :value="s.key">
                    {{ s.label }}
                  </option>
                </select>
              </div>
              <label class="block">
                <span class="font-medium">Statement</span>
                <textarea
                  v-model="form.statement"
                  rows="3"
                  :class="inputClass"
                  placeholder="What you propose about how the concepts relate"
                ></textarea>
              </label>
              <div>
                <div class="font-medium">Concepts and their roles</div>
                <div
                  v-for="(c, i) in form.concepts"
                  :key="c.id"
                  class="flex gap-1 mt-1 items-center"
                >
                  <span class="grow truncate">{{ conceptName(c.id) }}</span>
                  <input
                    v-model="c.role"
                    :class="inputClass + ' w-48'"
                    placeholder="role (your words)"
                    list="concept-roles"
                  />
                  <Button
                    size="sm"
                    variant="ghost"
                    :disabled="i === 0"
                    @click="moveConcept(i, -1)"
                    >↑</Button
                  >
                  <Button
                    size="sm"
                    variant="ghost"
                    @click="form.concepts.splice(i, 1)"
                    >×</Button
                  >
                </div>
                <datalist id="concept-roles">
                  <option v-for="r in knownRoles" :key="r" :value="r" />
                </datalist>
                <select
                  :class="inputClass + ' mt-1'"
                  value=""
                  @change="
                    addConcept($event.target.value);
                    $event.target.value = '';
                  "
                >
                  <option value="">Add a concept…</option>
                  <option
                    v-for="c in concepts"
                    :key="c.id"
                    :value="c.id"
                    :disabled="form.concepts.some((x) => x.id === c.id)"
                  >
                    {{ c.name }}
                  </option>
                </select>
              </div>
              <label v-for="f in propositionTexts" :key="f.key" class="block">
                <span class="font-medium">{{ f.label }}</span>
                <span class="text-xs text-foreground/60"> – {{ f.help }}</span>
                <textarea
                  v-model="form[f.key]"
                  rows="2"
                  :class="inputClass"
                ></textarea>
              </label>
            </section>

            <div class="flex flex-wrap gap-2 items-center border-t pt-3">
              <input
                v-model="form.reason"
                :class="inputClass + ' w-72'"
                placeholder="Why it changed (optional)"
              />
              <Button size="sm" :disabled="!dirty || saving" @click="save">{{
                saving ? 'Saving…' : 'Save'
              }}</Button>
              <span class="text-xs text-foreground/60"
                >Version {{ detail.item.version }}</span
              >
              <span class="grow"></span>
              <Button size="sm" variant="ghost" @click="remove">Delete</Button>
            </div>

            <EvidencePanel
              :project-id="projectId"
              :target-type="tab === 'concepts' ? 'concept' : 'proposition'"
              :target-id="detail.item.id"
              :evidence="detail.evidence"
              :codes="codes"
              :sources="sources"
              :query-text="
                tab === 'concepts'
                  ? `${form.name ?? ''}. ${form.definition ?? ''}`
                  : `${form.title ?? ''}. ${form.statement ?? ''}`
              "
              @changed="evidenceChanged"
            />

            <TargetMemos
              :project-id="projectId"
              :type="tab === 'concepts' ? 'concept' : 'proposition'"
              :target="detail.item.id"
              :memos="memoList"
              @changed="(list) => (memoList = list)"
            />

            <RevisionList
              v-if="detail.revisions.length"
              :revisions="detail.revisions"
              :current="currentSnapshot"
              :labels="revisionLabels"
              :format="formatRevision"
            />
          </main>
          <main v-else class="grow text-foreground/60 italic">
            Select or add
            {{ tab === 'concepts' ? 'a concept' : 'a proposition' }}.
          </main>
        </div>
      </div>
    </template>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout.vue';
import Button from '../Components/interactive/Button.vue';
import { flashMessage } from '../Components/notification/flashMessage.js';
import { statusLabel, statuses } from '../domain/qda/vocabulary.js';
import EvidencePanel from './analysis-objects/EvidencePanel.vue';
import TargetMemos from './analysis-objects/TargetMemos.vue';
import RevisionList from './analysis-objects/RevisionList.vue';

const props = defineProps({
  projectId: { type: [String, Number], required: true },
  project: { type: Object, default: null },
  concepts: { type: Array, default: () => [] },
  propositions: { type: Array, default: () => [] },
  codes: { type: Array, default: () => [] },
  sources: { type: Array, default: () => [] },
  memos: { type: Array, default: () => [] },
  roles: { type: Array, default: () => [] },
  tab: { type: String, default: 'concepts' },
  focus: { type: String, default: null },
});

const inputClass = 'w-full text-sm rounded border-input bg-surface';
const concepts = ref(props.concepts);
const propositions = ref(props.propositions);
const memoList = ref(props.memos);
const tab = ref(props.tab);
const tabs = [
  { value: 'concepts', label: 'Concepts', count: () => concepts.value.length },
  {
    value: 'propositions',
    label: 'Propositions',
    count: () => propositions.value.length,
  },
];
const filter = ref('');
const newName = ref('');
const selectedId = ref(null);
const detail = ref(null);
const saving = ref(false);
const form = reactive({});

const fail = (e) =>
  flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });

const listed = computed(() => {
  const list = tab.value === 'concepts' ? concepts.value : propositions.value;
  const f = filter.value.trim().toLowerCase();
  return f
    ? list.filter((i) => (i.name ?? i.title).toLowerCase().includes(f))
    : list;
});

// ---- codes -----------------------------------------------------------------
const codeById = computed(() => new Map(props.codes.map((c) => [c.id, c])));
const codeName = (id) => codeById.value.get(id)?.name ?? '?';
const codeColor = (id) => codeById.value.get(id)?.color;
// hierarchical labels: "Parent › Child"
const codeOptions = computed(() => {
  const path = (c, seen = new Set()) => {
    if (!c.parent_id || seen.has(c.id)) return c.name;
    seen.add(c.id);
    const parent = codeById.value.get(c.parent_id);
    return parent ? `${path(parent, seen)} › ${c.name}` : c.name;
  };
  return props.codes
    .map((c) => ({ id: c.id, label: path(c) }))
    .sort((a, b) => a.label.localeCompare(b.label));
});
const toggleCode = (id) => {
  if (!id) return;
  const i = form.codes.indexOf(id);
  if (i >= 0) form.codes.splice(i, 1);
  else form.codes.push(id);
};

// ---- concepts in propositions ------------------------------------------------
const conceptName = (id) =>
  concepts.value.find((c) => c.id === id)?.name ?? '?';
const addConcept = (id) => id && form.concepts.push({ id, role: '' });
const moveConcept = (i, d) => {
  const [c] = form.concepts.splice(i, 1);
  form.concepts.splice(i + d, 0, c);
};
const knownRoles = ref([...props.roles]);
const propositionTexts = [
  {
    key: 'conditions',
    label: 'Conditions',
    help: 'when or where it holds',
  },
  { key: 'mechanism', label: 'Mechanism', help: 'how or why it comes about' },
  { key: 'scope', label: 'Scope', help: 'to whom or what it applies' },
];

// ---- selection and form ------------------------------------------------------
const toForm = (item) => {
  Object.keys(form).forEach((k) => delete form[k]);
  if (tab.value === 'concepts') {
    Object.assign(form, {
      name: item.name,
      definition: item.definition ?? '',
      status: item.status ?? null,
      color: item.color ?? '#999999',
      properties: structuredClone(item.properties ?? []),
      codes: [...(item.codes ?? [])],
    });
  } else {
    Object.assign(form, {
      title: item.title,
      statement: item.statement ?? '',
      conditions: item.conditions ?? '',
      mechanism: item.mechanism ?? '',
      scope: item.scope ?? '',
      status: item.status ?? null,
      concepts: structuredClone(item.concepts ?? []),
    });
  }
  form.reason = '';
};
const payload = () => {
  const { reason, ...rest } = form;
  return { ...rest, reason };
};
const snapshotOf = () => {
  const { reason: _reason, ...rest } = form;
  return JSON.stringify(rest);
};
const loaded = ref('');
const dirty = computed(() => detail.value && snapshotOf() !== loaded.value);

const select = async (id) => {
  if (dirty.value && !confirm('Discard unsaved changes?')) return;
  selectedId.value = id;
  try {
    const isConcept = tab.value === 'concepts';
    const { data } = await axios.get(
      isConcept
        ? route('concepts.show', { project: props.projectId, concept: id })
        : route('propositions.show', {
            project: props.projectId,
            proposition: id,
          })
    );
    const item = isConcept ? data.concept : data.proposition;
    detail.value = {
      item,
      evidence: data.evidence,
      revisions: data.revisions,
      propositions: data.propositions ?? [],
    };
    toForm(item);
    loaded.value = snapshotOf();
  } catch (e) {
    fail(e);
  }
};
// roles named so far are offered as suggestions
const rememberRoles = (list) => {
  const roles = new Set(knownRoles.value);
  (list ?? []).forEach((c) => c.role && roles.add(c.role));
  knownRoles.value = [...roles].sort();
};

const switchTab = (value) => {
  if (dirty.value && !confirm('Discard unsaved changes?')) return;
  tab.value = value;
  selectedId.value = null;
  detail.value = null;
  loaded.value = '';
};
const openProposition = (id) => {
  switchTab('propositions');
  select(id);
};

const create = async () => {
  const name = newName.value.trim();
  if (!name) return;
  try {
    const isConcept = tab.value === 'concepts';
    const { data } = await axios.post(
      route(isConcept ? 'concepts.store' : 'propositions.store', {
        project: props.projectId,
      }),
      isConcept ? { name } : { title: name }
    );
    if (isConcept) concepts.value = data.concepts;
    else propositions.value = data.propositions;
    newName.value = '';
    loaded.value = '';
    detail.value = null;
    select((isConcept ? data.concept : data.proposition).id);
  } catch (e) {
    fail(e);
  }
};

const save = async () => {
  saving.value = true;
  try {
    const isConcept = tab.value === 'concepts';
    const body = payload();
    if (isConcept)
      body.properties = body.properties.filter((p) => p.name.trim());
    const { data } = await axios.put(
      isConcept
        ? route('concepts.update', {
            project: props.projectId,
            concept: selectedId.value,
          })
        : route('propositions.update', {
            project: props.projectId,
            proposition: selectedId.value,
          }),
      body
    );
    const item = isConcept ? data.concept : data.proposition;
    detail.value.item = item;
    detail.value.revisions = data.revisions;
    if (isConcept) concepts.value = data.concepts;
    else {
      propositions.value = data.propositions;
      rememberRoles(item.concepts);
    }
    toForm(item);
    loaded.value = snapshotOf();
    flashMessage('Saved', { type: 'success' });
  } catch (e) {
    fail(e);
  } finally {
    saving.value = false;
  }
};

const remove = async () => {
  const isConcept = tab.value === 'concepts';
  if (
    !confirm(
      `Delete this ${isConcept ? 'concept' : 'proposition'} with its evidence links, memos and history? The coded segments stay.`
    )
  )
    return;
  try {
    const { data } = await axios.delete(
      isConcept
        ? route('concepts.destroy', {
            project: props.projectId,
            concept: selectedId.value,
          })
        : route('propositions.destroy', {
            project: props.projectId,
            proposition: selectedId.value,
          })
    );
    if (data.concepts) concepts.value = data.concepts;
    if (data.propositions) propositions.value = data.propositions;
    detail.value = null;
    selectedId.value = null;
    loaded.value = '';
  } catch (e) {
    fail(e);
  }
};

const evidenceChanged = (list) => {
  detail.value.evidence = list;
  // refresh the counts in the list
  const counts = {
    supports: 0,
    challenges: 0,
    illustrates: 0,
    qualifies: 0,
    openChallenges: 0,
  };
  list.forEach((e) => {
    counts[e.stance]++;
    if (
      e.stance === 'challenges' &&
      ['open', 'unresolved', null].includes(e.resolution)
    )
      counts.openChallenges++;
  });
  const listRef = tab.value === 'concepts' ? concepts : propositions;
  const entry = listRef.value.find((i) => i.id === selectedId.value);
  if (entry) entry.evidence = counts;
};

// ---- history -------------------------------------------------------------------
const revisionLabels = computed(() =>
  tab.value === 'concepts'
    ? {
        name: 'Name',
        definition: 'Definition',
        status: 'Status',
        properties: 'Properties',
        codes: 'Codes',
      }
    : {
        title: 'Title',
        statement: 'Statement',
        conditions: 'Conditions',
        mechanism: 'Mechanism',
        scope: 'Scope',
        status: 'Status',
        concepts: 'Concepts',
      }
);
const currentSnapshot = computed(() => {
  const item = detail.value?.item ?? {};
  return { ...item, codes: [...(item.codes ?? [])].sort() };
});
const formatRevision = (field, value) => {
  if (field === 'status') return statusLabel(value);
  if (field === 'codes') return (value ?? []).map(codeName);
  if (field === 'properties')
    return (value ?? []).map(
      (p) => `${p.name} (${p.low ?? ''}–${p.high ?? ''})`
    );
  if (field === 'concepts')
    return (value ?? []).map(
      (c) => conceptName(c.id) + (c.role ? ` as ${c.role}` : '')
    );
  return value;
};

onMounted(() => {
  if (props.focus) select(props.focus);
});
</script>
