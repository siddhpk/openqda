<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  shallowRef,
  watch,
} from 'vue';
import { select, zoom as d3zoom, zoomIdentity } from 'd3';
import { ROOT_ID, eachNode, isWithin, findNode, parentOf } from './tree.js';
import {
  label,
  layoutMindMap,
  layoutNetwork,
  layoutFocus,
  linkPath,
  relationPath,
} from './layout.js';
import { toHex } from './freemind.js';

/*------------------------------------------------------------------------------
 | MindMapCanvas.vue
 | FreeMind-like SVG mind map of codes: balanced branches, folding, zoom/pan,
 | keyboard navigation, drag-to-reparent, Shift/Alt-drag to relate, memo
 | badges, relationships as labelled dashed arrows. Also renders a focus
 | view around one code and a network of directed relationships.
 *----------------------------------------------------------------------------*/

const props = defineProps({
  root: { type: Object, required: true },
  relations: { type: Array, default: () => [] },
  notesCount: { type: Object, default: () => ({}) },
  editable: { type: Boolean, default: false },
  mode: { type: String, default: 'mindmap' }, // mindmap | focus | network
  // relationship types shown in the network (null = all directed ones)
  networkTypes: { type: Array, default: null },
  // name of a relationship type, from the project vocabulary
  typeLabel: { type: Function, default: (type) => type },
  // short badge for a code type, e.g. its initial
  kindBadge: { type: Function, default: null },
  focusId: { type: String, default: null },
  selectedId: { type: String, default: null },
  relationLabel: { type: Function, default: (r) => r.label ?? r.type },
});
const emit = defineEmits([
  'select',
  'open',
  'reparent',
  'relate',
  'add-child',
  'rename',
  'delete',
]);

const svgRef = ref(null);
const viewportRef = ref(null);
const transform = ref('');
const collapsed = ref(new Set());
const relateFrom = ref(null);
const drag = shallowRef(null);
let zoomBehavior = null;

//------------------------------------------------------------------------
// layout
//------------------------------------------------------------------------
const codesById = computed(() => {
  const map = new Map();
  eachNode(props.root, (n) => {
    if (n.id !== ROOT_ID) map.set(n.id, n);
  });
  return map;
});

const layout = computed(() => {
  if (props.mode === 'focus') {
    const focus = props.focusId && codesById.value.get(props.focusId);
    if (!focus)
      return {
        nodes: [],
        links: [],
        relations: [],
        headers: [],
        empty: 'Select a code to see everything it is related to.',
      };
    const result = layoutFocus(focus, codesById.value, props.relations, {
      label: props.typeLabel,
    });
    if (result.nodes.length === 1)
      result.empty = `${focus.name} has no relationships yet.`;
    return result;
  }
  if (props.mode === 'network') {
    const result = layoutNetwork(codesById.value, props.relations, {
      types: props.networkTypes,
    });
    if (!result.nodes.length)
      result.empty =
        'No directed relationships of the chosen types yet. Relate codes on the Coding page or with Shift/Alt-drag in the mind map.';
    return result;
  }
  return layoutMindMap(props.root, { collapsed: collapsed.value });
});

const nodeById = computed(
  () => new Map(layout.value.nodes.map((n) => [n.id, n]))
);

const visibleRelations = computed(() => {
  const list =
    props.mode === 'mindmap' ? props.relations : (layout.value.relations ?? []);
  return list
    .filter((r) => nodeById.value.has(r.from) && nodeById.value.has(r.to))
    .map((r, i) => {
      const a = nodeById.value.get(r.from);
      const b = nodeById.value.get(r.to);
      const path = relationPath(
        a,
        b,
        props.mode === 'mindmap' ? 0.25 : 0.12 + (i % 3) * 0.04
      );
      return { r, ...path };
    });
});

//------------------------------------------------------------------------
// colours (explicit values so exported SVG/PNG look the same)
//------------------------------------------------------------------------
const fill = (node) =>
  node.id === ROOT_ID ? '#ffffff' : (toHex(node.data.color) ?? '#cccccc');
const textColor = (hex) => {
  const h = (hex ?? '#ffffff').replace('#', '');
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
  const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  return lum > 0.55 ? '#111827' : '#ffffff';
};
const valenceColor = (r) =>
  r.valence === 1 ? '#15803d' : r.valence === -1 ? '#b91c1c' : '#6b7280';
