<template>
  <AuthenticatedLayout
    :menu="false"
    :showFooter="false"
    :title="`Research design – ${props.project?.name ?? ''}`"
  >
    <template #main>
      <div class="max-w-4xl mx-auto p-4 space-y-6 text-sm">
        <div>
          <h1 class="text-lg font-semibold">Research design</h1>
          <p class="text-foreground/70">
            Describe your study in your own words and shape the analytic
            vocabulary to fit it. OpenQDA does not prescribe a methodology: the
            same tools serve any approach, and every change is kept.
          </p>
        </div>

        <nav class="flex gap-2 border-b" role="tablist">
          <button
            v-for="t in tabs"
            :key="t.value"
            role="tab"
            :aria-selected="tab === t.value"
            :data-test="`tab-${t.value}`"
            :class="[
              'px-3 py-1 -mb-px border-b-2',
              tab === t.value
                ? 'border-primary font-semibold'
                : 'border-transparent',
            ]"
            @click="tab = t.value"
          >
            {{ t.label }}
          </button>
        </nav>

        <!-- ------------------------------------------------ design record -->
        <section v-if="tab === 'design'" class="space-y-4">
          <div
            v-for="f in designFields"
            :key="f.key"
            class="space-y-1"
            :data-test="`design-${f.key}`"
          >
            <label :for="`design-${f.key}`" class="font-medium">{{
              f.label
            }}</label>
            <p class="text-xs text-foreground/60">{{ f.help }}</p>
            <textarea
              :id="`design-${f.key}`"
              v-model="designForm[f.key]"
              rows="4"
              :class="inputClass"
            ></textarea>
          </div>
          <div v-if="design.version > 0" class="space-y-1">
            <label for="design-reason" class="font-medium"
              >Reason for this change (optional)</label
            >
            <input
              id="design-reason"
              v-model="designForm.reason"
              :class="inputClass"
              placeholder="e.g. first interviews changed the focus"
            />
          </div>
          <div class="flex items-center gap-3">
            <Button
              :disabled="saving || !designDirty"
              data-test="save-design"
              @click="saveDesign"
              >{{ saving ? 'Saving…' : 'Save design' }}</Button
            >
            <span v-if="design.version" class="text-xs text-foreground/60">
              Version {{ design.version
              }}<template v-if="design.updatedBy">
                · {{ design.updatedBy }}</template
              ><template v-if="design.updatedAt">
                · {{ when(design.updatedAt) }}</template
              >
            </span>
          </div>

          <div v-if="revisions.length" class="space-y-2">
            <h2 class="font-semibold">Change history</h2>
            <ul class="divide-y border rounded" data-test="design-history">
              <li v-for="r in revisions" :key="r.version" class="p-2">
                <button
                  class="w-full text-left flex justify-between gap-2"
                  @click="
                    openRevision = openRevision === r.version ? null : r.version
                  "
                >
                  <span
                    >Version {{ r.version }}
                    <span class="text-foreground/60"
                      >· replaced by {{ r.changedBy ?? 'unknown' }} ·
                      {{ when(r.changedAt) }}</span
                    ></span
                  >
                  <span class="text-xs underline">{{
                    openRevision === r.version ? 'hide' : 'show'
                  }}</span>
                </button>
                <p v-if="r.reason" class="text-xs mt-1">
                  Reason: {{ r.reason }}
                </p>
                <dl
                  v-if="openRevision === r.version"
                  class="mt-2 space-y-2 text-xs"
                >
                  <template v-for="f in designFields" :key="f.key">
                    <div v-if="r.snapshot[f.key]">
                      <dt class="font-medium">{{ f.label }}</dt>
                      <dd class="whitespace-pre-line text-foreground/80">
                        {{ r.snapshot[f.key] }}
                      </dd>
                    </div>
                  </template>
                </dl>
              </li>
            </ul>
          </div>
        </section>

        <!-- --------------------------------------------------- vocabulary -->
        <section v-if="tab === 'vocabulary'" class="space-y-6">
          <p class="text-foreground/70">
            These lists define what you can choose while coding. Rename,
            describe or add entries to match your analytic language. Entries in
            use can be archived (hidden from choices) but not deleted, so
            existing work keeps its meaning.
          </p>
          <div
            v-for="section in vocabSections"
            :key="section.key"
            class="space-y-2"
            :data-test="`vocab-${section.key}`"
          >
            <h2 class="font-semibold">{{ section.label }}</h2>
            <p class="text-xs text-foreground/60">{{ section.help }}</p>
            <div
              v-for="(entry, i) in vocab[section.key]"
              :key="entry._id"
              :class="[
                'border rounded p-2 space-y-2',
                entry.archived && 'opacity-60',
              ]"
            >
              <div class="flex flex-wrap items-center gap-2">
                <input
                  v-model="entry.label"
                  :class="inputClass + ' w-56'"
                  placeholder="Name"
                  :aria-label="`${section.single} name`"
                  @input="entry._new && (entry.key = slug(entry.label))"
                />
                <code class="text-xs text-foreground/50">{{ entry.key }}</code>
                <span class="text-xs text-foreground/60"
                  >{{ usageOf(section.key, entry.key) }} in use</span
                >
                <span class="grow"></span>
                <label class="flex items-center gap-1 text-xs">
                  <input
                    v-model="entry.archived"
                    type="checkbox"
                    class="rounded"
                  />
                  archived
                </label>
                <button
                  v-if="
                    !usageOf(section.key, entry.key) &&
                    !locked(section.key, entry.key)
                  "
                  class="text-xs underline text-destructive"
                  @click="vocab[section.key].splice(i, 1)"
                >
                  remove
                </button>
              </div>
              <input
                v-model="entry.description"
                :class="inputClass"
                placeholder="Description (shown as help)"
              />
              <div
                v-if="section.key === 'relation_types'"
                class="flex flex-wrap items-center gap-3 text-xs"
              >
                <label class="flex items-center gap-1"
                  >Short label
                  <input v-model="entry.short" :class="inputClass + ' w-32'"
                /></label>
                <label class="flex items-center gap-1"
                  ><input
                    v-model="entry.directed"
                    type="checkbox"
                    class="rounded"
                  />
                  has a direction</label
                >
                <label class="flex items-center gap-1"
                  ><input
                    v-model="entry.valence"
                    type="checkbox"
                    class="rounded"
                  />
                  can be positive or negative</label
                >
              </div>
              <div
                v-if="section.key === 'memo_types'"
                class="space-y-1 text-xs"
              >
                <div class="flex flex-wrap gap-2">
                  <span class="text-foreground/60">Attach to:</span>
                  <label
                    v-for="t in memoTargets"
                    :key="t"
                    class="flex items-center gap-1"
                    ><input
                      v-model="entry.targets"
                      type="checkbox"
                      :value="t"
                      class="rounded"
                    />{{ t }}</label
                  >
                </div>
                <textarea
                  v-model="entry.template"
                  rows="3"
                  :class="inputClass"
                  placeholder="Template text (optional)"
                ></textarea>
              </div>
              <div
                v-if="section.key === 'code_fields'"
                class="space-y-1 text-xs"
              >
                <label class="flex items-center gap-2"
                  >Kind of field
                  <select v-model="entry.type" :class="inputClass + ' w-40'">
                    <option value="text">Text</option>
                    <option value="choice">Choice from a list</option>
                    <option value="boolean">Yes / no</option>
                  </select>
                </label>
                <div v-if="entry.type === 'choice'" class="space-y-1">
                  <div
                    v-for="(o, j) in entry.options"
                    :key="j"
                    class="flex items-center gap-2"
                  >
                    <input
                      v-model="o.label"
                      :class="inputClass + ' w-56'"
                      placeholder="Option"
                      @input="o._new && (o.key = slug(o.label))"
                    />
                    <code class="text-foreground/50">{{ o.key }}</code>
                    <button
                      class="underline"
                      @click="entry.options.splice(j, 1)"
                    >
                      remove
                    </button>
                  </div>
                  <button
                    class="underline"
                    @click="
                      entry.options.push({ key: '', label: '', _new: true })
                    "
                  >
                    add option
                  </button>
                </div>
              </div>
            </div>
            <Button
              size="sm"
              variant="outline"
              :data-test="`add-${section.key}`"
              @click="addEntry(section.key)"
              >Add {{ section.single }}</Button
            >
          </div>
          <div class="flex items-center gap-3 sticky bottom-0 bg-surface py-2">
            <Button
              :disabled="saving"
              data-test="save-vocabulary"
              @click="saveVocabulary"
              >{{ saving ? 'Saving…' : 'Save vocabulary' }}</Button
            >
            <Button
              v-if="vocabInfo.customised"
              variant="outline"
              :disabled="saving"
              @click="resetVocabulary"
              >Back to the starter set</Button
            >
            <span class="text-xs text-foreground/60">{{
              vocabInfo.customised
                ? `Version ${vocabInfo.version}`
                : 'Starter set (not customized yet)'
            }}</span>
          </div>
        </section>

        <!-- ---------------------------------------------------- the plan -->
        <section v-if="tab === 'plan'" class="space-y-3">
          <p class="text-foreground/70">
            An optional list of the steps you intend to take, in your own terms.
            It appears in the analysis overview on the Coding and Mind Map
            pages, where you can tick steps off. Revisit it as your analysis
            develops.
          </p>
          <ol class="space-y-2" data-test="plan-steps">
            <li
              v-for="(step, i) in plan"
              :key="step._id"
              class="border rounded p-2 space-y-1"
            >
              <div class="flex items-center gap-2">
                <input v-model="step.done" type="checkbox" class="rounded" />
                <input
                  v-model="step.title"
                  :class="inputClass"
                  placeholder="Step"
                  :aria-label="`Step ${i + 1}`"
                />
                <button
                  class="text-xs underline"
                  :disabled="i === 0"
                  @click="move(i, -1)"
                >
                  up
                </button>
                <button
                  class="text-xs underline"
                  :disabled="i === plan.length - 1"
                  @click="move(i, 1)"
                >
                  down
                </button>
                <button
                  class="text-xs underline text-destructive"
                  @click="plan.splice(i, 1)"
                >
                  remove
                </button>
              </div>
              <textarea
                v-model="step.description"
                rows="2"
                :class="inputClass"
                placeholder="Notes (optional)"
              ></textarea>
            </li>
          </ol>
          <div class="flex gap-3">
            <Button variant="outline" data-test="add-step" @click="addStep"
              >Add step</Button
            >
            <Button
              :disabled="saving"
              data-test="save-plan"
              @click="savePlan"
              >{{ saving ? 'Saving…' : 'Save plan' }}</Button
            >
          </div>
        </section>
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
import { setVocabulary } from '../domain/qda/vocabulary.js';

