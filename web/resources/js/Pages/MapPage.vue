<template>
  <AuthenticatedLayout :menu="true" :showFooter="false" :title="pageTitle">
    <template #menu>
      <BaseContainer>
        <div class="space-y-4 p-1">
          <ResponsiveTabList
            :tabs="menuTabs"
            :initial="menuView"
            @change="(v) => (menuView = v)"
          />

          <!-- selected node -->
          <template v-if="menuView === 'node'">
            <div v-if="selectedCode" class="space-y-3 text-sm">
              <div
                class="rounded-md px-2 py-2 font-semibold"
                :style="{ background: selectedCode.color }"
              >
                <ContrastText>{{ selectedCode.name }}</ContrastText>
              </div>
              <div class="text-xs text-foreground/70">
                {{ kindLabel(selectedCode) }} ·
                {{ selectedCode.selectionsCount ?? 0 }} coded segments
              </div>
              <p v-if="selectedCode.meta?.definition" class="text-xs">
                <b>Definition:</b> {{ selectedCode.meta.definition }}
              </p>
              <p v-if="selectedCode.meta?.central_concept" class="text-xs">
                <b>Central organizing concept:</b>
                {{ selectedCode.meta.central_concept }}
              </p>
              <div class="grid grid-cols-2 gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  @click="openDetails(selectedCode.id)"
                  >Analytic details</Button
                >
                <Button
                  size="sm"
                  variant="outline"
                  @click="openDetails(selectedCode.id, 'relations')"
                  >Relationships</Button
                >
                <Button
                  size="sm"
                  variant="outline"
                  @click="startRename(selectedCode.id)"
                  >Rename</Button
                >
                <Button
                  size="sm"
                  variant="outline"
                  @click="startAddChild(selectedCode.id)"
                  >Add subcode</Button
                >
                <Button
                  size="sm"
                  variant="outline"
                  @click="canvas?.startRelation(selectedCode.id)"
                  >Relate to…</Button
                >
                <Button size="sm" variant="outline" @click="mode = 'focus'"
                  >Focus view</Button
                >
                <Button
                  size="sm"
                  variant="destructive"
                  class="col-span-2"
                  @click="startDelete(selectedCode.id)"
                  >Delete code</Button
                >
              </div>
              <div>
                <Headline3 class="my-2">Memos</Headline3>
                <NoteList
                  :notes="selectedNotes"
                  :target="selectedCode"
                  type="code"
                  :color="selectedCode.color"
                />
              </div>
            </div>
            <div v-else class="text-sm space-y-3">
              <p>
                Select a code in the map. Double-click opens its analytic
                details.
              </p>
              <ul class="text-xs space-y-1 text-foreground/80">
                <li>
                  <b>Drag</b> a code onto another code to make it a subcode;
                  drop it on the center to make it top-level.
                </li>
                <li>
                  <b>Shift/Alt-drag</b> from one code to another, or press
                  <b>R</b>, to create a relationship.
                </li>
                <li>
                  <b>Arrow keys</b> move between codes, <b>Space</b> folds,
                  <b>Enter/F2</b> renames, <b>Insert/Tab</b> adds a subcode,
                  <b>Delete</b> deletes.
                </li>
                <li><b>Mouse wheel</b> zooms, dragging the background pans.</li>
              </ul>
              <div class="border rounded p-2 space-y-2">
                <div class="font-medium">Import a FreeMind map</div>
                <p class="text-xs text-foreground/70">
                  Creates a new codebook from a .mm file (FreeMind or
                  Freeplane). Node notes become memos.
                </p>
                <input
                  type="file"
                  accept=".mm,application/x-freemind"
                  class="text-xs"
                  @change="importMindMap"
                />
              </div>
            </div>
          </template>

          <WorkflowPanel
            v-if="menuView === 'workflow'"
            :codes="flatCodes"
            @memo="openMemo"
          />
          <MemoPanel
            v-if="menuView === 'memos'"
            :key="memoPanelKey"
            :codes="flatCodes"
            :initial-memo-type="memoPreset"
          />
        </div>
      </BaseContainer>
    </template>

    <template #main>
      <div class="h-full">
        <div class="flex flex-col h-full">
          <div class="flex flex-wrap items-center gap-2 p-2 border-b text-sm">
            <select
              v-model="mode"
              class="text-sm rounded border-input bg-surface"
              title="Layout"
            >
              <option value="mindmap">Mind map</option>
              <option value="focus">Focus view</option>
              <option value="network">Relationship network</option>
            </select>
            <select
              v-if="props.codebooks?.length > 1"
              v-model="codebookFilter"
              class="text-sm rounded border-input bg-surface"
            >
              <option value="">All codebooks</option>
              <option v-for="cb in props.codebooks" :key="cb.id" :value="cb.id">
                {{ cb.name }}
              </option>
            </select>
            <select
              v-if="mode === 'mindmap' && codeTypes().length > 1"
              v-model="typeFilter"
              class="text-sm rounded border-input bg-surface"
              title="Show only codes of one type"
            >
              <option value="">All code types</option>
              <option v-for="t in codeTypes()" :key="t.key" :value="t.key">
                only {{ t.label }}
              </option>
            </select>
            <label
              v-if="mode === 'focus'"
              class="flex items-center gap-1 text-xs text-foreground/60"
            >
              Center:
              <select
                :value="selectedCode ? selectedId : ''"
                class="text-sm rounded border-input bg-surface max-w-[16rem]"
                @change="select($event.target.value || null)"
              >
                <option value="">select a code</option>
                <option v-for="c in focusChoices" :key="c.id" :value="c.id">
                  {{ c.name }}
                </option>
              </select>
            </label>
            <details v-if="mode === 'network'" class="text-xs relative">
              <summary class="cursor-pointer">
                Relationship types ({{
                  networkTypes ? networkTypes.length : 'all directed'
                }})
              </summary>
              <div
                class="absolute z-10 mt-1 p-2 bg-surface border rounded shadow space-y-1 min-w-[14rem]"
              >
                <label
                  v-for="t in directedTypes"
                  :key="t.key"
                  class="flex items-center gap-1"
                  ><input
                    type="checkbox"
                    class="rounded"
                    :checked="!networkTypes || networkTypes.includes(t.key)"
                    @change="toggleNetworkType(t.key)"
                  />{{ t.label }}</label
                >
              </div>
            </details>
            <span class="grow"></span>
            <Button
              size="sm"
              variant="ghost"
              title="Add a top-level code"
              @click="startAddChild(null)"
              ><PlusIcon class="w-4 h-4"
            /></Button>
            <Button
              v-if="mode === 'mindmap'"
              size="sm"
              variant="ghost"
              title="Fold all"
              @click="canvas?.collapseAll()"
              >Fold</Button
            >
            <Button
              v-if="mode === 'mindmap'"
              size="sm"
              variant="ghost"
              title="Unfold all"
              @click="canvas?.expandAll()"
              >Unfold</Button
            >
            <Button
              size="sm"
              variant="ghost"
              title="Fit to screen"
              @click="canvas?.fit()"
              ><ArrowsPointingOutIcon class="w-4 h-4"
            /></Button>
            <Button
              size="sm"
              variant="outline"
              title="Export as FreeMind (.mm)"
              @click="exportMm"
              >.mm</Button
            >
            <Button
              size="sm"
              variant="outline"
              title="Export as SVG"
              @click="downloadSvg(canvas.svg(), fileBase)"
              >SVG</Button
            >
            <Button
              size="sm"
              variant="outline"
              title="Export as PNG"
              @click="downloadPng(canvas.svg(), fileBase)"
              >PNG</Button
            >
          </div>
          <div class="grow min-h-[24rem] bg-white">
            <MindMapCanvas
              ref="canvas"
              :root="root"
              :relations="relations"
              :notes-count="notesCount"
              :editable="true"
              :mode="mode"
              :focus-id="selectedId"
              :selected-id="selectedId"
              :relation-label="relationLabel"
              :type-label="(type) => relationType(type).label"
              :network-types="networkTypes"
              @select="select"
              @open="openDetails"
              @reparent="reparent"
              @relate="startRelate"
              @add-child="startAddChild"
              @rename="startRename"
              @delete="startDelete"
            />
          </div>
        </div>

        <!-- small dialogs -->
        <DialogBase
          :show="!!dialog"
          :title="dialogTitle"
          :show-close-button="true"
          @close="dialog = null"
        >
          <template #body>
            <form
              v-if="dialog?.type === 'name'"
              class="space-y-3"
              @submit.prevent="submitName"
            >
              <input
                v-model="dialog.value"
                required
                maxlength="255"
                autofocus
                class="w-full rounded border-input bg-surface"
              />
              <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" @click="dialog = null"
                  >Cancel</Button
                >
                <Button type="submit">Save</Button>
              </div>
            </form>
            <form
              v-if="dialog?.type === 'relate'"
              class="space-y-3 text-sm"
              @submit.prevent="submitRelation"
            >
              <div>
                <b>{{ codeName(dialog.from) }}</b> →
                <b>{{ codeName(dialog.to) }}</b>
                <button
                  type="button"
                  class="ms-2 text-xs underline"
                  @click="swapRelation"
                >
                  swap
                </button>
              </div>
              <select
                v-model="dialog.relType"
                class="w-full rounded border-input bg-surface"
              >
                <option
                  v-for="t in relationTypes()"
                  :key="t.key"
                  :value="t.key"
                >
                  {{ t.label }}
                </option>
              </select>
              <input
                v-if="dialog.relType === 'custom'"
                v-model="dialog.label"
                required
                maxlength="120"
                placeholder="Label"
                class="w-full rounded border-input bg-surface"
              />
              <div
                v-if="relationType(dialog.relType).valence"
                class="flex gap-3 text-xs"
              >
                <label
                  ><input
                    v-model.number="dialog.valence"
                    type="radio"
                    :value="1"
                  />
                  +</label
                >
                <label
                  ><input
                    v-model.number="dialog.valence"
                    type="radio"
                    :value="-1"
                  />
                  −</label
                >
                <label
                  ><input
                    v-model.number="dialog.valence"
                    type="radio"
                    :value="0"
                  />
                  unspecified</label
                >
              </div>
              <textarea
                v-model="dialog.description"
                rows="2"
                placeholder="Why do you see this link? (optional)"
                class="w-full rounded border-input bg-surface"
              ></textarea>
              <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" @click="dialog = null"
                  >Cancel</Button
                >
                <Button type="submit">Add relationship</Button>
              </div>
            </form>
            <div v-if="dialog?.type === 'delete'" class="space-y-3 text-sm">
              <p>
                Delete <b>{{ codeName(dialog.id) }}</b
                >, its subcodes, relationships and all coded segments across all
                sources? This cannot be undone.
              </p>
              <div class="flex justify-end gap-2">
                <Button variant="outline" @click="dialog = null">Cancel</Button>
                <Button variant="destructive" @click="submitDelete"
                  >Delete</Button
                >
              </div>
            </div>
          </template>
        </DialogBase>
        <CodeDetailsDialog :codes="flatCodes" />
      </div>
    </template>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, toRaw, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowsPointingOutIcon, PlusIcon } from '@heroicons/vue/24/outline';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout.vue';
