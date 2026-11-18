<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Codes } from '../../../domain/codes/Codes.js';
import { useSelections } from '../selections/useSelections.js';
import { useCodingEditor } from '../useCodingEditor.js';
import { editorText } from './segment.js';
import { useAutocodeEngine } from './useAutocodeEngine.js';
import AiConsent from '../ai/AiConsent.vue';
import AutocodeOptions from './AutocodeOptions.vue';
import AutocodeModelCard from './AutocodeModelCard.vue';
import AutocodeSuggestion from './AutocodeSuggestion.vue';
import Button from '../../../Components/interactive/Button.vue';
import Link from '../../../Components/Link.vue';
import { flashMessage } from '../../../Components/notification/flashMessage.js';

/*------------------------------------------------------------------------------
 | AutocodePanel.vue
 | Autocoding of the source that is open in the editor. Accepted codes are
 | written as ordinary selections and appear immediately in the editor;
 | several codes per unit are possible. For many sources at once use the
 | batch page.
 *----------------------------------------------------------------------------*/

const props = defineProps({ source: Object });
const page = usePage();
const projectId = page.props.projectId;
const codeStore = Codes.by(`${projectId}-${props.source?.id}`);
const { createSelection } = useSelections();
const editor = useCodingEditor();
const {
  ai,
  options,
  state,
  prepare,
  run,
  chosen,
  suggested,
  toggle,
  accept,
  dismiss,
  defer,
  reset,
  NONE,
} = useAutocodeEngine();
const limit = ref(100);
const showAll = ref(false);

onMounted(() => ai.loadStatus().catch(() => {}));

const codes = computed(() => codeStore.all());
const codeById = computed(() => new Map(codes.value.map((c) => [c.id, c])));
const codeName = (id) =>
  id === NONE ? 'No code' : (codeById.value.get(id)?.name ?? 'Unknown code');
const codeColor = (id) => codeById.value.get(id)?.color ?? 'rgba(0,0,0,0.3)';

const start = () => {
  const quill = editor.instance();
  if (!quill)
    return flashMessage('Open a source in the editor first.', {
      type: 'error',
    });
  // use the editor's live text for the open source (exact offsets)
  prepare([props.source.id], {
    textOverrides: { [props.source.id]: editorText(quill) },
  });
};

// deferred units ("Later") go to the end of the list
const open = computed(() =>
  state.items
    .filter((i) => i.status === 'open')
    .sort((a, b) => Number(a.deferred) - Number(b.deferred))
);
const visible = computed(() =>
  open.value
    .filter((i) => showAll.value || suggested(i).length)
    .slice(0, limit.value)
);
const openSuggested = computed(() =>
  open.value.filter((i) => suggested(i).length)
);

const writeLive = async (pairs) => {
  let written = 0;
  for (const { item, codeId } of pairs) {
    let code = null;
    try {
      code = codeStore.entry(codeId);
    } catch {
      code = null;
    }
    if (!code) continue;
    await createSelection({
      code,
      index: item.unit.start,
      length: item.unit.end - item.unit.start,
      text: item.unit.text,
      origin: state.run?.origin,
    });
    written++;
  }
  if (state.run?.id)
    ai.updateRun(state.run.id, state.accepted + written).catch(() => {});
  return written;
};

