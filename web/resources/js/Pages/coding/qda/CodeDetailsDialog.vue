<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DialogBase from '../../../dialogs/DialogBase.vue';
import Button from '../../../Components/interactive/Button.vue';
import RelationEditor from './RelationEditor.vue';
import { useCodeDetailsDialog } from '../../../domain/qda/useCodeDetailsDialog.js';
import { updateCodeDetails } from '../../../domain/qda/updateCodeDetails.js';
import {
  allCodeFields,
  codeTypes,
  statuses,
} from '../../../domain/qda/vocabulary.js';
import { flashMessage } from '../../../Components/notification/flashMessage.js';
import { cn } from '../../../utils/css/cn.js';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

/*------------------------------------------------------------------------------
 | CodeDetailsDialog.vue
 | Analytic details of a code: its type, definition with inclusion and
 | exclusion criteria, properties and the range of their variation, a
 | status, the project's own custom fields, and relationships to other
 | codes. Types, statuses and fields come from the project vocabulary
 | (Research design → Vocabulary), so any analytic approach fits.
 *----------------------------------------------------------------------------*/

const props = defineProps({
  codes: { type: Array, required: true }, // flat list
});
const emit = defineEmits(['saved']);

const page = usePage();
const projectId = page.props.projectId ?? page.props.project?.id;
const { code, show, tab, close } = useCodeDetailsDialog();

const form = reactive({ kind: 'code', meta: {} });
const saving = ref(false);

const emptyMeta = () => ({
  definition: '',
  inclusion: '',
  exclusion: '',
  example: '',
  status: '',
  properties: [],
  ...Object.fromEntries(
    allCodeFields().map((f) => [f.key, f.type === 'boolean' ? false : ''])
  ),
});

// archived fields stay visible only where a code already has a value
const visibleFields = computed(() =>
  allCodeFields().filter(
    (f) =>
      !f.archived ||
      (code.value?.meta?.[f.key] !== undefined &&
        code.value?.meta?.[f.key] !== '')
  )
);

watch(
  code,
  (c) => {
    if (!c) return;
    form.kind = c.kind ?? 'code';
    const meta = { ...emptyMeta(), ...(c.meta ?? {}) };
    meta.properties = (meta.properties ?? []).map((p) => ({ ...p }));
    form.meta = meta;
  },
  { immediate: true }
);

const types = computed(() => {
  const list = codeTypes();
  // keep an archived type selectable for a code that still uses it
  if (!list.find((t) => t.key === form.kind)) {
    return [...list, { key: form.kind, label: form.kind, description: '' }];
  }
  return list;
});
const kindInfo = computed(() => types.value.find((k) => k.key === form.kind));
const designUrl = computed(() =>
  projectId
    ? route('design.show', { project: projectId, tab: 'vocabulary' })
    : null
);

const addProperty = () =>
  form.meta.properties.push({ name: '', low: '', high: '' });
const removeProperty = (i) => form.meta.properties.splice(i, 1);

const save = async () => {
  if (!code.value) return;
  saving.value = true;
  try {
    const meta = { ...form.meta };
    await updateCodeDetails(projectId, code.value, { kind: form.kind, meta });
    flashMessage('Details saved', { type: 'success' });
    emit('saved', code.value);
    close();
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  } finally {
    saving.value = false;
  }
};

const tabs = [
  { value: 'details', label: 'Analytic details' },
  { value: 'relations', label: 'Relationships' },
];
const inputClass = 'w-full text-sm rounded border-input bg-surface';
</script>

