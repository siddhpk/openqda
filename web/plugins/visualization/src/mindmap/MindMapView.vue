<script setup>
import { computed, inject, ref } from 'vue';
import MindMapCanvas from './MindMapCanvas.vue';
import { fromFlat, filterTree } from './tree.js';
import { toFreeMind } from './freemind.js';
import {
  downloadBlob,
  downloadPng,
  downloadSvg,
  safeFilename,
} from './exporters.js';

/**
 * Read-only mind map for the analysis page. Respects the code filter and
 * shows relationships and memo counts. Editing happens on the Map page.
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
  'notes',
]);
const API = inject('api');
const { Button } = inject('components');

const mode = ref('mindmap');
// show only codes of one type (types come from the project vocabulary)
const typeFilter = ref('');
const codeTypes = computed(() => (API.codeTypes ? API.codeTypes() : []));
const typeLabel = (type) => (API.typeLabel ? API.typeLabel(type) : type);
const selected = ref(null);
const canvas = ref(null);

const visibleCodes = computed(() =>
  (props.codes ?? []).filter((c) => props.checkedCodes?.get(c.id))
);
const root = computed(() => {
  const tree = fromFlat(
    visibleCodes.value.map((c) => ({
      ...c,
      selectionsCount: c.text?.length ?? 0,
    })),
    'Codes'
  );
  return typeFilter.value
    ? filterTree(tree, (n) => (n.kind ?? 'code') === typeFilter.value)
    : tree;
});
const relations = computed(() => API.relations ?? []);
const notesCount = computed(() => {
  const out = {};
  (props.notes ?? []).forEach((n) => {
    if (n.type === 'code') out[n.target] = (out[n.target] ?? 0) + 1;
  });
  return out;
});
const relationLabel = (r) =>
  API.relationLabel ? API.relationLabel(r) : (r.label ?? r.type);

const exportMm = () => {
  const notesByCode = {};
  (props.notes ?? []).forEach((n) => {
    if (n.type === 'code') (notesByCode[n.target] ??= []).push(n.content);
  });
  const xml = toFreeMind({
    root: root.value,
    relations: relations.value,
    notesByCode,
    sides: canvas.value?.sides(),
  });
  downloadBlob(
    new Blob([xml], { type: 'application/x-freemind;charset=utf-8' }),
    safeFilename('codes', 'mm')
  );
};
</script>

<template>
  <div class="w-full h-[75vh] flex flex-col">
    <div class="flex flex-wrap items-center gap-2 p-2 text-sm">
      <select v-model="mode" class="text-sm rounded border-input bg-surface">
        <option value="mindmap">Mind map</option>
        <option value="focus">Focus view</option>
        <option value="network">Relationship network</option>
      </select>
      <select
        v-if="mode === 'mindmap' && codeTypes.length > 1"
        v-model="typeFilter"
        class="text-sm rounded border-input bg-surface"
        title="Show only codes of one type"
      >
        <option value="">All code types</option>
        <option v-for="t in codeTypes" :key="t.key" :value="t.key">
          only {{ t.label }}
        </option>
      </select>
      <label v-if="mode === 'focus'" class="flex items-center gap-1 text-xs">
        Center:
        <select
          v-model="selected"
          class="text-sm rounded border-input bg-surface"
        >
          <option :value="null">select a code</option>
          <option v-for="c in visibleCodes" :key="c.id" :value="c.id">
            {{ c.name }}
          </option>
        </select>
      </label>
      <span class="grow"></span>
      <component :is="Button" size="sm" variant="outline" @click="canvas?.fit()"
        >Fit</component
      >
      <component :is="Button" size="sm" variant="outline" @click="exportMm"
        >.mm</component
      >
      <component
        :is="Button"
        size="sm"
        variant="outline"
        @click="downloadSvg(canvas.svg(), 'codes')"
        >SVG</component
      >
      <component
        :is="Button"
        size="sm"
        variant="outline"
        @click="downloadPng(canvas.svg(), 'codes')"
        >PNG</component
      >
    </div>
    <div class="grow border rounded bg-white">
      <MindMapCanvas
        ref="canvas"
        :root="root"
        :relations="relations"
        :notes-count="notesCount"
        :mode="mode"
        :focus-id="selected"
        :selected-id="selected"
        :relation-label="relationLabel"
        :type-label="typeLabel"
        @select="(id) => (selected = id)"
      />
    </div>
  </div>
</template>
