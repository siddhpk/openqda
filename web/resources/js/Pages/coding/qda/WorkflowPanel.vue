<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useNotes } from '../../../domain/notes/useNotes.js';
import { useRelations } from '../../../domain/qda/useRelations.js';
import { overviewIndicators } from '../../../domain/qda/vocabulary.js';
import { flashMessage } from '../../../Components/notification/flashMessage.js';

/*------------------------------------------------------------------------------
 | WorkflowPanel.vue – analysis overview
 | Shows the analysis plan the researchers wrote themselves (Research design
 | → Analysis plan) and neutral counts of what exists so far. OpenQDA does
 | not impose an order of steps or a methodology.
 *----------------------------------------------------------------------------*/

const props = defineProps({
  codes: { type: Array, default: () => [] }, // flat
});
defineEmits(['memo']);

const page = usePage();
const projectId = page.props.projectId ?? page.props.project?.id;
const { notes } = useNotes();
const { relations } = useRelations();
const design = ref(null);

onMounted(async () => {
  if (!projectId) return;
  try {
    const { data } = await axios.get(
      route('design.summary', { project: projectId })
    );
    design.value = data.design;
  } catch {
    design.value = { plan: [] };
  }
});

const indicators = computed(() =>
  overviewIndicators({
    codes: props.codes,
    relations: relations.value,
    notes: notes.value ?? [],
  })
);
const plan = computed(() => design.value?.plan ?? []);
const designUrl = (tab) => route('design.show', { project: projectId, tab });

const toggle = async (step) => {
  const next = plan.value.map((s) =>
    s.id === step.id ? { ...s, done: !s.done } : s
  );
  try {
    const { data } = await axios.put(
      route('design.plan', { project: projectId }),
      { plan: next }
    );
    design.value = data.design;
  } catch (e) {
    flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });
  }
};

const sections = [
  { key: 'codes', label: 'Codes' },
  { key: 'relations', label: 'Relationships' },
  { key: 'memos', label: 'Memos' },
];
</script>

<template>
  <div class="p-3 space-y-4 text-sm">
    <section>
      <div class="flex items-center justify-between">
        <h3 class="font-semibold">Analysis plan</h3>
        <a :href="designUrl('plan')" class="text-xs underline">Edit</a>
      </div>
      <p v-if="design && !plan.length" class="text-xs text-foreground/70 mt-1">
        No plan yet. Write down the steps you intend to take, in your own terms,
        under
        <a :href="designUrl('plan')" class="underline"
          >Research design → Analysis plan</a
        >.
      </p>
      <ul class="mt-2 space-y-1">
        <li v-for="step in plan" :key="step.id" class="flex items-start gap-2">
          <input
            type="checkbox"
            class="rounded mt-0.5"
            :checked="step.done"
            @change="toggle(step)"
          />
          <div>
            <div :class="step.done ? 'line-through opacity-70' : ''">
              {{ step.title }}
            </div>
            <div v-if="step.description" class="text-xs text-foreground/60">
              {{ step.description }}
            </div>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="design?.questions">
      <h3 class="font-semibold">Research questions</h3>
      <p class="text-xs whitespace-pre-line text-foreground/80 mt-1">
        {{ design.questions }}
      </p>
    </section>

    <section v-for="s in sections" :key="s.key">
      <h3 class="font-semibold">{{ s.label }}</h3>
      <dl class="grid grid-cols-[1fr_auto] gap-x-3 text-xs mt-1">
        <template v-for="item in indicators[s.key]" :key="item.label">
          <dt class="text-foreground/70">{{ item.label }}</dt>
          <dd class="text-right tabular-nums">{{ item.value }}</dd>
        </template>
      </dl>
    </section>

    <p class="text-xs text-foreground/60">
      Types of codes, relationships and memos are yours to define:
      <a :href="designUrl('vocabulary')" class="underline">edit vocabulary</a>.
    </p>
  </div>
</template>