import BaseContainer from '../Layouts/BaseContainer.vue';
import ResponsiveTabList from '../Components/lists/ResponsiveTabList.vue';
import Button from '../Components/interactive/Button.vue';
import Headline3 from '../Components/layout/Headline3.vue';
import ContrastText from '../Components/text/ContrastText.vue';
import DialogBase from '../dialogs/DialogBase.vue';
import NoteList from './coding/tree/NoteList.vue';
import WorkflowPanel from './coding/qda/WorkflowPanel.vue';
import MemoPanel from './coding/qda/MemoPanel.vue';
import CodeDetailsDialog from './coding/qda/CodeDetailsDialog.vue';
import MindMapCanvas from '@openqda/visualization/src/mindmap/MindMapCanvas.vue';
import {
  fromNested,
  filterTree,
} from '@openqda/visualization/src/mindmap/tree.js';
import {
  toFreeMind,
  parseFreeMind,
  countNodes,
} from '@openqda/visualization/src/mindmap/freemind.js';
import {
  downloadBlob,
  downloadPng,
  downloadSvg,
  safeFilename,
} from '@openqda/visualization/src/mindmap/exporters.js';
import { useNotes } from '../domain/notes/useNotes.js';
import { useRelations } from '../domain/qda/useRelations.js';
import { useCodeDetailsDialog } from '../domain/qda/useCodeDetailsDialog.js';
import {
  codeTypes,
  kindLabel,
  relationLabel,
  relationType,
  relationTypes,
  vocabularyState,
} from '../domain/qda/vocabulary.js';
import { request } from '../utils/http/BackendRequest.js';
import { flashMessage } from '../Components/notification/flashMessage.js';

