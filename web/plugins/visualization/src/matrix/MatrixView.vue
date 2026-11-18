<script setup>
import { computed, inject, ref } from 'vue';
import {
  buildColumns,
  orderCodes,
  segmentsIn,
  toCsv,
  variableNames,
} from './helpers.js';

/**
 * Code × source matrix. Columns can be
 * partitioned by a source variable; cells show counts or excerpts.
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

const variable = ref('');
const cellMode = ref('count');
const detail = ref(null);

const sources = computed(() =>
  (props.sources ?? []).filter((s) => props.checkedSources?.get(s.id))
);
const rows = computed(() =>
  orderCodes((props.codes ?? []).filter((c) => props.checkedCodes?.get(c.id)))
);
const variables = computed(() => variableNames(props.sources ?? []));
const columns = computed(() =>
  buildColumns(sources.value, variable.value || null)
);
const cells = computed(() =>
  rows.value.map(({ code }) =>
    columns.value.map((col) => segmentsIn(code, col.sourceIds))
  )
);
const max = computed(() =>
  Math.max(1, ...cells.value.flat().map((c) => c.length))
);
const shade = (n) =>
  n ? `rgba(37, 99, 235, ${0.12 + 0.6 * (n / max.value)})` : 'transparent';

const exportCsv = () => {
  const head = ['Code', ...columns.value.map((c) => c.label)];
  const body = rows.value.map(({ code }, i) => [
    code.name,
    ...cells.value[i].map((c) => c.length),
  ]);
  const blob = new Blob([toCsv([head, ...body])], {
    type: 'text/csv;charset=utf-8',
  });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'code-source-matrix.csv';
  a.click();
  URL.revokeObjectURL(a.href);
};
</script>

<template>
  <div class="w-full text-sm">
    <div class="flex flex-wrap items-center gap-2 p-2">
      <label class="flex items-center gap-1">
        Columns
        <select
          v-model="variable"
          class="text-sm rounded border-input bg-surface"
        >
          <option value="">one per source</option>
          <option v-for="v in variables" :key="v" :value="v">
            grouped by “{{ v }}”
          </option>
        </select>
      </label>
      <label class="flex items-center gap-1">
        Cells
        <select
          v-model="cellMode"
          class="text-sm rounded border-input bg-surface"
        >
          <option value="count">counts</option>
          <option value="excerpts">excerpts</option>
        </select>
      </label>
      <span class="grow"></span>
      <component :is="Button" size="sm" variant="outline" @click="exportCsv"
        >CSV</component
      >
    </div>
    <p v-if="!rows.length || !columns.length" class="p-4 italic opacity-70">
      Select codes and sources to build the matrix.
    </p>
    <div v-else class="overflow-auto max-h-[70vh] border rounded">
      <table class="border-collapse text-xs">
        <thead class="sticky top-0 bg-surface z-10">
          <tr>
            <th class="sticky left-0 bg-surface p-2 text-left border-b">
              Code
            </th>
            <th
              v-for="col in columns"
              :key="col.key"
              class="p-2 border-b border-l max-w-[10rem] truncate"
              :title="col.label"
            >
              {{ col.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, i) in rows"
            :key="row.code.id"
            class="hover:bg-foreground/5"
          >
            <td
              class="sticky left-0 bg-surface p-1 border-b whitespace-nowrap"
              :style="{ paddingLeft: `${0.5 + row.depth}rem` }"
            >
              <span
                class="inline-block w-2 h-2 rounded-full me-1"
                :style="{ background: row.code.color }"
              ></span
              >{{ row.code.name }}
            </td>
            <td
              v-for="(cell, j) in cells[i]"
              :key="columns[j].key"
              class="border-b border-l p-1 align-top cursor-pointer"
              :style="{
                background:
                  cellMode === 'count' ? shade(cell.length) : 'transparent',
              }"
              :title="`${row.code.name} × ${columns[j].label}: ${cell.length}`"
              @click="
                detail = cell.length
                  ? { code: row.code, column: columns[j], segments: cell }
                  : null
              "
            >
              <span v-if="cellMode === 'count'" class="block text-center">{{
                cell.length || ''
              }}</span>
              <ul v-else class="space-y-1 max-w-[14rem]">
                <li v-for="s in cell.slice(0, 2)" :key="s.id" class="italic">
                  “{{
                    s.text.length > 90 ? s.text.slice(0, 90) + '…' : s.text
                  }}”
                </li>
                <li v-if="cell.length > 2" class="opacity-60">
                  +{{ cell.length - 2 }} more
                </li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="detail" class="mt-3 border rounded p-2">
      <div class="flex justify-between items-center">
        <b
          >{{ detail.code.name }} × {{ detail.column.label }} ({{
            detail.segments.length
          }})</b
        >
        <button class="text-xs underline" @click="detail = null">close</button>
      </div>
      <ul class="divide-y max-h-80 overflow-auto">
        <li v-for="s in detail.segments" :key="s.id" class="py-1 text-xs">
          “{{ s.text }}”
          <span class="opacity-60"
            >– {{ props.sources.find((x) => x.id === s.source_id)?.name }}</span
          >
        </li>
      </ul>
    </div>
  </div>
</template>
