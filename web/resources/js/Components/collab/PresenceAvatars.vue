<template>
  <div
    v-if="people.length"
    class="flex flex-col items-center gap-1.5 py-2"
    data-test="presence-avatars"
    aria-label="Collaborators online"
  >
    <span
      v-for="p in people"
      :key="`${p.node}:${p.user}`"
      :title="title(p)"
      class="relative h-7 w-7 rounded-full text-[10px] font-semibold text-white flex items-center justify-center ring-2 ring-background"
      :class="p.idle && 'opacity-60'"
      :style="{ background: p.color || '#2563eb' }"
    >
      {{ initials(p.name) }}
      <span
        v-if="p.edit"
        class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-green-500 ring-2 ring-background"
      />
    </span>
  </div>
</template>

<script setup>
defineProps({ people: { type: Array, default: () => [] } });

const initials = (name = '') =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('') || '?';

const views = {
  coding: 'coding',
  editing: 'editing a text',
  preparation: 'preparing documents',
  analysis: 'analysing',
};

const title = (p) =>
  `${p.name} is online${views[p.view] ? `, ${views[p.view]}` : ''}${p.idle ? ' (away)' : ''}`;
</script>
