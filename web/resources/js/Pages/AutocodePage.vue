<template>
  <AuthenticatedLayout
    :menu="true"
    :showFooter="false"
    :title="`Batch autocoding – ${props.project?.name ?? ''}`"
  >
    <template #menu>
      <BaseContainer>
        <div class="space-y-4 p-1 text-sm">
          <AiConsent />
          <template v-if="ai.projectEnabled.value">
            <section class="space-y-2">
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">Sources to autocode</h3>
                <div class="flex gap-2 text-xs">
                  <button class="underline" @click="selectUncoded">
                    uncoded
                  </button>
                  <button class="underline" @click="selectAll(true)">
                    all
                  </button>
                  <button class="underline" @click="selectAll(false)">
                    none
                  </button>
                </div>
              </div>
              <ul
                class="max-h-64 overflow-y-auto border rounded divide-y text-xs"
              >
                <li
                  v-for="s in props.sources"
                  :key="s.id"
                  class="flex items-center gap-2 px-2 py-1"
                >
                  <input
                    v-model="selected[s.id]"
                    type="checkbox"
                    class="rounded"
                    @change="engine.state.step = 'idle'"
                  />
                  <span class="grow truncate" :title="s.name">{{
                    s.name
                  }}</span>
                  <span
                    class="text-foreground/50"
                    :title="`${s.selectionsCount} coded segments`"
                    >{{ s.selectionsCount }}</span
                  >
                </li>
                <li v-if="!props.sources.length" class="p-2 italic">
                  No converted sources yet.
                </li>
              </ul>
              <p class="text-xs text-foreground/60">
                {{ targetIds.length }} selected. Coded sources always serve as
                training data; their already coded units are skipped unless you
                include them below.
              </p>
            </section>

            <template
              v-if="
                engine.state.step === 'idle' ||
                engine.state.step === 'configure'
              "
            >
              <AutocodeOptions
                :options="engine.options"
                @changed="
                  (key, value) => (
                    (engine.options[key] = value),
                    (engine.state.step = 'idle')
                  )
                "
              />
              <Button
                v-if="engine.state.step === 'idle'"
                class="w-full"
                :disabled="!targetIds.length"
                @click="engine.prepare(targetIds)"
              >
                Prepare {{ targetIds.length }} source(s)
              </Button>
              <div v-else class="space-y-2">
                <div class="text-xs text-foreground/70">
                  {{ engine.state.corpus.targets.length }} units to autocode.
                  Training: {{ engine.state.corpus.stats.positives }} coded
                  units ({{ engine.state.corpus.stats.multiCoded }} with several
                  codes), {{ engine.state.corpus.stats.negatives }}
                  uncoded units.
                </div>
                <ul
                  class="max-h-48 overflow-y-auto border rounded divide-y text-xs"
                >
                  <li
                    v-for="(n, id) in engine.state.corpus.stats.counts"
                    :key="id"
                    class="flex items-center gap-2 px-2 py-1"
                  >
                    <input
                      v-model="engine.state.include[id]"
                      type="checkbox"
                      class="rounded"
                    />
                    <span
                      class="w-3 h-3 rounded-full shrink-0"
                      :style="{ background: codeColor(id) }"
                    ></span>
                    <span class="grow truncate">{{ codeName(id) }}</span>
                    <span
                      :class="
                        n < engine.options.minExamples
                          ? 'text-destructive'
                          : 'text-foreground/60'
                      "
                      >{{ n }}</span
                    >
                  </li>
                </ul>
                <Button class="w-full" @click="engine.run(props.codes)"
                  >Run autocoding</Button
                >
              </div>
            </template>
            <p
              v-if="engine.state.error"
              class="text-xs text-destructive border border-destructive rounded p-2"
            >
              {{ engine.state.error }}
            </p>
            <AutocodeModelCard
              v-if="engine.state.step === 'done' && engine.state.result"
              :result="engine.state.result"
              :code-name="codeName"
            />
          </template>
        </div>
      </BaseContainer>
    </template>

    <template #main>
      <div class="p-3 md:p-6 space-y-4 text-sm">
        <div v-if="engine.state.step === 'running'" class="space-y-2 max-w-xl">
          <div>{{ engine.state.label }}…</div>
          <div class="w-full h-2 bg-foreground/10 rounded">
            <div
              class="h-2 bg-primary rounded transition-all"
              :style="{ width: percent(engine.state.progress) }"
            ></div>
          </div>
        </div>

        <div
          v-else-if="engine.state.step !== 'done'"
          class="max-w-2xl text-foreground/70 space-y-2"
        >
          <h2 class="text-lg font-semibold text-foreground">
            Batch autocoding
          </h2>
          <p>
            Select several interviews or documents on the left, prepare and run.
            The model learns from all coded sources at once and suggests codes
            for every selected source – several codes per sentence where your
            coding does that. Review the suggestions here; accepted ones are
            saved as coded segments marked as machine-suggested.
          </p>
        </div>

        <template v-else>
          <div class="flex flex-wrap items-center gap-2">
            <select
              v-model="filter.source"
              class="text-xs rounded border-input bg-surface"
            >
              <option value="">All sources ({{ open.length }})</option>
              <option v-for="(n, id) in openBySource" :key="id" :value="id">
                {{ engine.state.sourceNames[id] }} ({{ n }})
              </option>
            </select>
            <select
              v-model="filter.code"
              class="text-xs rounded border-input bg-surface"
            >
              <option value="">All codes</option>
              <option
                v-for="c in engine.state.result.classes.filter(
                  (c) => c.threshold !== null
                )"
                :key="c.id"
                :value="c.id"
              >
                {{ codeName(c.id) }}
              </option>
            </select>
            <label class="flex items-center gap-1 text-xs"
              ><input v-model="filter.all" type="checkbox" class="rounded" />
              also units without suggestion</label
            >
            <label class="flex items-center gap-1 text-xs">
              <input
                v-model="engine.options.thresholdMode"
                type="checkbox"
                true-value="fixed"
                false-value="tuned"
                class="rounded"
              />
              one cut-off
              <input
                v-if="engine.options.thresholdMode === 'fixed'"
                v-model.number="engine.options.fixedThreshold"
                type="range"
                min="0.1"
                max="0.9"
                step="0.05"
              />
              <span v-if="engine.options.thresholdMode === 'fixed'">{{
                engine.options.fixedThreshold.toFixed(2)
              }}</span>
            </label>
            <span class="grow"></span>
            <span class="text-xs text-foreground/60"
              >{{ engine.state.accepted }} codings saved</span
            >
            <span
              v-if="engine.state.decisionErrors"
              class="text-xs text-destructive"
              >{{ engine.state.decisionErrors }} decisions not recorded</span
            >
            <Button
              size="sm"
              variant="outline-secondary"
              :disabled="!filteredSuggested.length || saving"
              @click="acceptMany(filteredSuggested)"
            >
              Accept {{ filteredSuggested.length }} shown
            </Button>
            <Button size="sm" variant="outline" @click="engine.reset()"
              >New run</Button
            >
          </div>

          <ul class="divide-y border rounded max-w-4xl">
            <AutocodeSuggestion
              v-for="item in visible"
              :key="item.index"
              :item="item"
              :chosen="engine.chosen(item)"
              :code-name="codeName"
              :code-color="codeColor"
              :source-name="engine.state.sourceNames[item.unit.sourceId]"
              :none="engine.NONE"
              @focus="openInEditor"
              @toggle="engine.toggle"
              @accept="(i) => acceptMany([i])"
              @dismiss="engine.dismiss"
              @defer="engine.defer"
            />
            <li v-if="!visible.length" class="p-3 italic text-xs">
              No open suggestions for this filter.
            </li>
            <li v-if="filtered.length > limit" class="p-2">
              <Button
                size="sm"
                variant="ghost"
                class="w-full"
                @click="limit += 200"
                >Show more ({{ filtered.length - limit }} left)</Button
              >
            </li>
          </ul>
        </template>
      </div>
    </template>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout.vue';
