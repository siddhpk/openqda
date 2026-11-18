<script setup>
/*------------------------------------------------------------------------------
 | EvidencePanel.vue – coded segments used as evidence for a concept or a
 | proposition, grouped by stance. Challenging evidence keeps a resolution so
 | disconfirming material stays visible until it is dealt with.
 *----------------------------------------------------------------------------*/
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import Button from '../../Components/interactive/Button.vue';
import { flashMessage } from '../../Components/notification/flashMessage.js';
import { openInSource } from './openInSource.js';

const props = defineProps({
  projectId: { type: [String, Number], required: true },
  targetType: { type: String, required: true }, // concept | proposition
  targetId: { type: String, required: true },
  evidence: { type: Array, required: true },
  codes: { type: Array, required: true },
  sources: { type: Array, required: true },
  // definition or statement, used to rank candidates by similarity
  queryText: { type: String, default: '' },
});
const emit = defineEmits(['changed']);

const STANCES = [
  { key: 'supports', label: 'Supporting' },
  { key: 'challenges', label: 'Challenging' },
  { key: 'qualifies', label: 'Qualifying' },
  { key: 'illustrates', label: 'Illustrating' },
];
const RESOLUTIONS = [
  { key: 'open', label: 'open' },
  { key: 'accounted_for', label: 'accounted for' },
  { key: 'revised', label: 'led to a revision' },
  { key: 'unresolved', label: 'unresolved' },
];
const inputClass = 'text-xs rounded border-input bg-surface';
const codeName = (id) => props.codes.find((c) => c.id === id)?.name ?? '';
const codeColor = (id) => props.codes.find((c) => c.id === id)?.color;
const grouped = computed(() =>
  STANCES.map((s) => ({
    ...s,
    items: props.evidence.filter((e) => e.stance === s.key),
  }))
);
const openChallenges = computed(
  () =>
    props.evidence.filter(
      (e) =>
        e.stance === 'challenges' &&
        ['open', 'unresolved', null].includes(e.resolution)
    ).length
);

const fail = (e) =>
  flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });

const update = async (item, changes) => {
  try {
    const { data } = await axios.put(
      route('evidence.update', {
        project: props.projectId,
        evidence: item.id,
      }),
      changes
    );
    emit('changed', data.evidence);
  } catch (e) {
    fail(e);
  }
};
const remove = async (item) => {
  try {
    const { data } = await axios.delete(
      route('evidence.destroy', {
        project: props.projectId,
        evidence: item.id,
      })
    );
    emit('changed', data.evidence);
  } catch (e) {
    fail(e);
  }
};

// ---- finding segments --------------------------------------------------------
const finder = reactive({
  open: false,
  code: '',
  source: '',
  q: '',
  page: 1,
  stance: 'supports',
  note: '',
  loading: false,
  results: [],
  total: 0,
  lastPage: 1,
  linkedCodes: 0,
  picked: {},
});
const search = async (page = 1) => {
  finder.loading = true;
  try {
    const { data } = await axios.get(
      route('evidence.candidates', {
        project: props.projectId,
        target_type: props.targetType,
        target_id: props.targetId,
        code_id: finder.code || undefined,
        source_id: finder.source || undefined,
        q: finder.q || undefined,
        page,
      })
    );
    finder.results = data.candidates;
    finder.total = data.total;
    finder.page = data.page;
    finder.lastPage = data.lastPage;
    finder.linkedCodes = data.linkedCodes;
  } catch (e) {
    fail(e);
  } finally {
    finder.loading = false;
  }
};
// ---- optional: rank by embedding similarity (the only AI feature) -------------
// needs the Embeddings & Autocoding module; without it the button stays hidden
const aiModule = Object.values(
  import.meta.glob('../../domain/ai/useAi.js', { eager: true })
)[0];
const ai = aiModule
  ? aiModule.useAi()
  : { globallyEnabled: ref(false), projectEnabled: ref(false) };
