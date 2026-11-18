import { hierarchy, tree as d3tree } from 'd3';
import { ROOT_ID, leafCount } from './tree.js';

/**
 * Layouts for the mind map:
 *  - layoutMindMap: FreeMind-like, branches balanced left/right of the root
 *  - layoutFocus: one code in the centre, everything it is related to
 *    around it, grouped by relationship type (incoming left, outgoing right,
 *    undirected above)
 *  - layoutNetwork: directed relationships, layered left → right
 * Relationship types come from the project's own vocabulary; the layouts do
 * not assume any particular methodology.
 * All functions are pure and deterministic (no DOM).
 * @module
 */

export const NODE_HEIGHT = 30;
export const MAX_LABEL = 36;

export const label = (name) =>
  name.length > MAX_LABEL ? `${name.slice(0, MAX_LABEL - 1)}…` : name;

/** estimated label box width (12px sans-serif) */
export const nodeWidth = (node) =>
  Math.round(
    label(node.name ?? '').length * 7 +
      28 +
      (node.kind && node.kind !== 'code' && node.kind !== 'root' ? 18 : 0)
  );

/**
 * Splits top-level branches into right and left so that both sides hold a
 * similar number of leaves (FreeMind alternates in a similar way).
 */
export const balanceSides = (children, collapsed) => {
  const right = [];
  const left = [];
  let r = 0;
  let l = 0;
  children.forEach((child) => {
    const size = leafCount(child, collapsed);
    if (r <= l) {
      right.push(child);
      r += size;
    } else {
      left.push(child);
      l += size;
    }
  });
  return { right, left };
};

/**
 * @param root {object} tree from tree.js
 * @param options {{collapsed?: Set<string>, gap?: number}}
 * @return {{nodes: object[], links: {source: object, target: object}[]}}
 */
export const layoutMindMap = (
  root,
  { collapsed = new Set(), gap = 48 } = {}
) => {
  const nodes = [];
  const links = [];
  const rootWidth = nodeWidth(root) + 20;
  const rootNode = {
    id: root.id,
    data: root,
    x: 0,
    y: 0,
    width: rootWidth,
    height: NODE_HEIGHT + 10,
    depth: 0,
    side: 0,
    hasChildren: !!root.children?.length,
    collapsed: false,
  };
  nodes.push(rootNode);

  const visibleChildren = (n) =>
    collapsed.has(n.id) ? [] : (n.children ?? []);
  const { right, left } = balanceSides(root.children ?? [], collapsed);

  const placeSide = (branches, side) => {
    if (!branches.length) return;
    const h = hierarchy({ id: '__side__', children: branches }, (d) =>
      d.id === '__side__' ? d.children : visibleChildren(d)
    );
    d3tree()
      .nodeSize([NODE_HEIGHT + 8, 1])
      .separation((a, b) => (a.parent === b.parent ? 1 : 1.3))(h);

    // column x per depth: widest label of the previous depth + gap
    const maxWidth = [];
    h.each((d) => {
      if (d.depth === 0) return;
      maxWidth[d.depth] = Math.max(maxWidth[d.depth] ?? 0, nodeWidth(d.data));
    });
    const colX = [rootWidth / 2 + gap];
    for (let depth = 1; depth < maxWidth.length; depth++) {
      colX[depth] = (colX[depth - 1] ?? 0) + (maxWidth[depth] ?? 0) + gap;
    }

    const byHier = new Map();
    h.each((d) => {
      if (d.depth === 0) return;
      const width = nodeWidth(d.data);
      const left = colX[d.depth - 1];
      const node = {
        id: d.data.id,
        data: d.data,
        x: side * (left + width / 2),
        y: d.x,
        width,
        height: NODE_HEIGHT,
        depth: d.depth,
        side,
        hasChildren: !!d.data.children?.length,
        collapsed: collapsed.has(d.data.id),
      };
      byHier.set(d, node);
      nodes.push(node);
      const parent = d.depth === 1 ? rootNode : byHier.get(d.parent);
      links.push({ source: parent, target: node });
    });
  };

  placeSide(right, 1);
  placeSide(left, -1);
  return { nodes, links };
};

/** connection points on the facing edges of two boxes */
export const edgePoints = (a, b) => {
  const dir = b.x >= a.x ? 1 : -1;
  return {
    x1: a.x + (dir * a.width) / 2,
    y1: a.y,
    x2: b.x - (dir * b.width) / 2,
    y2: b.y,
  };
};

/** FreeMind-style bezier edge between parent and child */
export const linkPath = (source, target) => {
  const { x1, y1, x2, y2 } = edgePoints(source, target);
  const mx = (x1 + x2) / 2;
  return `M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`;
};

