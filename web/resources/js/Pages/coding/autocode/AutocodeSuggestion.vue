<script setup>
/*------------------------------------------------------------------------------
 | AutocodeSuggestion.vue – one unit with its suggested codes (toggleable).
 *----------------------------------------------------------------------------*/
import Button from '../../../Components/interactive/Button.vue';
import { cn } from '../../../utils/css/cn.js';

defineProps({
  item: { type: Object, required: true },
  chosen: { type: Array, required: true },
  codeName: { type: Function, required: true },
  codeColor: { type: Function, required: true },
  sourceName: { type: String, default: '' },
  none: { type: String, required: true },
});
const emit = defineEmits(['focus', 'toggle', 'accept', 'dismiss', 'defer']);
const score = (v) => (v ?? 0).toFixed(2);
</script>

<template>
  <li class="p-2 space-y-1">
    <div
      v-if="sourceName"
      class="text-[10px] uppercase tracking-wide text-foreground/50"
    >
      {{ sourceName }}
    </div>
    <button
      class="text-left text-xs hover:underline w-full"
      title="Show in context"
      @click="emit('focus', item)"
    >
      {{
        item.unit.text.length > 240
          ? item.unit.text.slice(0, 240) + '…'
          : item.unit.text
      }}
    </button>
    <div class="flex flex-wrap gap-1">
      <button
        v-for="t in item.scores"
        :key="t.classId"
        :disabled="t.classId === none"
        :class="
          cn(
            'text-xs px-2 py-0.5 rounded-full border',
            chosen.includes(t.classId) && 'ring-2 ring-primary font-medium',
            t.classId === none && 'opacity-60 cursor-default'
          )
        "
        :style="t.classId !== none ? { borderColor: codeColor(t.classId) } : {}"
        :title="
          t.threshold !== null
            ? `score ${score(t.score)}, cut-off ${score(t.threshold)} (scores rank units; they are not probabilities) – click to add or remove`
            : ''
        "
        @click="emit('toggle', item, t.classId)"
      >
        {{ codeName(t.classId) }}
        <span class="opacity-60 tabular-nums">{{ score(t.score) }}</span>
      </button>
    </div>
    <div class="flex gap-2">
      <Button
        size="sm"
        variant="outline-confirmative"
        :disabled="!chosen.length"
        @click="emit('accept', item)"
      >
        Accept{{ chosen.length > 1 ? ` ${chosen.length} codes` : '' }}
      </Button>
      <Button size="sm" variant="ghost" @click="emit('dismiss', item)"
        >Reject</Button
      >
      <Button
        v-if="!item.deferred"
        size="sm"
        variant="ghost"
        title="Decide later: moves this unit to the end of the list"
        @click="emit('defer', item)"
        >Later</Button
      >
    </div>
  </li>
</template>