onMounted(() => ai.globallyEnabled.value && ai.loadStatus().catch(() => {}));
const ranking = reactive({ busy: false, progress: 0, on: false });
const normalized = (v) => {
  const n = Math.hypot(...v) || 1;
  return v.map((x) => x / n);
};
const rank = async () => {
  ranking.busy = true;
  try {
    const { data } = await axios.get(
      route('evidence.candidates', {
        project: props.projectId,
        target_type: props.targetType,
        target_id: props.targetId,
        code_id: finder.code || undefined,
        source_id: finder.source || undefined,
        q: finder.q || undefined,
        all: 1,
      })
    );
    // what the candidates are compared with: the definition or statement
    // plus the supporting and qualifying evidence so far
    const anchors = [
      props.queryText,
      ...props.evidence
        .filter((e) => ['supports', 'qualifies'].includes(e.stance))
        .map((e) => e.text),
    ].filter((t) => t && t.trim());
    if (!anchors.length)
      throw new Error(
        'Write a definition (or statement) or add supporting evidence first – candidates are ranked by similarity to them.'
      );
    const texts = [...anchors, ...data.candidates.map((c) => c.text)];
    const { vectors } = await ai.embed(
      texts,
      (done, total) => (ranking.progress = done / total)
    );
    const dims = vectors[0].length;
    const centre = new Float64Array(dims);
    vectors
      .slice(0, anchors.length)
      .forEach((v) =>
        normalized(Array.from(v)).forEach((x, j) => (centre[j] += x))
      );
    const c = normalized(Array.from(centre));
    finder.results = data.candidates
      .map((cand, i) => {
        const v = normalized(Array.from(vectors[anchors.length + i]));
        return { ...cand, similarity: v.reduce((s, x, j) => s + x * c[j], 0) };
      })
      .sort((a, b) => b.similarity - a.similarity)
      .slice(0, 100);
    finder.total = data.total;
    finder.page = 1;
    finder.lastPage = 1;
    ranking.on = true;
  } catch (e) {
    fail(e);
  } finally {
    ranking.busy = false;
  }
};

const pickedIds = computed(() =>
  Object.keys(finder.picked).filter((id) => finder.picked[id])
);
const add = async () => {
  try {
    const { data } = await axios.post(
      route('evidence.store', { project: props.projectId }),
      {
        target_type: props.targetType,
        target_id: props.targetId,
        selection_ids: pickedIds.value,
        stance: finder.stance,
        note: finder.note || null,
      }
    );
    emit('changed', data.evidence);
    finder.picked = {};
    finder.note = '';
    flashMessage(`${data.created} segment(s) added as evidence`, {
      type: 'success',
    });
    search(finder.page);
  } catch (e) {
    fail(e);
  }
};
watch(
  () => props.targetId,
  () => {
    finder.open = false;
    finder.results = [];
    finder.picked = {};
  }
);
const editingNote = ref(null);
</script>