const markerFor = (r) =>
  r.directed === false
    ? null
    : r.valence === 1
      ? 'url(#mm-arrow-pos)'
      : r.valence === -1
        ? 'url(#mm-arrow-neg)'
        : 'url(#mm-arrow)';
const kindBadge = (node) => {
  if (props.kindBadge) return props.kindBadge(node.data);
  const kind = node.data.kind;
  return kind && kind !== 'code' ? kind.charAt(0).toUpperCase() : '';
};

//------------------------------------------------------------------------
// zoom / pan
//------------------------------------------------------------------------
onMounted(() => {
  zoomBehavior = d3zoom()
    .scaleExtent([0.15, 3])
    .filter(
      (event) =>
        (!event.button &&
          !(event.target.closest && event.target.closest('[data-node]'))) ||
        event.type === 'wheel'
    )
    .on('zoom', (event) => {
      transform.value = event.transform.toString();
    });
  select(svgRef.value).call(zoomBehavior).on('dblclick.zoom', null);
  nextTick(fit);
});

const fit = () => {
  const svg = svgRef.value;
  if (!svg || !zoomBehavior) return;
  const nodes = layout.value.nodes;
  const width = svg.clientWidth || 800;
  const height = svg.clientHeight || 600;
  if (!nodes.length) {
    select(svg).call(
      zoomBehavior.transform,
      zoomIdentity.translate(width / 2, height / 2)
    );
    return;
  }
  let minX = Infinity;
  let maxX = -Infinity;
  let minY = Infinity;
  let maxY = -Infinity;
  nodes.forEach((n) => {
    minX = Math.min(minX, n.x - n.width / 2);
    maxX = Math.max(maxX, n.x + n.width / 2);
    minY = Math.min(minY, n.y - n.height / 2);
    maxY = Math.max(maxY, n.y + n.height / 2);
  });
  (layout.value.headers ?? []).forEach(
    (h) => (minY = Math.min(minY, h.y - 20))
  );
  const pad = 40;
  const scale = Math.min(
    1.4,
    Math.max(
      0.15,
      Math.min(
        width / (maxX - minX + pad * 2),
        height / (maxY - minY + pad * 2)
      )
    )
  );
  const tx = width / 2 - scale * ((minX + maxX) / 2);
  const ty = height / 2 - scale * ((minY + maxY) / 2);
  select(svg).call(
    zoomBehavior.transform,
    zoomIdentity.translate(tx, ty).scale(scale)
  );
};

// refit when the layout type changes, or when the phenomenon of the
// focus changes – never on plain selection in the mind map
watch(
  () => props.mode,
  () => nextTick(fit)
);
watch(
  () => props.focusId,
  () => props.mode === 'focus' && nextTick(fit)
);

//------------------------------------------------------------------------
// folding
//------------------------------------------------------------------------
const toggle = (id) => {
  const next = new Set(collapsed.value);
  next.has(id) ? next.delete(id) : next.add(id);
  collapsed.value = next;
};
const expand = (id) => {
  if (collapsed.value.has(id)) toggle(id);
};
const collapseAll = () => {
  const next = new Set();
  eachNode(
    props.root,
    (n) => n.depth >= 1 && n.children?.length && next.add(n.id)
  );
  collapsed.value = next;
};
const expandAll = () => (collapsed.value = new Set());

//------------------------------------------------------------------------
// pointer: select, drag to reparent, shift/alt-drag to relate
//------------------------------------------------------------------------
const toLocal = (event) => {
  const g = viewportRef.value;
  const pt = svgRef.value.createSVGPoint();
  pt.x = event.clientX;
  pt.y = event.clientY;
  return pt.matrixTransform(g.getScreenCTM().inverse());
};
const hit = ({ x, y }, exclude) =>
  layout.value.nodes.find(
    (n) =>
      n.id !== exclude &&
      Math.abs(n.x - x) <= n.width / 2 &&
      Math.abs(n.y - y) <= n.height / 2
  ) ?? null;

