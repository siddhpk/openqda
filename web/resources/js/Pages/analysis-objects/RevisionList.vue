<script setup>
/*------------------------------------------------------------------------------
 | RevisionList.vue – earlier versions of a concept or proposition and what
 | changed between them.
 *----------------------------------------------------------------------------*/
const props = defineProps({
  revisions: { type: Array, required: true },
  current: { type: Object, required: true },
  labels: { type: Object, required: true }, // field -> label
  format: { type: Function, default: (field, value) => value },
});
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
const show = (field, value) => {
  const v = props.format(field, value);
  if (v === null || v === undefined || v === '') return '–';
  return Array.isArray(v) ? v.join(', ') || '–' : String(v);
};
// fields that differ from the next newer version (or the current one)
const changes = (index) => {
  const older = props.revisions[index].snapshot;
  const newer =
    index === 0 ? props.current : props.revisions[index - 1].snapshot;
  return Object.keys(props.labels).filter(
    (f) => JSON.stringify(older[f] ?? null) !== JSON.stringify(newer[f] ?? null)
  );
};
</script>

<template>
  <details class="text-xs">
    <summary class="cursor-pointer font-semibold text-sm">
      History ({{ revisions.length }} earlier version{{
        revisions.length === 1 ? '' : 's'
      }})
    </summary>
    <ol class="mt-2 space-y-2">
      <li
        v-for="(r, i) in revisions"
        :key="r.version"
        class="border rounded p-2"
      >
        <div class="text-foreground/60">
          Version {{ r.version }} · {{ r.author ?? 'unknown' }} ·
          {{ when(r.createdAt) }}
        </div>
        <div v-if="r.reason">Why it changed: {{ r.reason }}</div>
        <div v-for="f in changes(i)" :key="f" class="mt-1">
          <span class="font-medium">{{ labels[f] }}</span> was:
          <span class="whitespace-pre-wrap">{{ show(f, r.snapshot[f]) }}</span>
        </div>
      </li>
    </ol>
  </details>
</template>