// relations and notes are read from the page props by useRelations/useNotes
const props = defineProps(['project', 'projectId', 'codebooks', 'allCodes']);
const pageTitle = `Mind map – ${props.project?.name ?? ''}`;
const fileBase = props.project?.name ?? 'mindmap';

const menuTabs = [
  { value: 'node', label: 'Code' },
  { value: 'workflow', label: 'Overview' },
  { value: 'memos', label: 'Memos' },
];
const menuView = ref('node');

//------------------------------------------------------------------------
// data
//------------------------------------------------------------------------
const codes = ref(structuredClone(toRaw(props.allCodes ?? [])));
// e.g. after a FreeMind import reloaded the page props
watch(
  () => props.allCodes,
  (next) => (codes.value = structuredClone(toRaw(next ?? [])))
);
const { relations, createRelation, prune } = useRelations();
const { notes, initNotes } = useNotes();
onMounted(() => initNotes());

const flatCodes = computed(() => {
  const out = [];
  const walk = (list, parent) =>
    list.forEach((c) => {
      if (c.parent?.id !== parent?.id || c.parent?.kind !== parent?.kind) {
        c.parent = parent ? { id: parent.id, kind: parent.kind } : null;
      }
      out.push(c);
      walk(c.children ?? [], c);
    });
  walk(codes.value, null);
  return out;
});
const byId = computed(() => new Map(flatCodes.value.map((c) => [c.id, c])));
const codeName = (id) => byId.value.get(id)?.name ?? '';

