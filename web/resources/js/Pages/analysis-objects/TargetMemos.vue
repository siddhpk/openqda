<script setup>
/*------------------------------------------------------------------------------
 | TargetMemos.vue – memos attached to a concept, proposition or case.
 *----------------------------------------------------------------------------*/
import { computed, ref } from 'vue';
import axios from 'axios';
import Button from '../../Components/interactive/Button.vue';
import { flashMessage } from '../../Components/notification/flashMessage.js';
import { memoType, memoTypesFor } from '../../domain/qda/vocabulary.js';

const props = defineProps({
  projectId: { type: [String, Number], required: true },
  type: { type: String, required: true },
  target: { type: String, required: true },
  memos: { type: Array, required: true },
});
const emit = defineEmits(['changed']);
const types = computed(() => memoTypesFor(props.type));
const mine = computed(() =>
  props.memos.filter((m) => m.target === props.target)
);
const content = ref('');
const kind = ref('');
const shared = ref(true);
const pickType = () => {
  const t = memoType(kind.value);
  if (t?.template && !content.value.trim()) content.value = t.template;
};
const save = async () => {
  try {
    const { data } = await axios.post(
      route('notes.store', { project: props.projectId }),
      {
        content: content.value,
        target: props.target,
        type: props.type,
        memo_type: kind.value || null,
        visibility: shared.value ? 1 : 0,
      }
    );
    emit('changed', [...props.memos, data.note]);
    content.value = '';
  } catch (e) {
    flashMessage(e.response?.data?.message ?? e.message, { type: 'error' });
  }
};
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
</script>

<template>
  <section class="space-y-2 text-xs">
    <h3 class="font-semibold text-sm">Memos</h3>
    <ul v-if="mine.length" class="divide-y border rounded">
      <li v-for="m in mine" :key="m.id" class="p-2 space-y-0.5">
        <div class="text-foreground/60">
          {{ memoType(m.memo_type)?.label ?? 'Memo' }} ·
          {{ when(m.created_at) }}
          <span v-if="!m.visibility">· private</span>
        </div>
        <div class="whitespace-pre-wrap">{{ m.content }}</div>
      </li>
    </ul>
    <div class="space-y-1">
      <div class="flex gap-2 items-center">
        <select
          v-model="kind"
          class="text-xs rounded border-input bg-surface"
          @change="pickType"
        >
          <option value="">Memo type…</option>
          <option v-for="t in types" :key="t.key" :value="t.key">
            {{ t.label }}
          </option>
        </select>
        <label class="flex items-center gap-1"
          ><input v-model="shared" type="checkbox" class="rounded" /> visible to
          the team</label
        >
      </div>
      <textarea
        v-model="content"
        rows="3"
        class="w-full text-xs rounded border-input bg-surface"
        placeholder="Write a memo…"
      ></textarea>
      <Button size="sm" :disabled="!content.trim()" @click="save"
        >Add memo</Button
      >
    </div>
  </section>
</template>