/** curved arrow for relationships (bends away from the straight line) */
export const relationPath = (a, b, bend = 0.25) => {
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  const len = Math.hypot(dx, dy) || 1;
  // stop at the box boundary
  const shrink = (box, ux, uy) => {
    const tx = Math.abs(ux) > 1e-9 ? box.width / 2 / Math.abs(ux) : Infinity;
    const ty = Math.abs(uy) > 1e-9 ? box.height / 2 / Math.abs(uy) : Infinity;
    return Math.min(tx, ty) + 4;
  };
  const ux = dx / len;
  const uy = dy / len;
  const s = shrink(a, ux, uy);
  const e = shrink(b, ux, uy);
  const x1 = a.x + ux * s;
  const y1 = a.y + uy * s;
  const x2 = b.x - ux * e;
  const y2 = b.y - uy * e;
  const cx = (x1 + x2) / 2 - uy * len * bend;
  const cy = (y1 + y2) / 2 + ux * len * bend;
  return {
    d: `M${x1},${y1} Q${cx},${cy} ${x2},${y2}`,
    labelX: (x1 + 2 * cx + x2) / 4,
    labelY: (y1 + 2 * cy + y2) / 4,
  };
};

const box = (data, x, y, extra = {}) => ({
  id: data.id,
  data,
  x,
  y,
  width: nodeWidth(data),
  height: NODE_HEIGHT,
  depth: 1,
  side: 0,
  hasChildren: false,
  collapsed: false,
  ...extra,
});

const GROUP_GAP = 26;

/**
 * Focus view around one code. `label(type)` names a relationship type.
 */
export const layoutFocus = (
  focus,
  codesById,
  relations,
  { label: typeLabel = (type) => type } = {}
) => {
  const center = box(focus, 0, 0, { height: NODE_HEIGHT + 10, depth: 0 });
  const nodes = [center];
  const sides = { left: new Map(), right: new Map(), top: new Map() };
  const used = [];
  relations.forEach((r) => {
    const otherId =
      r.to === focus.id ? r.from : r.from === focus.id ? r.to : null;
    if (!otherId || otherId === focus.id || !codesById.get(otherId)) return;
    const side =
      r.directed === false ? 'top' : r.to === focus.id ? 'left' : 'right';
    const key = r.type === 'custom' ? `custom:${r.label ?? ''}` : r.type;
    const group = sides[side].get(key) ?? {
      type: key,
      title: r.type === 'custom' ? (r.label ?? '') : typeLabel(r.type),
      codes: [],
    };
    if (!group.codes.find((c) => c.id === otherId))
      group.codes.push(codesById.get(otherId));
    sides[side].set(key, group);
    used.push(r);
  });
  const headers = [];
  const place = (side, x) => {
    const groups = [...sides[side].values()];
    const height = groups.reduce(
      (sum, g) => sum + g.codes.length * (NODE_HEIGHT + 8) + GROUP_GAP,
      0
    );
    let y = -height / 2;
    groups.forEach((g) => {
      headers.push({ type: `${side}:${g.type}`, title: g.title, x, y });
      y += 16;
      g.codes.forEach((code) => {
        nodes.push(box(code, x, y + NODE_HEIGHT / 2, { role: g.type }));
        y += NODE_HEIGHT + 8;
      });
      y += GROUP_GAP - 16;
    });
  };
  place('left', -330);
  place('right', 330);
  // undirected relationships above the centre, side by side
  [...sides.top.values()].forEach((g, gi, all) => {
    const x = (gi - (all.length - 1) / 2) * 240;
    const top = -190 - g.codes.length * (NODE_HEIGHT + 8);
    headers.push({ type: `top:${g.type}`, title: g.title, x, y: top - 6 });
    g.codes.forEach((code, i) =>
      nodes.push(
        box(code, x, top + 18 + i * (NODE_HEIGHT + 8), { role: g.type })
      )
    );
  });
  return { nodes, links: [], relations: used, headers };
};

/**
 * Network of directed relationships, layered by longest path from the
 * sources of the network. `types` limits it to some relationship types
 * (default: every directed relationship).
 */
export const layoutNetwork = (codesById, relations, { types = null } = {}) => {
  const rels = relations.filter(
    (r) =>
      r.directed !== false &&
      (!types || types.includes(r.type)) &&
      codesById.has(r.from) &&
      codesById.has(r.to) &&
      r.from !== r.to
  );
  const edges = rels.map((r) => ({ from: r.from, to: r.to, r }));
  const ids = [...new Set(edges.flatMap((e) => [e.from, e.to]))];
  const layer = new Map(ids.map((id) => [id, 0]));
  // relax edges (bounded to avoid infinite loops on cycles)
  for (let i = 0; i < ids.length; i++) {
    let changed = false;
    for (const e of edges) {
      if (
        layer.get(e.to) < layer.get(e.from) + 1 &&
        layer.get(e.from) + 1 < ids.length
      ) {
        layer.set(e.to, layer.get(e.from) + 1);
        changed = true;
      }
    }
    if (!changed) break;
  }
  const byLayer = new Map();
  ids.forEach((id) => {
    const l = layer.get(id);
    if (!byLayer.has(l)) byLayer.set(l, []);
    byLayer.get(l).push(id);
  });
  const nodes = [];
  [...byLayer.keys()]
    .sort((a, b) => a - b)
    .forEach((l) => {
      const list = byLayer
        .get(l)
        .sort((a, b) =>
          codesById.get(a).name.localeCompare(codesById.get(b).name)
        );
      list.forEach((id, i) => {
        nodes.push(
          box(codesById.get(id), l * 230, (i - (list.length - 1) / 2) * 70, {
            depth: l,
          })
        );
      });
    });
  return { nodes, links: [], relations: rels };
};

export { ROOT_ID };