const mode = ref('mindmap');
// show only codes of one type (types come from the project vocabulary)
const typeFilter = ref('');
// relationship types shown in the network view (null = all directed ones)
const networkTypes = ref(null);
const directedTypes = computed(() => relationTypes().filter((t) => t.directed));
const toggleNetworkType = (key) => {
  const current = networkTypes.value ?? directedTypes.value.map((t) => t.key);
  const next = current.includes(key)
    ? current.filter((k) => k !== key)
    : [...current, key];
  networkTypes.value = next.length === directedTypes.value.length ? null : next;
};
const codebookFilter = ref('');
const root = computed(() => {
  const list = codebookFilter.value
    ? codes.value.filter(
        (c) => String(c.codebook) === String(codebookFilter.value)
      )
    : codes.value;
  const tree = fromNested(list, props.project?.name ?? 'Codes');
  return typeFilter.value
    ? filterTree(tree, (n) => (n.kind ?? 'code') === typeFilter.value)
    : tree;
});

const notesCount = computed(() => {
  const out = {};
  (notes.value ?? []).forEach((n) => {
    if (n.type === 'code') out[n.target] = (out[n.target] ?? 0) + 1;
  });
  return out;
});

//------------------------------------------------------------------------
// selection
//------------------------------------------------------------------------
const canvas = ref(null);
const selectedId = ref(null);
const selectedCode = computed(() => byId.value.get(selectedId.value) ?? null);
// codes for the focus picker: related codes first, then the rest by name
const focusChoices = computed(() => {
  const related = new Set(relations.value.flatMap((r) => [r.from, r.to]));
  return [...flatCodes.value].sort(
    (a, b) =>
      (related.has(b.id) ? 1 : 0) - (related.has(a.id) ? 1 : 0) ||
      a.name.localeCompare(b.name)
  );
});
const selectedNotes = computed(() =>
  (notes.value ?? []).filter(
    (n) => n.type === 'code' && n.target === selectedId.value
  )
);
const select = (id) => {
  selectedId.value = id;
  if (byId.value.has(id)) menuView.value = 'node';
};

