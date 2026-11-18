<script setup>
import { computed, reactive } from 'vue';
import { useRelations } from '../../../domain/qda/useRelations.js';
import {
  relationLabel,
  relationType,
  relationTypes,
} from '../../../domain/qda/vocabulary.js';
import Button from '../../../Components/interactive/Button.vue';
import { flashMessage } from '../../../Components/notification/flashMessage.js';
import {
  ArrowLongRightIcon,
  ArrowsRightLeftIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline';

/*------------------------------------------------------------------------------
 | RelationEditor.vue
 | Lists and creates non-hierarchical relationships of one code.
 *----------------------------------------------------------------------------*/

const props = defineProps({
  code: { type: Object, required: true },
  codes: { type: Array, required: true }, // flat list of all codes
  readonly: Boolean,
});

const { relationsOf, createRelation, deleteRelation } = useRelations();
const byId = computed(() => new Map(props.codes.map((c) => [c.id, c])));
const nameOf = (id) => byId.value.get(id)?.name ?? '(deleted code)';
const own = computed(() => relationsOf(props.code.id));

const form = reactive({
  to: '',
  type: relationTypes()[0]?.key ?? 'custom',
  valence: 0,
  label: '',
  description: '',
  reverse: false,
});
const selectedType = computed(() => relationType(form.type));
const candidates = computed(() =>
  props.codes
    .filter((c) => c.id !== props.code.id)
    .toSorted((a, b) => a.name.localeCompare(b.name))
);

const submit = async () => {
  if (!form.to) return;
  const from = form.reverse ? form.to : props.code.id;
  const to = form.reverse ? props.code.id : form.to;
  try {
    await createRelation({
      from,
      to,
      type: form.type,
      label: form.type === 'custom' ? form.label : undefined,
      valence: selectedType.value.valence ? form.valence : undefined,
      description: form.description || undefined,
    });
    form.to = '';
    form.description = '';
    form.label = '';
    flashMessage('Relationship added', { type: 'success' });
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

const remove = async (relation) => {
  try {
    await deleteRelation(relation.id);
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};
</script>

<template>
  <div class="space-y-3 text-sm">
    <ul class="divide-y border rounded">
      <li v-for="r in own" :key="r.id" class="flex items-center gap-2 p-2">
        <span class="truncate" :class="r.from === code.id && 'font-semibold'">{{
          nameOf(r.from)
        }}</span>
        <component
          :is="r.directed ? ArrowLongRightIcon : ArrowsRightLeftIcon"
          class="w-4 h-4 shrink-0"
        />
        <span class="text-xs px-1 rounded bg-foreground/10 shrink-0">{{
          relationLabel(r)
        }}</span>
        <component
          :is="r.directed ? ArrowLongRightIcon : ArrowsRightLeftIcon"
          class="w-4 h-4 shrink-0"
        />
        <span
          class="truncate grow"
          :class="r.to === code.id && 'font-semibold'"
          >{{ nameOf(r.to) }}</span
        >
        <button v-if="!readonly" title="Delete relationship" @click="remove(r)">
          <TrashIcon class="w-4 h-4 hover:text-destructive" />
        </button>
      </li>
      <li v-if="!own.length" class="p-2 italic text-xs">
        No relationships yet.
      </li>
    </ul>

    <form
      v-if="!readonly"
      class="space-y-2 border rounded p-2"
      @submit.prevent="submit"
    >
      <div class="text-xs font-medium">Add a relationship</div>
      <div class="flex items-center gap-2 text-xs">
        <span class="font-semibold truncate max-w-[40%]">{{
          form.reverse ? 'other code' : code.name
        }}</span>
        <button
          type="button"
          class="underline text-foreground/60"
          title="Swap direction"
          @click="form.reverse = !form.reverse"
        >
          swap
        </button>
        <span class="font-semibold truncate max-w-[40%]">{{
          form.reverse ? code.name : 'other code'
        }}</span>
      </div>
      <select
        v-model="form.type"
        class="w-full text-xs rounded border-input bg-surface"
      >
        <option v-for="t in relationTypes()" :key="t.key" :value="t.key">
          {{ t.label }}
        </option>
      </select>
      <input
        v-if="form.type === 'custom'"
        v-model="form.label"
        maxlength="120"
        required
        placeholder="Label, e.g. 'enables'"
        class="w-full text-xs rounded border-input bg-surface"
      />
      <div v-if="selectedType.valence" class="flex gap-3 text-xs">
        <label class="flex items-center gap-1"
          ><input v-model.number="form.valence" type="radio" :value="1" />
          positive (+)</label
        >
        <label class="flex items-center gap-1"
          ><input v-model.number="form.valence" type="radio" :value="-1" />
          negative (−)</label
        >
        <label class="flex items-center gap-1"
          ><input v-model.number="form.valence" type="radio" :value="0" />
          unspecified</label
        >
      </div>
      <select
        v-model="form.to"
        required
        class="w-full text-xs rounded border-input bg-surface"
      >
        <option value="" disabled>Choose the other code…</option>
        <option v-for="c in candidates" :key="c.id" :value="c.id">
          {{ c.name }}
        </option>
      </select>
      <textarea
        v-model="form.description"
        rows="2"
        placeholder="Why do you see this link? (optional)"
        class="w-full text-xs rounded border-input bg-surface"
      ></textarea>
      <Button size="sm" type="submit" class="w-full" :disabled="!form.to"
        >Add relationship</Button
      >
    </form>
  </div>
</template>