<template>
  <section class="space-y-2">
    <div class="flex items-center gap-2">
      <h3 class="font-semibold">Evidence</h3>
      <span class="text-xs text-foreground/60"
        >{{ evidence.length }} segment(s)<span v-if="openChallenges"
          >,
          <b class="text-destructive"
            >{{ openChallenges }} challenge(s) not yet dealt with</b
          ></span
        ></span
      >
      <span class="grow"></span>
      <Button
        size="sm"
        variant="outline"
        @click="(finder.open = !finder.open) && search(1)"
        >{{ finder.open ? 'Close' : 'Add evidence' }}</Button
      >
    </div>

    <div v-if="finder.open" class="border rounded p-2 space-y-2 text-xs">
      <div class="flex flex-wrap gap-2 items-center">
        <select v-model="finder.code" :class="inputClass" @change="search(1)">
          <option value="">
            Segments of linked codes ({{ finder.linkedCodes }} codes)
          </option>
          <option v-for="c in codes" :key="c.id" :value="c.id">
            {{ c.name }}
          </option>
        </select>
        <select v-model="finder.source" :class="inputClass" @change="search(1)">
          <option value="">All sources</option>
          <option v-for="s in sources" :key="s.id" :value="s.id">
            {{ s.name }}
          </option>
        </select>
        <input
          v-model="finder.q"
          :class="inputClass + ' w-40'"
          placeholder="Text contains…"
          @keyup.enter="search(1)"
        />
        <Button
          size="sm"
          variant="ghost"
          @click="
            ranking.on = false;
            search(1);
          "
          >Search</Button
        >
        <Button
          v-if="ai.projectEnabled.value"
          size="sm"
          variant="outline"
          :disabled="ranking.busy"
          title="Ranks the coded segments by embedding similarity to the definition and the supporting evidence. Similar wording is not the same as fitting the concept – read each one."
          @click="rank"
          >{{
            ranking.busy
              ? `Ranking… ${Math.round(ranking.progress * 100)}%`
              : 'Rank by similarity'
          }}</Button
        >
      </div>
      <p v-if="ranking.on" class="text-foreground/60">
        Ranked by embedding similarity to the definition and supporting evidence
        (top 100). Similarity is a prompt to read, not a judgement.
      </p>
      <p v-if="!finder.loading && !finder.results.length" class="italic">
        No coded segments found. Link codes to it, choose a code, or code the
        passage first.
      </p>
      <ul class="max-h-72 overflow-auto divide-y border rounded">
        <li
          v-for="r in finder.results"
          :key="r.selectionId"
          class="p-1.5 flex gap-2 items-start"
        >
          <input
            v-model="finder.picked[r.selectionId]"
            type="checkbox"
            class="rounded mt-0.5"
          />
          <div class="grow">
            <div>{{ r.text }}</div>
            <div class="text-foreground/60">
              {{ r.sourceName }} ·
              <span :style="{ color: codeColor(r.codeId) }">{{
                r.codeName
              }}</span>
              ·
              <a
                href="#"
                class="underline"
                @click.prevent="openInSource(r.sourceId, r.start, r.end)"
                >open</a
              ><span v-if="r.similarity !== undefined">
                · similarity {{ r.similarity.toFixed(2) }}</span
              >
            </div>
          </div>
        </li>
      </ul>
      <div class="flex flex-wrap items-center gap-2">
        <span>{{ finder.total }} found</span>
        <Button
          v-if="finder.page > 1"
          size="sm"
          variant="ghost"
          @click="search(finder.page - 1)"
          >←</Button
        >
        <Button
          v-if="finder.page < finder.lastPage"
          size="sm"
          variant="ghost"
          @click="search(finder.page + 1)"
          >→</Button
        >
        <span class="grow"></span>
        <select v-model="finder.stance" :class="inputClass">
          <option v-for="s in STANCES" :key="s.key" :value="s.key">
            as {{ s.label.toLowerCase() }}
          </option>
        </select>
        <input
          v-model="finder.note"
          :class="inputClass + ' w-48'"
          placeholder="Note (optional)"
        />
        <Button size="sm" :disabled="!pickedIds.length" @click="add"
          >Add {{ pickedIds.length || '' }}</Button
        >
      </div>
    </div>

    <div v-for="g in grouped" :key="g.key">
      <div class="text-xs font-medium mt-2">
        {{ g.label }} ({{ g.items.length }})
      </div>
      <ul v-if="g.items.length" class="divide-y border rounded text-xs">
        <li v-for="e in g.items" :key="e.id" class="p-2 space-y-1">
          <div :class="e.segmentRemoved && 'opacity-70'">“{{ e.text }}”</div>
          <div class="flex flex-wrap items-center gap-2 text-foreground/60">
            <span>{{ e.sourceName }}</span>
            <span v-if="e.codeId" :style="{ color: codeColor(e.codeId) }">{{
              codeName(e.codeId)
            }}</span>
            <span v-if="e.segmentRemoved" title="The coded segment was removed"
              >segment removed</span
            >
            <a
              href="#"
              class="underline"
              @click.prevent="openInSource(e.sourceId, e.start, e.end)"
              >open in source</a
            >
            <span class="grow"></span>
            <select
              :value="e.stance"
              :class="inputClass"
              @change="update(e, { stance: $event.target.value })"
            >
              <option v-for="s in STANCES" :key="s.key" :value="s.key">
                {{ s.label }}
              </option>
            </select>
            <button class="underline" @click="remove(e)">remove</button>
          </div>
          <div v-if="e.stance === 'challenges'" class="flex flex-wrap gap-2">
            <select
              :value="e.resolution ?? 'open'"
              :class="inputClass"
              @change="update(e, { resolution: $event.target.value })"
            >
              <option v-for="r in RESOLUTIONS" :key="r.key" :value="r.key">
                {{ r.label }}
              </option>
            </select>
            <input
              :value="e.resolutionNote"
              :class="inputClass + ' grow'"
              placeholder="How it was dealt with"
              @change="update(e, { resolution_note: $event.target.value })"
            />
          </div>
          <div v-if="editingNote === e.id">
            <input
              :value="e.note"
              :class="inputClass + ' w-full'"
              placeholder="Note"
              @change="
                update(e, { note: $event.target.value });
                editingNote = null;
              "
            />
          </div>
          <button
            v-else
            class="text-left text-foreground/70"
            @click="editingNote = e.id"
          >
            {{ e.note || 'Add a note…' }}
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>