const { open: openCodeDetails } = useCodeDetailsDialog();
const openDetails = (id, tab = 'details') => {
  const code = byId.value.get(id);
  if (code) openCodeDetails(code, tab);
};

//------------------------------------------------------------------------
// editing
//------------------------------------------------------------------------
const fail = (res, fallback) => {
  if (res.error || res.response?.status >= 400) {
    throw new Error(
      res.response?.data?.message ?? res.error?.message ?? fallback
    );
  }
  return res.response.data;
};
const listOf = (parentId) =>
  parentId ? byId.value.get(parentId)?.children : codes.value;

const reparent = async ({ id, parentId }) => {
  const code = byId.value.get(id);
  const parent = parentId ? byId.value.get(parentId) : null;
  if (!code) return;
  if (parent && String(parent.codebook) !== String(code.codebook)) {
    flashMessage('Codes can only be nested within the same codebook.', {
      type: 'error',
    });
    return;
  }
  try {
    fail(
      await request({
        url: route('coding.update-attribute', {
          project: props.projectId,
          code: id,
        }),
        type: 'patch',
        body: { parent_id: parentId },
      }),
      'Could not move the code.'
    );
    const from = listOf(code.parent?.id ?? null);
    from.splice(
      from.findIndex((c) => c.id === id),
      1
    );
    const to = parent ? (parent.children ??= []) : codes.value;
    to.push(code);
    flashMessage(
      parent ? `Moved under “${parent.name}”` : 'Moved to top level',
      { type: 'success' }
    );
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

const dialog = ref(null);
const dialogTitle = computed(
  () =>
    ({
      rename: 'Rename code',
      add: dialog.value?.parentId
        ? `New subcode of “${codeName(dialog.value.parentId)}”`
        : 'New top-level code',
      relate: 'New relationship',
      delete: 'Delete code',
    })[dialog.value?.action] ?? ''
);

const startRename = (id) => {
  const code = byId.value.get(id);
  if (code)
    dialog.value = reactive({
      type: 'name',
      action: 'rename',
      id,
      value: code.name,
    });
};
const startAddChild = (parentId) => {
  dialog.value = reactive({
    type: 'name',
    action: 'add',
    parentId: parentId && byId.value.has(parentId) ? parentId : null,
    value: '',
  });
};
const startDelete = (id) => {
  if (byId.value.has(id))
    dialog.value = { type: 'delete', action: 'delete', id };
};
const startRelate = ({ from, to }) => {
  dialog.value = reactive({
    type: 'relate',
    action: 'relate',
    from,
    to,
    relType: relationTypes()[0]?.key ?? 'custom',
    valence: 0,
    label: '',
    description: '',
  });
};

const swapRelation = () => {
  const d = dialog.value;
  [d.from, d.to] = [d.to, d.from];
};

const randomHex = () =>
  `#${Math.floor(0x404040 + Math.random() * 0x9f9f9f)
    .toString(16)
    .padStart(6, '0')}`;

const submitName = async () => {
  const d = dialog.value;
  try {
    if (d.action === 'rename') {
      fail(
        await request({
          url: route('coding.update-attribute', {
            project: props.projectId,
            code: d.id,
          }),
          type: 'patch',
          body: { title: d.value },
        }),
        'Could not rename.'
      );
      byId.value.get(d.id).name = d.value;
    } else {
      const parent = d.parentId ? byId.value.get(d.parentId) : null;
      const codebook =
        parent?.codebook ?? (codebookFilter.value || props.codebooks?.[0]?.id);
      if (!codebook)
        throw new Error('Create a codebook on the coding page first.');
      const color = parent?.color ?? randomHex();
      const data = fail(
        await request({
          url: route('coding.store', { project: props.projectId }),
          type: 'post',
          body: {
            title: d.value,
            color,
            codebook,
            parent_id: parent?.id ?? null,
          },
        }),
        'Could not create the code.'
      );
      const code = {
        id: data.id,
        name: d.value,
        color,
        codebook,
        description: '',
        kind: 'code',
        meta: {},
        selectionsCount: 0,
        children: [],
      };
      (parent ? (parent.children ??= []) : codes.value).push(code);
      selectedId.value = code.id;
    }
    dialog.value = null;
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

const submitRelation = async () => {
  const d = dialog.value;
  try {
    await createRelation({
      from: d.from,
      to: d.to,
      type: d.relType,
      label: d.relType === 'custom' ? d.label : undefined,
      valence: relationType(d.relType).valence ? d.valence : undefined,
      description: d.description || undefined,
    });
    dialog.value = null;
    flashMessage('Relationship added', { type: 'success' });
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

const submitDelete = async () => {
  const id = dialog.value.id;
  const code = byId.value.get(id);
  try {
    fail(
      await request({
        url: route('coding.destroy', { project: props.projectId, code: id }),
        type: 'delete',
      }),
      'Could not delete the code.'
    );
    const list = listOf(code.parent?.id ?? null);
    list.splice(
      list.findIndex((c) => c.id === id),
      1
    );
    prune(flatCodes.value.map((c) => c.id));
    if (selectedId.value === id) selectedId.value = null;
    dialog.value = null;
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

//------------------------------------------------------------------------
// FreeMind import / export
//------------------------------------------------------------------------
const exportMm = () => {
  const notesByCode = {};
  (notes.value ?? []).forEach((n) => {
    if (n.type === 'code') (notesByCode[n.target] ??= []).push(n.content);
  });
  const relationLabels = Object.fromEntries(
    vocabularyState.data.relation_types.map((t) => [t.key, t.label])
  );
  const xml = toFreeMind({
    root: root.value,
    relations: relations.value,
    notesByCode,
    relationLabels,
    sides: canvas.value?.sides(),
  });
  downloadBlob(
    new Blob([xml], { type: 'application/x-freemind;charset=utf-8' }),
    safeFilename(fileBase, 'mm')
  );
};

const importMindMap = async (event) => {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file) return;
  try {
    const { title, nodes } = parseFreeMind(await file.text());
    const res = await request({
      url: route('map.import', { project: props.projectId }),
      type: 'post',
      body: { name: title || file.name.replace(/\.mm$/i, ''), nodes },
    });
    fail(res, 'Import failed.');
    flashMessage(`Imported ${countNodes(nodes)} codes into a new codebook`, {
      type: 'success',
    });
    router.reload();
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  }
};

//------------------------------------------------------------------------
// memos from the workflow panel
//------------------------------------------------------------------------
const memoPanelKey = ref(0);
const memoPreset = ref('');
const openMemo = ({ memoType }) => {
  memoPreset.value = memoType ?? '';
  memoPanelKey.value++;
  menuView.value = 'memos';
};
</script>
