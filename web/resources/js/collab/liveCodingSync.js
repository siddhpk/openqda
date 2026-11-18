import { toRaw } from 'vue';

/**
 * Applies changes that other team members made (received over the
 * WebSocket) to the local code and selection stores. Each function is
 * idempotent: an event for a change that is already known changes nothing,
 * so an event that arrives twice, or one's own change, is harmless.
 * @module
 */

const removeById = (list, id) => {
  if (!Array.isArray(list)) return;
  const index = list.findIndex((item) => item?.id === id);
  if (index > -1) list.splice(index, 1);
};

/**
 * @param ctx {object}
 * @param ctx.selections {AbstractStore} selections of the open source
 * @param ctx.codes {AbstractStore} codes of the project
 * @param ctx.getText {(index: number, length: number) => string=} reads the
 *   segment's text from the open source (the event does not carry it)
 * @param event {{action: 'created'|'updated'|'deleted', selection: object}}
 * @return {boolean} whether the stores changed
 */
export const applySelectionChange = ({ selections, codes, getText }, event) => {
  const { action, selection } = event ?? {};
  if (!selection?.id) return false;
  const existing = selections.entries[selection.id];

  if (action === 'deleted') {
    if (!existing) return false;
    const owner = existing.code?.id ? codes.entries[existing.code.id] : null;
    removeById(owner?.text ?? existing.code?.text, existing.id);
    selections.remove(existing.id);
    return true;
  }

  if (action !== 'created' && action !== 'updated') return false;

  // a code that is not loaded here cannot be shown
  const reactiveCode = codes.entries[selection.code_id];
  if (!reactiveCode) return false;
  const code = toRaw(reactiveCode);

  if (existing) {
    // recoded
    if (existing.code?.id === code.id) return false;
    const previous = existing.code?.id ? codes.entries[existing.code.id] : null;
    removeById(previous?.text ?? existing.code?.text, existing.id);
    reactiveCode.text = reactiveCode.text ?? [];
    reactiveCode.text.push(existing);
    selections.update(existing.id, { code });
    return true;
  }

  const start = Number(selection.start);
  const end = Number(selection.end);
  if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
    return false;
  }

  const entry = {
    id: selection.id,
    start,
    end,
    length: end - start,
    text: getText ? getText(start, end - start) : '',
    code,
    notes: [],
    creating_user_id: selection.creating_user_id ?? null,
    origin: selection.origin ?? null,
  };
  reactiveCode.text = reactiveCode.text ?? [];
  reactiveCode.text.push(entry);
  selections.add(entry);
  return true;
};

/**
 * @param ctx {object}
 * @param ctx.codes {AbstractStore} codes of the project
 * @param ctx.selections {AbstractStore} selections of the open source
 * @param event {{action: 'created'|'updated'|'deleted', code: object}}
 * @return {boolean} whether the stores changed
 */
export const applyCodeChange = ({ codes, selections }, event) => {
  const { action, code } = event ?? {};
  if (!code?.id) return false;
  const existing = codes.entries[code.id];

  if (action === 'deleted') {
    if (!existing) return false;
    // the server deletes subcodes and all their segments as well
    const ids = new Set();
    const collect = (c) => {
      ids.add(c.id);
      (c.children ?? []).forEach(collect);
    };
    collect(existing);

    const parent = existing.parent ? codes.entries[existing.parent.id] : null;
    if (parent) removeById(parent.children, existing.id);

    const segments = selections
      .all()
      .filter((s) => ids.has(s.code?.id))
      .map((s) => s.id);
    if (segments.length) selections.remove(...segments);
    codes.remove(...[...ids].filter((id) => id in codes.entries));
    return true;
  }

  if (action !== 'created' && action !== 'updated') return false;

  if (!existing) {
    const parent = code.parent_id ? codes.entries[code.parent_id] : null;
    const entry = {
      id: code.id,
      name: code.name,
      color: code.color,
      description: code.description ?? '',
      codebook: Number(code.codebook),
      kind: code.kind ?? 'code',
      meta: {},
      text: [],
      children: [],
      notes: [],
      active: parent ? parent.active !== false : true,
      order: codes.size.value,
      parent: parent ? toRaw(parent) : null,
    };
    // push to the reactive parent, so that the code tree updates
    if (parent) {
      parent.children = parent.children ?? [];
      parent.children.push(entry);
    }
    codes.add(entry);
    return true;
  }

  const diff = {};
  ['name', 'color', 'description', 'kind'].forEach((key) => {
    if (key in code && code[key] !== existing[key]) diff[key] = code[key];
  });

  const oldParentId = existing.parent?.id ?? null;
  const newParentId = code.parent_id ?? null;
  if ('parent_id' in code && oldParentId !== newParentId) {
    const newParent = newParentId ? codes.entries[newParentId] : null;
    // only move when the new parent is known here
    if (!newParentId || newParent) {
      const oldParent = oldParentId ? codes.entries[oldParentId] : null;
      if (oldParent) removeById(oldParent.children, existing.id);
      if (newParent) {
        newParent.children = newParent.children ?? [];
        newParent.children.push(existing);
      }
      diff.parent = newParent ? toRaw(newParent) : null;
    }
  }

  if (!Object.keys(diff).length) return false;
  codes.update(existing.id, diff);

  // segments of this code are re-rendered (colour, name)
  selections.update((all) => all.filter((s) => s.code?.id === existing.id));
  return true;
};