const acceptOne = async (item) => {
  try {
    await accept([item], writeLive);
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};
const acceptAll = async () => {
  try {
    const n = await accept(openSuggested.value, writeLive);
    flashMessage(`${n} codings added`, { type: 'success' });
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};
const focus = (item) => {
  try {
    editor.focusSelection({ start: item.unit.start, end: item.unit.end });
  } catch {
    // editor not ready
  }
};
const percent = (v) => `${Math.round((v ?? 0) * 100)}%`;
</script>

<template>
  <div class="p-3 space-y-4 text-sm">
    <AiConsent />

    <template v-if="ai.projectEnabled.value">
      <div class="flex items-center justify-between text-xs">
        <span>This source only.</span>
        <Link :href="route('autocode.show', projectId)" class="underline"
          >Batch autocoding of several sources →</Link
        >
      </div>

      <details class="text-xs text-foreground/70 border rounded-md p-2">
        <summary class="cursor-pointer font-medium text-foreground">
          How autocoding works
        </summary>
        <p class="mt-2">
          Sentences or paragraphs are turned into embeddings by your embedding
          service. Then, in your browser, either each code is represented by the
          average of its coded units and new units are scored by their
          similarity to it (compared with uncoded text), or one penalised
          logistic regression per code is trained on the full embeddings. Every
          code is judged on its own, and every code above its cut-off is
          suggested, so a unit can get several codes.
        </p>
        <p class="mt-2">
          Quality is measured on whole sources the models did not see (or, with
          few coded sources, on held-out units), and cut-offs are tuned without
          looking at the data the figures come from. Scores rank units from 0 to
          1; they are not probabilities.
        </p>
        <p class="mt-2">
          Suggestions are prompts to look again, never decisions: they can only
          repeat patterns in the coding you have done so far. Accepted
          suggestions are marked so they can be told apart.
        </p>
      </details>

      <template v-if="state.step === 'idle' || state.step === 'configure'">
        <AutocodeOptions
          :options="options"
          @changed="
            (key, value) => ((options[key] = value), (state.step = 'idle'))
          "
        />
        <Button v-if="state.step === 'idle'" class="w-full" @click="start"
          >Prepare</Button
        >
        <div v-else class="space-y-2">
          <div class="text-xs text-foreground/70">
            {{ state.corpus.targets.length }} {{ options.granularity }}s to
            autocode. Training: {{ state.corpus.stats.positives }} coded units
            ({{ state.corpus.stats.multiCoded }} with several codes) and
            {{ state.corpus.stats.negatives }} uncoded units from other sources.
          </div>
          <ul class="max-h-48 overflow-y-auto border rounded divide-y text-xs">
            <li
              v-for="(n, id) in state.corpus.stats.counts"
              :key="id"
              class="flex items-center gap-2 px-2 py-1"
            >
              <input
                v-model="state.include[id]"
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
                  n < options.minExamples
                    ? 'text-destructive'
                    : 'text-foreground/60'
                "
                >{{ n }}</span
              >
            </li>
          </ul>
          <Button class="w-full" @click="run(codes)">Run autocoding</Button>
        </div>
      </template>

      <p
        v-if="state.error"
        class="text-xs text-destructive border border-destructive rounded p-2"
      >
        {{ state.error }}
      </p>

      <div v-if="state.step === 'running'" class="space-y-2">
        <div class="text-xs">{{ state.label }}…</div>
        <div class="w-full h-2 bg-foreground/10 rounded">
          <div
            class="h-2 bg-primary rounded transition-all"
            :style="{ width: percent(state.progress) }"
          ></div>
        </div>
      </div>

      <template v-if="state.step === 'done' && state.result">
        <AutocodeModelCard :result="state.result" :code-name="codeName" />
        <div class="space-y-1 text-xs">
          <label class="flex items-center gap-2">
            <input v-model="options.thresholdMode" type="radio" value="tuned" />
            cut-offs tuned per code (recommended)
          </label>
          <label class="flex items-center gap-2">
            <input v-model="options.thresholdMode" type="radio" value="fixed" />
            one cut-off:
            <input
              v-model.number="options.fixedThreshold"
              type="range"
              min="0.1"
              max="0.9"
              step="0.05"
              :disabled="options.thresholdMode !== 'fixed'"
            />
            {{ options.fixedThreshold.toFixed(2) }}
          </label>
          <label class="flex items-center gap-2"
            ><input v-model="showAll" type="checkbox" class="rounded" /> show
            all units</label
          >
        </div>
        <div class="flex items-center gap-2">
          <Button
            size="sm"
            variant="outline-secondary"
            class="grow"
            :disabled="!openSuggested.length"
            @click="acceptAll"
          >
            Accept all {{ openSuggested.length }}
          </Button>
          <Button size="sm" variant="outline" @click="reset">New run</Button>
        </div>
        <div class="text-xs text-foreground/60">
          {{ state.accepted }} codings accepted
        </div>
        <div v-if="state.decisionErrors" class="text-xs text-destructive">
          {{ state.decisionErrors }} decisions could not be recorded in the
          project's audit trail (the codings themselves were saved).
        </div>
        <ul class="divide-y border rounded">
          <AutocodeSuggestion
            v-for="item in visible"
            :key="item.index"
            :item="item"
            :chosen="chosen(item)"
            :code-name="codeName"
            :code-color="codeColor"
            :none="NONE"
            @focus="focus"
            @toggle="toggle"
            @accept="acceptOne"
            @dismiss="dismiss"
            @defer="defer"
          />
          <li v-if="!visible.length" class="p-2 italic text-xs">
            No open suggestions.
          </li>
          <li v-if="open.length > limit" class="p-2">
            <Button
              size="sm"
              variant="ghost"
              class="w-full"
              @click="limit += 100"
              >Show more</Button
            >
          </li>
        </ul>
      </template>
    </template>
  </div>
</template>