const props = defineProps({
  projectId: [String, Number],
  project: Object,
  design: Object,
  revisions: Array,
  vocabulary: Object,
  vocabularyInfo: Object,
  tab: String,
});

const tabs = [
  { value: 'design', label: 'Design' },
  { value: 'vocabulary', label: 'Vocabulary' },
  { value: 'plan', label: 'Analysis plan' },
];
const tab = ref(props.tab ?? 'design');
const saving = ref(false);
const inputClass = 'w-full text-sm rounded border-input bg-surface';
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
let uid = 0;
const nextId = () => `n${++uid}`;
const slug = (label) =>
  (label || '')
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/^(\d)/, 'x$1')
    .slice(0, 40) || 'entry';

// ------------------------------------------------------------ design record
const designFields = [
  {
    key: 'questions',
    label: 'Research questions',
    help: 'What do you want to find out?',
  },
  {
    key: 'aims',
    label: 'Aims and objectives',
    help: 'What should the study achieve, and for whom?',
  },
  {
    key: 'approach',
    label: 'Analytic approach',
    help: 'How you intend to analyze the data, in your own words. It is fine to start open and refine this later.',
  },
  {
    key: 'positioning',
    label: 'Positioning and reflexivity',
    help: 'Your relation to the topic and participants, assumptions you bring, and how you will keep them in view.',
  },
  {
    key: 'rationale',
    label: 'Rationale',
    help: 'Why this approach fits your questions and data.',
  },
];
const design = ref(props.design);
const revisions = ref(props.revisions ?? []);
const openRevision = ref(null);
const designForm = reactive({
  ...Object.fromEntries(
    designFields.map((f) => [f.key, props.design[f.key] ?? ''])
  ),
  reason: '',
});
const designDirty = computed(() =>
  designFields.some(
    (f) => (designForm[f.key] ?? '') !== (design.value[f.key] ?? '')
  )
);
const saveDesign = async () => {
  saving.value = true;
  try {
    const { data } = await axios.put(
      route('design.update', { project: props.projectId }),
      { ...designForm }
    );
    design.value = data.design;
    revisions.value = data.revisions;
    designForm.reason = '';
    flashMessage('Research design saved', { type: 'success' });
  } catch (e) {
    flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });
  } finally {
    saving.value = false;
  }
};