import BaseContainer from '../Layouts/BaseContainer.vue';
import Button from '../Components/interactive/Button.vue';
import AiConsent from './coding/ai/AiConsent.vue';
import AutocodeOptions from './coding/autocode/AutocodeOptions.vue';
import AutocodeModelCard from './coding/autocode/AutocodeModelCard.vue';
import AutocodeSuggestion from './coding/autocode/AutocodeSuggestion.vue';
import { useAutocodeEngine } from './coding/autocode/useAutocodeEngine.js';
import { flashMessage } from '../Components/notification/flashMessage.js';

/*------------------------------------------------------------------------------
 | AutocodePage.vue – batch autocoding of several sources at once.
 *----------------------------------------------------------------------------*/

const props = defineProps(['project', 'projectId', 'sources', 'codes']);
const engine = useAutocodeEngine();
const { ai } = engine;
onMounted(() => ai.loadStatus().catch(() => {}));

const selected = reactive(
  Object.fromEntries(
    (props.sources ?? []).map((s) => [s.id, s.selectionsCount === 0])
  )
);
const targetIds = computed(() =>
  Object.keys(selected).filter((id) => selected[id])
);
const selectAll = (value) => {
  Object.keys(selected).forEach((id) => (selected[id] = value));
  engine.state.step = 'idle';
};
const selectUncoded = () => {
  (props.sources ?? []).forEach(
    (s) => (selected[s.id] = s.selectionsCount === 0)
  );
  engine.state.step = 'idle';
};

