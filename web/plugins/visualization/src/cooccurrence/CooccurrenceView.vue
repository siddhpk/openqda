<script setup>
import { computed, inject, ref } from 'vue';
import { cooccurrence, jaccard, orderCodes, toCsv } from '../matrix/helpers.js';

/**
 * Code co-occurrence matrix: how often segments of two codes overlap
 * (or lie close together) in the same source – a display for noting
 * relations between codes before making them explicit as relationships.
 */
defineEmits(['remove']);
const props = defineProps([
  'sources',
  'codes',
  'checkedSources',
  'checkedCodes',
  'hasSelections',
  'menu',
  'showMenu',
]);
const { Button } = inject('components');
// relationships between codes exist only with the Analysis Toolkit module
const canRelate = Array.isArray(inject('api', null)?.relations);

const distance = ref(0);
const normalize = ref(false);
const detail = ref(null);

const codes = computed(() =>
  orderCodes(
    (props.codes ?? []).filter((c) => props.checkedCodes?.get(c.id))
  ).map((r) => r.code)
);
const sourceIds = computed(() =>
  (props.sources ?? [])
    .filter((s) => props.checkedSources?.get(s.id))
    .map((s) => s.id)
);
const result = computed(() =>
  cooccurrence(codes.value, {
    distance: Number(distance.value),
    sourceIds: sourceIds.value,
  })
);
const value = (i, j) =>
  i === j
    ? result.value.counts[i][i]
    : normalize.value
      ? jaccard(result.value.counts, i, j)
      : result.value.counts[i][j];
const max = computed(() => {
  let m = 0;
  codes.value.forEach((_, i) =>
    codes.value.forEach((__, j) => i !== j && (m = Math.max(m, value(i, j))))
  );
  return m || 1;
});
const shade = (i, j) => {
  if (i === j) return 'rgba(0,0,0,0.06)';
  const v = value(i, j);
  return v
    ? `rgba(217, 119, 6, ${0.12 + 0.65 * (v / max.value)})`
    : 'transparent';
};
const fmt = (i, j) => {
  const v = value(i, j);
  if (!v) return '';
  return normalize.value && i !== j ? v.toFixed(2) : v;
};

const exportCsv = () => {
  const head = ['', ...codes.value.map((c) => c.name)];
  const body = codes.value.map((c, i) => [
    c.name,
    ...codes.value.map((_, j) => value(i, j)),
  ]);
  const blob = new Blob([toCsv([head, ...body])], {
    type: 'text/csv;charset=utf-8',
  });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'code-cooccurrence.csv';
  a.click();
  URL.revokeObjectURL(a.href);
};
</script>

<template>
  <div class="w-full text-sm">
    <div class="flex flex-wrap items-center gap-2 p-2">
      <label class="flex items-center gap-1">
        Co-occur when segments
        <select
          v-model="distance"
          class="text-sm rounded border-input bg-surface"
        >
          <option :value="0">overlap</option>
          <option :value="200">are ≤ 200 characters apart</option>
          <option :value="1000">are ≤ 1000 characters apart</option>
        </select>
      </label>
      <label class="flex items-center gap-1"
        ><input v-model="normalize" type="checkbox" class="rounded" /> normalize
        (Jaccard)</label
      >
      <span class="grow"></span>
      <component :is="Button" size="sm" variant="outline" @click="exportCsv"
        >CSV</component
      >
    </div>
    <p v-if="codes.length < 2" class="p-4 italic opacity-70">
      Select at least two codes.
    </p>
    <div v-else class="overflow-auto max-h-[70vh] border rounded">
      <table class="border-collapse text-xs">
        <thead class="sticky top-0 bg-surface z-10">
          <tr>
            <th class="sticky left-0 bg-surface p-2 border-b"></th>
            <th
              v-for="c in codes"
              :key="c.id"
              class="p-1 border-b border-l align-bottom h-32"
            >
              <div
                class="[writing-mode:vertical-rl] rotate-180 whitespace-nowrap max-h-32 truncate"
                :title="c.name"
              >
                {{ c.name }}
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in codes" :key="row.id">
            <td class="sticky left-0 bg-surface p-1 border-b whitespace-nowrap">
              <span
                class="inline-block w-2 h-2 rounded-full me-1"
                :style="{ background: row.color }"
              ></span
              >{{ row.name }}
            </td>
            <td
              v-for="(col, j) in codes"
              :key="col.id"
              class="border-b border-l p-1 text-center min-w-[2.5rem]"
              :class="i !== j && result.counts[i][j] ? 'cursor-pointer' : ''"
              :style="{ background: shade(i, j) }"
              :title="
                i === j
                  ? `${row.name}: ${result.counts[i][i]} segments`
                  : `${row.name} & ${col.name}: ${result.counts[i][j]}`
              "
              @click="
                i !== j &&
                result.counts[i][j] &&
                (detail = { a: row, b: col, pairs: result.pairs[i][j] })
              "
            >
              {{ fmt(i, j) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="detail" class="mt-3 border rounded p-2">
      <div class="flex justify-between items-center">
        <b>{{ detail.a.name }} &amp; {{ detail.b.name }}</b>
        <button class="text-xs underline" @click="detail = null">close</button>
      </div>
      <p v-if="canRelate" class="text-xs opacity-70">
        If this pairing is meaningful, record it as a relationship on the Map
        page.
      </p>
      <ul class="divide-y max-h-80 overflow-auto">
        <li v-for="([a, b], k) in detail.pairs" :key="k" class="py-1 text-xs">
          <div>“{{ a.text }}”</div>
          <div class="opacity-70">“{{ b.text }}”</div>
        </li>
      </ul>
    </div>
  </div>
</template>