// --------------------------------------------------------------- vocabulary
const vocabSections = [
  {
    key: 'code_types',
    label: 'Types of codes',
    single: 'type',
    help: 'For example code, category, theme, pattern code, concept. Hierarchy (subcodes) works with every type.',
  },
  {
    key: 'relation_types',
    label: 'Relationship types',
    single: 'relationship type',
    help: 'How codes can be linked on the Coding and Mind Map pages.',
  },
  {
    key: 'memo_types',
    label: 'Memo types',
    single: 'memo type',
    help: 'Kinds of memos, where they can be attached and an optional template.',
  },
  {
    key: 'statuses',
    label: 'Statuses',
    single: 'status',
    help: 'How developed a code is, in your terms.',
  },
  {
    key: 'code_fields',
    label: 'Custom code fields',
    single: 'field',
    help: 'Extra attributes recorded for every code, besides definition, criteria, example and properties.',
  },
];
const memoTargets = [
  'project',
  'source',
  'selection',
  'code',
  'relation',
  'case',
  'concept',
  'proposition',
];
const toForm = (v) =>
  Object.fromEntries(
    vocabSections.map((s) => [
      s.key,
      (v?.[s.key] ?? []).map((e) => ({
        ...e,
        _id: nextId(),
        targets: e.targets ? [...e.targets] : undefined,
        options: e.options ? e.options.map((o) => ({ ...o })) : undefined,
      })),
    ])
  );
