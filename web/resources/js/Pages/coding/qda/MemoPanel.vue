<script setup>
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { saveAs } from 'file-saver';
import { useNotes } from '../../../domain/notes/useNotes.js';
import { useUsers } from '../../../domain/teams/useUsers.js';
import { useRelations } from '../../../domain/qda/useRelations.js';
import {
  memoType,
  memoTypes,
  memoTypesFor,
  vocabularyState,
  relationLabel,
} from '../../../domain/qda/vocabulary.js';
import Button from '../../../Components/interactive/Button.vue';
import { useClusterLive } from '../../../domain/collab/useClusterLive.js';
import { useCoEdit } from '../../../domain/collab/useCoEdit.js';
import { bindTextarea } from '../../../domain/collab/useYTextarea.js';
import { debounce } from '../../../utils/dom/debounce.js';
import { flashMessage } from '../../../Components/notification/flashMessage.js';
import {
  ArrowDownTrayIcon,
  TrashIcon,
  PencilIcon,
  UserIcon,
  UsersIcon,
} from '@heroicons/vue/24/outline';

/*------------------------------------------------------------------------------
 | MemoPanel.vue
 | All memos of the project in one place, with the project's own memo types
 | and templates (Research design → Vocabulary), filters and Markdown export.
 *----------------------------------------------------------------------------*/

const props = defineProps({
  codes: { type: Array, default: () => [] }, // flat
  source: { type: Object, default: null },
  initialTarget: { type: Object, default: null }, // { type, id, name }
  initialMemoType: { type: String, default: '' },
});

const page = usePage();
const projectId = page.props.projectId ?? page.props.project?.id;
const projectName = page.props.project?.name ?? 'Project';
const { notes, createNote, updateNote, deleteNote, applyRemote } = useNotes();
const { getMemberBy, getOwnUser } = useUsers();
const { relations } = useRelations();

const codeById = computed(() => new Map(props.codes.map((c) => [c.id, c])));
const relationById = computed(
  () => new Map(relations.value.map((r) => [r.id, r]))
);

const targetName = (note) => {
  switch (note.type) {
    case 'project':
      return 'Project';
    case 'source':
      return props.source && note.target === props.source.id
        ? `Source: ${props.source.name}`
        : 'Source';
    case 'code':
      return `Code: ${codeById.value.get(note.target)?.name ?? '?'}`;
    case 'selection':
      return 'Coded segment';
    case 'relation': {
      const r = relationById.value.get(note.target);
      if (!r) return 'Relationship';
      const from = codeById.value.get(r.from)?.name ?? '?';
      const to = codeById.value.get(r.to)?.name ?? '?';
      return `Link: ${from} ${relationLabel(r)} ${to}`;
    }
    default:
      return note.type;
  }
};

//------------------------------------------------------------------------
// filters
//------------------------------------------------------------------------
const filter = reactive({ memoType: '', target: '', mine: false, q: '' });
const filtered = computed(() => {
  const own = getOwnUser()?.id;
  const q = filter.q.trim().toLowerCase();
  return (notes.value ?? [])
    .filter(
      (n) => !filter.memoType || (n.memo_type ?? 'analytic') === filter.memoType
    )
    .filter((n) => !filter.target || n.type === filter.target)
    .filter((n) => !filter.mine || n.creating_user_id === own)
    .filter((n) => !q || n.content.toLowerCase().includes(q))
    .toSorted(
      (a, b) =>
        new Date(b.updated_at ?? b.created_at) -
        new Date(a.updated_at ?? a.created_at)
    );
});

//------------------------------------------------------------------------
// create
//------------------------------------------------------------------------
const form = reactive({
  targetType: props.initialTarget?.type ?? 'project',
  codeId: props.initialTarget?.type === 'code' ? props.initialTarget.id : '',
  memoType: props.initialMemoType || 'analytic',
  content: '',
  visibility: 0,
});
const creating = ref(!!props.initialTarget || !!props.initialMemoType);
const availableTypes = computed(() => memoTypesFor(form.targetType));

watch(
  () => form.targetType,
  () => {
    if (!availableTypes.value.find((m) => m.key === form.memoType))
      form.memoType = 'analytic';
  }
);
watch(
  () => form.memoType,
  (type, prev) => {
    const prevTemplate = memoType(prev)?.template ?? '';
    if (!form.content || form.content === prevTemplate)
      form.content = memoType(type).template;
  },
  { immediate: true }
);

const target = computed(() => {
  if (form.targetType === 'project') return { id: String(projectId) };
  if (form.targetType === 'source') return props.source;
  if (form.targetType === 'code')
    return codeById.value.get(form.codeId) ?? null;
  return null;
});

