<script setup>
/*------------------------------------------------------------------------------
 | AutocodeModelCard.vue – transparent summary of an autocoding run.
 *----------------------------------------------------------------------------*/
const props = defineProps({
  result: { type: Object, required: true },
  codeName: { type: Function, required: true },
});
const percent = (v) =>
  v === null || v === undefined ? '–' : `${Math.round(v * 100)}%`;
const f2 = (v) => (v === null || v === undefined ? '–' : v.toFixed(2));
const label = (m) => (m === 'similarity' ? 'Similarity' : 'Logistic');
const perClass = (id) =>
  props.result.metrics?.perClass.find((m) => m.classId === id);
const validationText = () => {
  const v = props.result.validation;
  if (!v || v.scheme === 'none') return null;
  const how =
    v.scheme === 'sources'
      ? `whole sources held out (${v.folds} folds over ${v.groups} sources)`
      : `units held out within sources (${v.folds} folds)`;
  return `${how}, ${v.evaluated} units scored by models that never saw them; cut-offs were tuned inside the training folds only`;
};
</script>

<template>
  <div class="border rounded-md p-2 space-y-1 text-xs">
    <div class="font-semibold text-sm">Model card</div>
    <div>{{ result.model.name }}</div>
    <div v-if="result.comparison.length > 1" class="flex flex-wrap gap-2">
      <span
        v-for="c in result.comparison"
        :key="c.model"
        :class="[
          'px-1 rounded border',
          c.chosen && 'border-primary font-semibold',
        ]"
      >
        {{ label(c.model) }}: micro-F1 {{ f2(c.microF1)
        }}{{ c.chosen ? ' ✓' : '' }}
      </span>
    </div>
    <div v-if="result.metrics">
      Held-out quality: micro-F1
      <b>{{ f2(result.metrics.microF1) }}</b
      >, macro-F1 {{ f2(result.metrics.macroF1) }}, all codes right for
      {{ percent(result.metrics.exactMatch) }} of units
      <div class="text-foreground/60">{{ validationText() }}.</div>
    </div>
    <div>
      Full {{ result.model.dimensions }}-dimensional embeddings (no
      reduction)<span v-if="result.model.lambda"
        >, ridge λ = {{ result.model.lambda }}</span
      >
    </div>
    <div v-if="result.model.scale" class="text-foreground/60">
      Scores: {{ result.model.scale }}.
    </div>
    <div>
      {{ result.model.nTrain }} training units ({{
        result.model.multiCoded
      }}
      with several codes, {{ result.model.negatives }} without code)
    </div>
    <details>
      <summary class="cursor-pointer">Per code</summary>
      <p class="mt-1 text-foreground/60">
        Examples: your coded units. Held out: units of the code that were scored
        without being seen. Precision: share of the suggestions for the code
        that matched your coding. Recall: share of your coded units it found.
        Few held-out units make both very uncertain.
      </p>
      <table class="w-full mt-1">
        <thead>
          <tr class="text-left text-foreground/60">
            <th>Code</th>
            <th title="coded units used for training">Examples</th>
            <th title="held-out units with this code">Held out</th>
            <th title="precision">Prec.</th>
            <th title="recall">Recall</th>
            <th>F1</th>
            <th title="cut-off for suggesting the code">Cut-off</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="c in result.classes.filter((c) => c.threshold !== null)"
            :key="c.id"
          >
            <td
              class="truncate max-w-[10rem]"
              :title="
                c.rolledUp.length
                  ? 'includes ' + c.rolledUp.map(codeName).join(', ')
                  : ''
              "
            >
              {{ codeName(c.id)
              }}<span v-if="c.rolledUp.length">
                (+{{ c.rolledUp.length }})</span
              >
            </td>
            <td>{{ c.count }}</td>
            <td>{{ perClass(c.id)?.support ?? '–' }}</td>
            <td>{{ f2(perClass(c.id)?.precision) }}</td>
            <td>{{ f2(perClass(c.id)?.recall) }}</td>
            <td>{{ f2(perClass(c.id)?.f1) }}</td>
            <td>{{ f2(c.threshold) }}</td>
          </tr>
        </tbody>
      </table>
      <div v-if="result.excluded.length" class="mt-1 text-foreground/60">
        Left out (too few units):
        {{ result.excluded.map((e) => e.name).join(', ') }}
      </div>
    </details>
    <ul v-if="result.warnings.length" class="text-destructive list-disc ms-4">
      <li v-for="w in result.warnings" :key="w">{{ w }}</li>
    </ul>
  </div>
</template>
