/**
 * Normalises OpenQDA codes into a single mind-map tree.
 * @module
 */

export const ROOT_ID = '__root__';

const toNode = (code, depth) => ({
  id: code.id,
  name: code.name ?? '',
  color: code.color ?? '#999999',
  kind: code.kind ?? 'code',
  meta: code.meta ?? {},
  codebook: code.codebook ?? null,
  count: code.selectionsCount ?? code.text?.length ?? 0,
  depth,
  children: [],
});

/**
 * From nested codes ({children: [...]}) – used on the map page.
 */
export const fromNested = (codes, title = 'Codes') => {
  const walk = (list, depth) =>
    (list ?? []).map((code) => {
      const node = toNode(code, depth);
      node.children = walk(code.children, depth + 1);
      return node;
    });
  return {
    id: ROOT_ID,
    name: title,
    color: '#ffffff',
    kind: 'root',
    meta: {},
    depth: 0,
    count: 0,
    children: walk(codes, 1),
  };
};

/**
 * From a flat list where each code references its parent by id
 * (code.parent may be an id or an object) – used on the analysis page.
 * Codes whose parent is not in the list become top-level nodes.
 */
export const fromFlat = (codes, title = 'Codes') => {
  const parentId = (c) =>
    (typeof c.parent === 'object' ? c.parent?.id : c.parent) ??
    c.parent_id ??
    null;
  const ids = new Set(codes.map((c) => c.id));
  const byParent = new Map();
  codes.forEach((c) => {
    const p = ids.has(parentId(c)) ? parentId(c) : ROOT_ID;
    if (!byParent.has(p)) byParent.set(p, []);
    byParent.get(p).push(c);
  });
  const walk = (pid, depth) =>
    (byParent.get(pid) ?? []).map((code) => {
      const node = toNode(code, depth);
      node.children = walk(code.id, depth + 1);
      return node;
    });
  return {
    id: ROOT_ID,
    name: title,
    color: '#ffffff',
    kind: 'root',
    meta: {},
    depth: 0,
    count: 0,
    children: walk(ROOT_ID, 1),
  };
};

/** depth-first iteration */
export const eachNode = (node, fn, parent = null) => {
  fn(node, parent);
  (node.children ?? []).forEach((child) => eachNode(child, fn, node));
};

export const findNode = (root, id) => {
  let found = null;
  eachNode(root, (n) => {
    if (!found && n.id === id) found = n;
  });
  return found;
};

export const parentOf = (root, id) => {
  let found = null;
  eachNode(root, (n, parent) => {
    if (!found && n.id === id) found = parent;
  });
  return found;
};

/** true when candidate is node itself or one of its descendants */
export const isWithin = (node, candidateId) => {
  let hit = false;
  eachNode(node, (n) => {
    if (n.id === candidateId) hit = true;
  });
  return hit;
};

/**
 * Keeps only nodes that satisfy predicate, plus their ancestors
 * (e.g. only codes of one type).
 */
export const filterTree = (root, predicate) => {
  const walk = (node) => {
    const children = (node.children ?? []).map(walk).filter(Boolean);
    if (node.id === ROOT_ID || predicate(node) || children.length)
      return { ...node, children };
    return null;
  };
  return walk(root);
};

/** number of leaves – used to balance left/right branches */
export const leafCount = (node, collapsed = new Set()) =>
  !node.children?.length || collapsed.has(node.id)
    ? 1
    : node.children.reduce((s, c) => s + leafCount(c, collapsed), 0);