const onNodePointerDown = (event, node) => {
  if (event.button !== 0) return;
  svgRef.value?.focus({ preventScroll: true });
  if (relateFrom.value && relateFrom.value !== node.id) {
    emit('relate', { from: relateFrom.value, to: node.id });
    relateFrom.value = null;
    return;
  }
  drag.value = {
    id: node.id,
    startX: event.clientX,
    startY: event.clientY,
    moved: false,
    relate: event.shiftKey || event.altKey,
    pos: null,
    target: null,
  };
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp, { once: true });
};

const onPointerMove = (event) => {
  const d = drag.value;
  if (!d) return;
  if (
    !d.moved &&
    Math.hypot(event.clientX - d.startX, event.clientY - d.startY) < 5
  )
    return;
  if (!props.editable || d.id === ROOT_ID || props.mode !== 'mindmap') {
    if (!(props.editable && d.relate)) return;
  }
  const pos = toLocal(event);
  drag.value = {
    ...d,
    moved: true,
    relate: d.relate || event.shiftKey || event.altKey,
    pos,
    target: hit(pos, d.id),
  };
};

const onPointerUp = () => {
  window.removeEventListener('pointermove', onPointerMove);
  const d = drag.value;
  drag.value = null;
  if (!d) return;
  if (!d.moved) {
    emit('select', d.id);
    return;
  }
  const target = d.target;
  if (!target || !props.editable) return;
  if (d.relate) {
    if (target.id !== ROOT_ID && d.id !== ROOT_ID)
      emit('relate', { from: d.id, to: target.id });
    return;
  }
  const moving = findNode(props.root, d.id);
  if (moving && isWithin(moving, target.id)) return; // cannot drop into own subtree
  const currentParent = parentOf(props.root, d.id);
  const newParent = target.id === ROOT_ID ? null : target.id;
  if ((currentParent?.id === ROOT_ID ? null : currentParent?.id) === newParent)
    return;
  emit('reparent', { id: d.id, parentId: newParent });
};

onBeforeUnmount(() => window.removeEventListener('pointermove', onPointerMove));

const dropInvalid = computed(() => {
  const d = drag.value;
  if (!d?.target || d.relate) return false;
  const moving = findNode(props.root, d.id);
  return !!moving && isWithin(moving, d.target.id);
});

//------------------------------------------------------------------------
// keyboard (FreeMind-like)
//------------------------------------------------------------------------
const siblingsOf = (id) => {
  const parent = parentOf(props.root, id);
  if (!parent) return [];
  const n = nodeById.value.get(id);
  return (parent.children ?? []).filter(
    (c) => nodeById.value.get(c.id)?.side === n?.side
  );
};
const onKeyDown = (event) => {
  const id = props.selectedId;
  if (!id) return;
  const node = nodeById.value.get(id);
  const data = codesById.value.get(id) ?? (id === ROOT_ID ? props.root : null);
  if (!node || !data) return;
  const goParent = () => {
    const p = parentOf(props.root, id);
    if (p) emit('select', p.id);
  };
  const goChild = (side) => {
    expand(id);
    const child =
      id === ROOT_ID
        ? layout.value.nodes.find((n) => n.depth === 1 && n.side === side)
        : nodeById.value.get(data.children?.[0]?.id);
    if (child) emit('select', child.id);
  };
  switch (event.key) {
    case 'ArrowRight':
      node.side < 0 ? goParent() : goChild(1);
      break;
    case 'ArrowLeft':
      node.side > 0 ? goParent() : goChild(-1);
      break;
    case 'ArrowUp':
    case 'ArrowDown': {
      const sibs = siblingsOf(id);
      const i = sibs.findIndex((s) => s.id === id);
      const next = sibs[i + (event.key === 'ArrowUp' ? -1 : 1)];
      if (next) emit('select', next.id);
      break;
    }
    case ' ':
      if (data.children?.length) toggle(id);
      break;
    case 'Enter':
    case 'F2':
      if (props.editable && id !== ROOT_ID) emit('rename', id);
      break;
    case 'Insert':
    case 'Tab':
      if (props.editable) emit('add-child', id === ROOT_ID ? null : id);
      break;
    case 'Delete':
      if (props.editable && id !== ROOT_ID) emit('delete', id);
      break;
    case 'r':
    case 'R':
      if (props.editable && id !== ROOT_ID)
        relateFrom.value = relateFrom.value ? null : id;
      break;
    case 'Escape':
      relateFrom.value = null;
      break;
    default:
      return;
  }
  event.preventDefault();
};