const submit = async () => {
  if (!target.value || !form.content.trim()) return;
  try {
    await createNote(
      {
        content: form.content,
        type: form.targetType,
        memo_type: form.memoType,
        visibility: form.visibility,
      },
      target.value
    );
    flashMessage(`${memoType(form.memoType).label} saved`, { type: 'success' });
    form.content = memoType(form.memoType).template;
    creating.value = false;
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

//------------------------------------------------------------------------
// edit / delete own memos
//------------------------------------------------------------------------
const editing = ref(null);
const editContent = ref('');
const isOwn = (note) => getOwnUser()?.id === note.creating_user_id;
// laptop collaboration: shared memos of a collaborative project can be
// edited by its members (an Assistant's change to someone else's memo waits
// for review) and are co-edited live by their author and the Primaries
const cluster = useClusterLive();
const coEdit = useCoEdit();
const editArea = ref(null);
let unbind = null;
const mayEdit = (note) =>
  isOwn(note) || (cluster.state.enabled && note.visibility === 1);
const reviewHint = computed(() => coEdit.active.value && !coEdit.canEdit.value);
const offEvents = cluster.onEvents((events) =>
  events.filter((e) => e.tbl === 'notes').forEach(applyRemote)
);
const stopCoEdit = () => {
  if (unbind) unbind();
  unbind = null;
  coEdit.stop();
};
onUnmounted(() => {
  stopCoEdit();
  offEvents();
});
const autosave = debounce((note) => {
  if (editing.value === note.id && coEdit.isLeader.value) {
    updateNote(
      { id: note.id, content: editContent.value },
      targetObject(note),
      {
        headers: coEdit.saveHeaders(),
      }
    ).catch((e) => flashMessage(e.message, { type: 'error' }));
  }
}, 1500);
const targetObject = (note) =>
  note.type === 'code'
    ? (codeById.value.get(note.target) ?? { id: note.target })
    : { id: note.target };
const startEdit = async (note) => {
  stopCoEdit();
  editing.value = note.id;
  editContent.value = note.content;
  if (!cluster.state.enabled || note.visibility !== 1) return;
  const live = await coEdit.start(`notes:${note.id}:content`, (ytext) =>
    ytext.insert(0, note.content ?? '')
  );
  await nextTick();
  if (
    live &&
    coEdit.canEdit.value &&
    editArea.value?.[0] &&
    editing.value === note.id
  ) {
    unbind = bindTextarea(coEdit.text(), editArea.value[0], (value) => {
      editContent.value = value;
      autosave(note);
    });
    editArea.value[0].addEventListener('input', () => autosave(note));
    // offline edits of several people were merged: save the merged text
    if (coEdit.needsFinalize.value) autosave(note);
  }
};
const saveEdit = async (note) => {
  try {
    await updateNote(
      { id: note.id, content: editContent.value },
      targetObject(note),
      {
        headers:
          coEdit.active.value && coEdit.canEdit.value
            ? coEdit.saveHeaders()
            : {},
      }
    );
    if (reviewHint.value) {
      flashMessage(
        'Saved. A Primary reviews your change before the others see it.',
        {
          type: 'success',
        }
      );
    }
    stopCoEdit();
    editing.value = null;
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};
const cancelEdit = () => {
  stopCoEdit();
  editing.value = null;
};
const remove = async (note) => {
  if (!confirm('Delete this memo? This cannot be undone.')) return;
  try {
    await deleteNote(note, targetObject(note));
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

//------------------------------------------------------------------------
// export
//------------------------------------------------------------------------
const exportMarkdown = () => {
  const lines = [
    `# Memos – ${projectName}`,
    '',
    `Exported ${new Date().toLocaleString()}`,
    '',
  ];
  for (const type of vocabularyState.data.memo_types) {
    const group = filtered.value.filter(
      (n) => (n.memo_type ?? 'analytic') === type.key
    );
    if (!group.length) continue;
    lines.push(`## ${type.label}`, '');
    for (const n of group) {
      const author = getMemberBy(n.creating_user_id)?.name ?? '';
      lines.push(
        `### ${targetName(n)}`,
        '',
        `*${author} · ${new Date(n.updated_at ?? n.created_at).toLocaleString()}*`,
        '',
        n.content,
        ''
      );
    }
  }
  saveAs(
    new Blob([lines.join('\n')], { type: 'text/markdown;charset=utf-8' }),
    `memos-${projectId}.md`
  );
};

const badge = (note) => memoType(note.memo_type ?? 'analytic');
const inputClass = 'w-full text-xs rounded border-input bg-surface';
</script>

<template>
  <div class="p-3 space-y-3 text-sm">
    <div class="flex gap-2">
      <Button
        size="sm"
        class="grow"
        :variant="creating ? 'outline' : 'default'"
        @click="creating = !creating"
      >
        {{ creating ? 'Close' : 'New memo' }}
      </Button>
      <Button
        size="sm"
        variant="outline"
        title="Export the listed memos as Markdown"
        @click="exportMarkdown"
      >
        <ArrowDownTrayIcon class="w-4 h-4" />
      </Button>
    </div>

    <form
      v-if="creating"
      class="space-y-2 border rounded p-2"
      @submit.prevent="submit"
    >
      <div class="grid grid-cols-2 gap-2">
        <select v-model="form.targetType" :class="inputClass">
          <option value="project">Project</option>
          <option v-if="source" value="source">This source</option>
          <option value="code">A code</option>
        </select>
        <select v-model="form.memoType" :class="inputClass">
          <option
            v-for="m in availableTypes"
            :key="m.key"
            :value="m.key"
            :title="m.description"
          >
            {{ m.label }}
          </option>
        </select>
      </div>
      <select
        v-if="form.targetType === 'code'"
        v-model="form.codeId"
        required
        :class="inputClass"
      >
        <option value="" disabled>Choose a code…</option>
        <option
          v-for="c in codes.toSorted((a, b) => a.name.localeCompare(b.name))"
          :key="c.id"
          :value="c.id"
        >
          {{ c.name }}
        </option>
      </select>
      <p
        v-if="memoType(form.memoType).description"
        class="text-[10px] text-foreground/60"
      >
        {{ memoType(form.memoType).description }}
      </p>
      <textarea
        v-model="form.content"
        rows="8"
        required
        :class="inputClass"
      ></textarea>
      <div class="flex items-center justify-between gap-2">
        <select
          v-model.number="form.visibility"
          :class="inputClass + ' w-auto'"
        >
          <option :value="0">Only me</option>
          <option :value="1">Team</option>
        </select>
        <Button size="sm" type="submit" :disabled="!target">Save memo</Button>
      </div>
    </form>

    <div class="grid grid-cols-2 gap-2">
      <select v-model="filter.memoType" :class="inputClass">
        <option value="">All memo types</option>
        <option v-for="m in memoTypes()" :key="m.key" :value="m.key">
          {{ m.label }}
        </option>
      </select>
      <select v-model="filter.target" :class="inputClass">
        <option value="">All targets</option>
        <option value="project">Project</option>
        <option value="source">Sources</option>
        <option value="code">Codes</option>
        <option value="selection">Coded segments</option>
        <option value="relation">Relationships</option>
      </select>
      <input
        v-model="filter.q"
        placeholder="Search memos…"
        :class="inputClass"
      />
      <label class="flex items-center gap-1 text-xs"
        ><input v-model="filter.mine" type="checkbox" class="rounded" /> only
        mine</label
      >
    </div>

    <ul class="divide-y border rounded">
      <li v-for="note in filtered" :key="note.id" class="p-2 space-y-1">
        <div class="flex items-center justify-between gap-2 text-xs">
          <span class="px-1 rounded bg-secondary/20 font-medium">{{
            badge(note).label
          }}</span>
          <span
            class="truncate grow text-foreground/70"
            :title="targetName(note)"
            >{{ targetName(note) }}</span
          >
          <component
            :is="note.visibility === 1 ? UsersIcon : UserIcon"
            class="w-4 h-4 shrink-0"
          />
          <template v-if="mayEdit(note)">
            <button title="Edit" @click="startEdit(note)">
              <PencilIcon class="w-4 h-4 hover:text-primary" />
            </button>
            <button title="Delete" @click="remove(note)">
              <TrashIcon class="w-4 h-4 hover:text-destructive" />
            </button>
          </template>
        </div>
        <div v-if="editing === note.id" class="space-y-1">
          <textarea
            ref="editArea"
            v-model="editContent"
            rows="6"
            :class="inputClass"
          ></textarea>
          <p
            v-if="coEdit.active.value && coEdit.canEdit.value"
            class="text-[10px] text-foreground/60"
            data-test="memo-live"
          >
            Live: others editing this memo see your typing; it saves by itself.
          </p>
          <p
            v-else-if="reviewHint"
            class="text-[10px] text-amber-700"
            data-test="memo-review"
          >
            This memo belongs to someone else: your change waits for a Primary's
            review.
          </p>
          <div class="flex gap-2 justify-end">
            <Button size="sm" variant="ghost" @click="cancelEdit"
              >Cancel</Button
            >
            <Button size="sm" @click="saveEdit(note)">Save</Button>
          </div>
        </div>
        <p v-else class="text-xs whitespace-pre-line">{{ note.content }}</p>
        <div class="text-[10px] text-foreground/50">
          {{ getMemberBy(note.creating_user_id)?.name }} ·
          {{ new Date(note.updated_at ?? note.created_at).toLocaleString() }}
        </div>
      </li>
      <li v-if="!filtered.length" class="p-2 italic text-xs">
        No memos match.
      </li>
    </ul>
  </div>
</template>