const vocab = reactive(toForm(props.vocabulary));
const vocabInfo = ref(
  props.vocabularyInfo ?? { usage: {}, version: 0, customised: false }
);
const usageOf = (section, key) => vocabInfo.value.usage?.[section]?.[key] ?? 0;
const locked = (section, key) =>
  (section === 'code_types' && key === 'code') ||
  (section === 'relation_types' && key === 'custom');

const addEntry = (section) => {
  const base = {
    key: '',
    label: '',
    description: '',
    archived: false,
    _id: nextId(),
    _new: true,
  };
  const extra = {
    relation_types: { short: '', directed: true, valence: false },
    memo_types: { targets: ['project', 'source', 'code'], template: '' },
    code_fields: { type: 'text', options: [] },
  }[section];
  vocab[section].push({ ...base, ...(extra ?? {}) });
};

const payload = () =>
  Object.fromEntries(
    vocabSections.map((s) => [
      s.key,
      vocab[s.key].map(({ _id, _new, ...e }) => {
        void _id;
        void _new;
        const out = { ...e, key: e.key || slug(e.label) };
        if (out.options)
          out.options = out.options.map(
            ({ _new: n, ...o }) => (
              void n,
              { ...o, key: o.key || slug(o.label) }
            )
          );
        if (s.key === 'relation_types' && !out.short) out.short = out.label;
        return out;
      }),
    ])
  );

const applyVocabulary = (data) => {
  setVocabulary(data.vocabulary);
  Object.assign(vocab, toForm(data.vocabulary));
  vocabInfo.value = {
    usage: data.usage,
    version: data.version,
    customised: data.customised,
  };
};

const saveVocabulary = async () => {
  saving.value = true;
  try {
    const { data } = await axios.put(
      route('vocabulary.update', { project: props.projectId }),
      payload()
    );
    applyVocabulary(data);
    flashMessage('Vocabulary saved', { type: 'success' });
  } catch (e) {
    flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });
  } finally {
    saving.value = false;
  }
};

const resetVocabulary = async () => {
  if (
    !window.confirm('Return to the starter set? Entries still in use are kept.')
  )
    return;
  saving.value = true;
  try {
    const { data } = await axios.delete(
      route('vocabulary.reset', { project: props.projectId })
    );
    applyVocabulary(data);
  } catch (e) {
    flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });
  } finally {
    saving.value = false;
  }
};

// ---------------------------------------------------------------- the plan
const plan = reactive(
  (props.design.plan ?? []).map((s) => ({ ...s, _id: nextId() }))
);
const addStep = () =>
  plan.push({
    id: null,
    title: '',
    description: '',
    done: false,
    _id: nextId(),
  });
const move = (i, d) => {
  const [step] = plan.splice(i, 1);
  plan.splice(i + d, 0, step);
};
const savePlan = async () => {
  saving.value = true;
  try {
    const { data } = await axios.put(
      route('design.plan', { project: props.projectId }),
      {
        plan: plan
          .filter((s) => s.title.trim())
          .map(({ _id, ...s }) => (void _id, s)),
      }
    );
    plan.splice(
      0,
      plan.length,
      ...data.design.plan.map((s) => ({ ...s, _id: nextId() }))
    );
    flashMessage('Plan saved', { type: 'success' });
  } catch (e) {
    flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });
  } finally {
    saving.value = false;
  }
};
</script>