//------------------------------------------------------------------------
// exposed API
//------------------------------------------------------------------------
const sides = () => {
  const out = {};
  layout.value.nodes.forEach((n) => n.depth === 1 && (out[n.id] = n.side));
  return out;
};
defineExpose({
  fit,
  collapseAll,
  expandAll,
  sides,
  svg: () => svgRef.value,
  collapsed,
  startRelation: (id) => (relateFrom.value = id),
});
</script>

<template>
  <div class="relative w-full h-full">
    <svg
      ref="svgRef"
      class="w-full h-full outline-hidden select-none"
      tabindex="0"
      role="tree"
      aria-label="Mind map of codes"
      font-family="ui-sans-serif, system-ui, sans-serif"
      @keydown="onKeyDown"
    >
      <defs>
        <marker
          id="mm-arrow"
          viewBox="0 0 10 10"
          refX="9"
          refY="5"
          markerWidth="7"
          markerHeight="7"
          orient="auto-start-reverse"
        >
          <path d="M0,0 L10,5 L0,10 z" fill="#6b7280" />
        </marker>
        <marker
          id="mm-arrow-pos"
          viewBox="0 0 10 10"
          refX="9"
          refY="5"
          markerWidth="7"
          markerHeight="7"
          orient="auto-start-reverse"
        >
          <path d="M0,0 L10,5 L0,10 z" fill="#15803d" />
        </marker>
        <marker
          id="mm-arrow-neg"
          viewBox="0 0 10 10"
          refX="9"
          refY="5"
          markerWidth="7"
          markerHeight="7"
          orient="auto-start-reverse"
        >
          <path d="M0,0 L10,5 L0,10 z" fill="#b91c1c" />
        </marker>
      </defs>
      <g ref="viewportRef" :transform="transform">
        <!-- group headers (focus view) -->
        <text
          v-for="h in layout.headers ?? []"
          :key="h.type"
          :x="h.x"
          :y="h.y"
          text-anchor="middle"
          font-size="12"
          font-weight="600"
          fill="#6b7280"
        >
          {{ h.title }}
        </text>

        <!-- hierarchy edges -->
        <path
          v-for="l in layout.links"
          :key="`${l.source.id}-${l.target.id}`"
          :d="linkPath(l.source, l.target)"
          fill="none"
          :stroke="fill(l.target)"
          stroke-width="2.5"
          stroke-linecap="round"
        />

        <!-- relationships -->
        <g v-for="v in visibleRelations" :key="v.r.id">
          <path
            :d="v.d"
            fill="none"
            :stroke="valenceColor(v.r)"
            stroke-width="1.5"
            stroke-dasharray="6 4"
            :marker-end="markerFor(v.r)"
          >
            <title>
              {{ props.relationLabel(v.r)
              }}{{ v.r.description ? ` – ${v.r.description}` : '' }}
            </title>
          </path>
          <text
            :x="v.labelX"
            :y="v.labelY - 4"
            text-anchor="middle"
            font-size="10"
            :fill="valenceColor(v.r)"
            paint-order="stroke"
            stroke="#ffffff"
            stroke-width="3"
          >
            {{ props.relationLabel(v.r) }}
          </text>
        </g>

        <!-- nodes -->
        <g
          v-for="n in layout.nodes"
          :key="n.id"
          data-node="true"
          :transform="`translate(${n.x},${n.y})`"
          :class="
            editable && n.id !== ROOT_ID ? 'cursor-grab' : 'cursor-pointer'
          "
          role="treeitem"
          :aria-selected="n.id === selectedId"
          :aria-expanded="n.hasChildren ? !n.collapsed : undefined"
          @pointerdown.stop="onNodePointerDown($event, n)"
          @mousedown.stop
          @dblclick.stop="emit('open', n.id)"
        >
          <rect
            :x="-n.width / 2"
            :y="-n.height / 2"
            :width="n.width"
            :height="n.height"
            :rx="n.id === ROOT_ID ? n.height / 2 : 7"
            :fill="fill(n)"
            :stroke="
              drag?.target?.id === n.id
                ? dropInvalid
                  ? '#b91c1c'
                  : '#2563eb'
                : n.id === selectedId || n.id === relateFrom
                  ? '#111827'
                  : n.id === ROOT_ID
                    ? '#374151'
                    : 'rgba(0,0,0,0.15)'
            "
            :stroke-width="
              n.id === selectedId ||
              drag?.target?.id === n.id ||
              n.id === relateFrom
                ? 3
                : n.id === ROOT_ID
                  ? 2
                  : 1
            "
            :stroke-dasharray="n.id === relateFrom ? '4 3' : null"
          />
          <text
            :x="kindBadge(n) ? 7 : 0"
            y="1"
            text-anchor="middle"
            dominant-baseline="middle"
            :font-size="n.id === ROOT_ID ? 14 : 12"
            :font-weight="
              n.id === ROOT_ID || n.data.kind !== 'code' ? 600 : 400
            "
            :fill="textColor(fill(n))"
          >
            {{ label(n.data.name) }}
            <title>
              {{ n.data.name
              }}{{ n.data.count ? ` – ${n.data.count} coded segments` : '' }}
            </title>
          </text>
          <!-- kind badge -->
          <g
            v-if="kindBadge(n)"
            :transform="`translate(${-n.width / 2 + 13},0)`"
          >
            <circle
              r="8"
              fill="rgba(255,255,255,0.85)"
              stroke="rgba(0,0,0,0.2)"
            />
            <text
              text-anchor="middle"
              dominant-baseline="middle"
              font-size="9"
              font-weight="700"
              fill="#111827"
              y="1"
            >
              {{ kindBadge(n) }}
            </text>
          </g>
          <!-- memo badge -->
          <g
            v-if="notesCount[n.id]"
            :transform="`translate(${n.width / 2 - 2},${-n.height / 2 + 2})`"
          >
            <rect
              x="-8"
              y="-8"
              width="16"
              height="13"
              rx="3"
              fill="#fde68a"
              stroke="#b45309"
              stroke-width="1"
            />
            <text text-anchor="middle" font-size="9" y="2" fill="#78350f">
              {{ notesCount[n.id] }}
            </text>
            <title>{{ notesCount[n.id] }} memo(s)</title>
          </g>
          <!-- fold toggle -->
          <g
            v-if="n.hasChildren && n.id !== ROOT_ID && mode === 'mindmap'"
            :transform="`translate(${(n.side < 0 ? -1 : 1) * (n.width / 2 + 9)},0)`"
            class="cursor-pointer"
            @pointerdown.stop="toggle(n.id)"
          >
            <circle r="7" fill="#ffffff" :stroke="fill(n)" stroke-width="2" />
            <text
              text-anchor="middle"
              dominant-baseline="middle"
              font-size="11"
              y="1"
              fill="#111827"
            >
              {{ n.collapsed ? '+' : '−' }}
            </text>
            <title>{{ n.collapsed ? 'Unfold' : 'Fold' }}</title>
          </g>
        </g>

        <!-- drag ghost -->
        <g
          v-if="drag?.moved && drag.pos"
          :transform="`translate(${drag.pos.x},${drag.pos.y})`"
          pointer-events="none"
        >
          <rect
            x="-50"
            y="-12"
            width="100"
            height="24"
            rx="6"
            fill="rgba(37,99,235,0.15)"
            stroke="#2563eb"
            stroke-dasharray="4 3"
          />
          <text
            text-anchor="middle"
            dominant-baseline="middle"
            font-size="11"
            fill="#1e3a8a"
            y="1"
          >
            {{ drag.relate ? 'relate…' : 'move…' }}
          </text>
        </g>
      </g>
    </svg>
    <div
      v-if="layout.empty"
      class="absolute inset-0 flex items-center justify-center pointer-events-none text-sm opacity-70 p-6 text-center"
    >
      {{ layout.empty }}
    </div>
    <div
      v-if="relateFrom"
      class="absolute top-2 left-1/2 -translate-x-1/2 text-xs bg-white/90 border rounded px-2 py-1 shadow text-gray-900"
    >
      Click the code to relate to (Esc to cancel)
    </div>
  </div>
</template>
