/**
 * Binds a plain <textarea> to a shared Y.Text: typing becomes minimal
 * insertions and deletions, remote changes update the field while the
 * caret stays where it belongs.
 * @module
 */

/**
 * The smallest change that turns `prev` into `next`.
 * @return {{index: number, remove: number, insert: string}}
 */
export const textDiff = (prev, next) => {
  let start = 0;
  while (
    start < prev.length &&
    start < next.length &&
    prev[start] === next[start]
  )
    start++;
  let endPrev = prev.length;
  let endNext = next.length;
  while (
    endPrev > start &&
    endNext > start &&
    prev[endPrev - 1] === next[endNext - 1]
  ) {
    endPrev--;
    endNext--;
  }
  return {
    index: start,
    remove: endPrev - start,
    insert: next.slice(start, endNext),
  };
};

/**
 * Where a caret position moves to after a remote change (Yjs delta).
 * @param pos {number}
 * @param delta {Array<{retain?: number, insert?: string, delete?: number}>}
 */
export const shiftPosition = (pos, delta) => {
  // retain and delete count characters of the old text; inserts add new ones
  let old = 0;
  let out = pos;
  for (const op of delta) {
    if (old > pos) break;
    if (op.retain) {
      old += op.retain;
    } else if (op.insert) {
      const len = typeof op.insert === 'string' ? op.insert.length : 1;
      if (old < pos) out += len; // text typed exactly at the caret stays after it
    } else if (op.delete) {
      if (old < pos) out -= Math.min(op.delete, pos - old);
      old += op.delete;
    }
  }
  return Math.max(0, out);
};

/**
 * @param ytext {import('yjs').Text}
 * @param el {HTMLTextAreaElement}
 * @param onRemote {(value: string) => void} keeps the component's model in step
 * @return {() => void} unbind
 */
export const bindTextarea = (ytext, el, onRemote = () => {}) => {
  let value = ytext.toString();
  el.value = value;
  onRemote(value);

  const onInput = () => {
    const next = el.value;
    if (next === value) return;
    const { index, remove, insert } = textDiff(value, next);
    value = next;
    ytext.doc.transact(() => {
      if (remove) ytext.delete(index, remove);
      if (insert) ytext.insert(index, insert);
    }, 'local');
  };

  const observer = (event, transaction) => {
    if (transaction.origin === 'local') return;
    const focused = document.activeElement === el;
    const start = shiftPosition(el.selectionStart ?? 0, event.delta);
    const end = shiftPosition(el.selectionEnd ?? 0, event.delta);
    value = ytext.toString();
    el.value = value;
    onRemote(value);
    if (focused) el.setSelectionRange(start, end);
  };

  el.addEventListener('input', onInput);
  ytext.observe(observer);
  return () => {
    el.removeEventListener('input', onInput);
    ytext.unobserve(observer);
  };
};