const codeById = computed(
  () => new Map((props.codes ?? []).map((c) => [c.id, c]))
);
const codeName = (id) =>
  id === engine.NONE
    ? 'No code'
    : (codeById.value.get(id)?.name ?? 'Unknown code');
const codeColor = (id) => codeById.value.get(id)?.color ?? 'rgba(0,0,0,0.3)';

const filter = reactive({ source: '', code: '', all: false });
const limit = ref(200);
// deferred units ("Later") go to the end of the list
const open = computed(() =>
  engine.state.items
    .filter((i) => i.status === 'open')
    .sort((a, b) => Number(a.deferred) - Number(b.deferred))
);
const openBySource = computed(() => {
  const out = {};
  open.value.forEach(
    (i) => (out[i.unit.sourceId] = (out[i.unit.sourceId] ?? 0) + 1)
  );
  return out;
});
const filtered = computed(() =>
  open.value.filter((i) => {
    if (filter.source && i.unit.sourceId !== filter.source) return false;
    const codes = engine.chosen(i);
    if (filter.code && !codes.includes(filter.code)) return false;
    return filter.all || codes.length > 0;
  })
);
const filteredSuggested = computed(() =>
  filtered.value.filter((i) => engine.chosen(i).length)
);
const visible = computed(() => filtered.value.slice(0, limit.value));

const saving = ref(false);
const writeBulk = async (pairs) => {
  const created = await ai.acceptBulk(
    engine.state.run.id,
    pairs.map(({ item, codeId }) => ({
      source_id: item.unit.sourceId,
      code_id: codeId,
      start: item.unit.start,
      end: item.unit.end,
      text: item.unit.text,
    }))
  );
  return created.length;
};
const acceptMany = async (items) => {
  saving.value = true;
  try {
    const n = await engine.accept(items, writeBulk);
    flashMessage(`${n} codings saved`, { type: 'success' });
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  } finally {
    saving.value = false;
  }
};

const openInEditor = (item) => {
  window.open(route('source.code', item.unit.sourceId), '_blank', 'noopener');
};
const percent = (v) => `${Math.round((v ?? 0) * 100)}%`;
</script>