<template>
  <DialogBase
    :show="show"
    :title="code ? `Analytic details – ${code.name}` : ''"
    :show-close-button="true"
    @close="close"
  >
    <template #body>
      <div
        v-if="code"
        class="space-y-4 text-sm max-h-[70vh] overflow-y-auto pe-1"
      >
        <div class="flex gap-2 border-b">
          <button
            v-for="t in tabs"
            :key="t.value"
            :class="
              cn(
                'px-3 py-1 -mb-px border-b-2',
                tab === t.value
                  ? 'border-primary font-semibold'
                  : 'border-transparent'
              )
            "
            @click="tab = t.value"
          >
            {{ t.label }}
          </button>
        </div>

        <template v-if="tab === 'details'">
          <!-- kind -->
          <div>
            <div class="flex items-center justify-between">
              <label class="text-xs font-medium">Type</label>
              <a
                v-if="designUrl"
                :href="designUrl"
                class="text-xs underline text-foreground/70"
                >Edit types and fields</a
              >
            </div>
            <div class="flex flex-wrap gap-2 mt-1">
              <button
                v-for="k in types"
                :key="k.key"
                :title="k.description"
                :class="
                  cn(
                    'border rounded px-3 py-1 text-left',
                    form.kind === k.key && 'ring-2 ring-primary'
                  )
                "
                @click="form.kind = k.key"
              >
                {{ k.label }}
              </button>
            </div>
            <p
              v-if="kindInfo?.description"
              class="text-xs text-foreground/70 mt-1"
            >
              {{ kindInfo.description }}
            </p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <label class="text-xs font-medium">
              Status
              <select v-model="form.meta.status" :class="inputClass">
                <option value="">–</option>
                <option v-for="st in statuses()" :key="st.key" :value="st.key">
                  {{ st.label }}
                </option>
              </select>
            </label>
            <template v-for="f in visibleFields" :key="f.key">
              <label v-if="f.type === 'choice'" class="text-xs font-medium">
                {{ f.label }}
                <select
                  v-model="form.meta[f.key]"
                  :class="inputClass"
                  :title="f.description"
                >
                  <option value="">–</option>
                  <option v-for="o in f.options" :key="o.key" :value="o.key">
                    {{ o.label }}
                  </option>
                </select>
              </label>
              <label
                v-else-if="f.type === 'boolean'"
                class="flex items-center gap-2 text-xs font-medium self-end pb-2"
                :title="f.description"
              >
                <input
                  v-model="form.meta[f.key]"
                  type="checkbox"
                  class="rounded"
                />
                {{ f.label }}
              </label>
              <label v-else class="text-xs font-medium col-span-2">
                {{ f.label }}
                <textarea
                  v-model="form.meta[f.key]"
                  rows="2"
                  :placeholder="f.description"
                  :class="inputClass"
                ></textarea>
              </label>
            </template>
          </div>

          <!-- definition -->
          <fieldset class="space-y-2 border rounded p-2">
            <legend class="text-xs font-medium px-1">Definition</legend>
            <textarea
              v-model="form.meta.definition"
              rows="2"
              placeholder="What does this code capture?"
              :class="inputClass"
            ></textarea>
            <div class="grid grid-cols-2 gap-2">
              <textarea
                v-model="form.meta.inclusion"
                rows="2"
                placeholder="Use it when…"
                :class="inputClass"
              ></textarea>
              <textarea
                v-model="form.meta.exclusion"
                rows="2"
                placeholder="Do not use it when…"
                :class="inputClass"
              ></textarea>
            </div>
            <textarea
              v-model="form.meta.example"
              rows="2"
              placeholder="Typical example from the data"
              :class="inputClass"
            ></textarea>
          </fieldset>

          <!-- properties: any code can have them -->
          <fieldset class="space-y-2 border rounded p-2">
            <legend class="text-xs font-medium px-1">
              Properties and their range
            </legend>
            <div
              v-for="(p, i) in form.meta.properties"
              :key="i"
              class="grid grid-cols-[1fr_1fr_1fr_auto] gap-1 items-center"
            >
              <input
                v-model="p.name"
                placeholder="property, e.g. duration"
                :class="inputClass"
              />
              <input
                v-model="p.low"
                placeholder="one end, e.g. brief"
                :class="inputClass"
              />
              <input
                v-model="p.high"
                placeholder="other end, e.g. lasting"
                :class="inputClass"
              />
              <button title="Remove property" @click="removeProperty(i)">
                <TrashIcon class="w-4 h-4 hover:text-destructive" />
              </button>
            </div>
            <Button size="sm" variant="ghost" @click="addProperty"
              ><PlusIcon class="w-4 h-4 me-1" /> Add property</Button
            >
          </fieldset>

          <div class="flex justify-end gap-2">
            <Button variant="outline" @click="close">Cancel</Button>
            <Button :disabled="saving" @click="save">{{
              saving ? 'Saving…' : 'Save'
            }}</Button>
          </div>
        </template>

        <RelationEditor v-else :code="code" :codes="props.codes" />
      </div>
    </template>
  </DialogBase>
</template>
