import { describe, expect, it } from 'vitest';
import * as Y from 'yjs';
import { bindTextarea, shiftPosition, textDiff } from './useYTextarea.js';

const connect = (a, b) => {
  a.on(
    'update',
    (u, origin) => origin !== 'peer' && Y.applyUpdate(b, u, 'peer')
  );
  b.on(
    'update',
    (u, origin) => origin !== 'peer' && Y.applyUpdate(a, u, 'peer')
  );
};

describe('textDiff', () => {
  it('finds the smallest change', () => {
    expect(textDiff('hello world', 'hello brave world')).toEqual({
      index: 6,
      remove: 0,
      insert: 'brave ',
    });
    expect(textDiff('hello brave world', 'hello world')).toEqual({
      index: 6,
      remove: 6,
      insert: '',
    });
    expect(textDiff('abc', 'abc')).toEqual({ index: 3, remove: 0, insert: '' });
    expect(textDiff('aaa', 'aaaa')).toEqual({
      index: 3,
      remove: 0,
      insert: 'a',
    });
  });
});

describe('shiftPosition', () => {
  it('moves the caret by remote changes before it only', () => {
    expect(shiftPosition(5, [{ insert: 'abc' }])).toBe(8);
    expect(shiftPosition(5, [{ retain: 5 }, { insert: 'abc' }])).toBe(5);
    expect(shiftPosition(5, [{ retain: 2 }, { delete: 2 }])).toBe(3);
    expect(shiftPosition(5, [{ retain: 3 }, { delete: 5 }])).toBe(3);
    expect(shiftPosition(5, [{ retain: 7 }, { delete: 1 }])).toBe(5);
    expect(
      shiftPosition(5, [
        { retain: 1 },
        { insert: 'xy' },
        { retain: 1 },
        { delete: 1 },
      ])
    ).toBe(6);
  });
});

describe('bindTextarea', () => {
  it('keeps two textareas in step and the caret in place', () => {
    const docA = new Y.Doc();
    const docB = new Y.Doc();
    connect(docA, docB);
    const a = document.createElement('textarea');
    const b = document.createElement('textarea');
    document.body.append(a, b);
    docA.getText('t').insert(0, 'The nurses were kind.');
    const seenB = [];
    bindTextarea(docA.getText('t'), a);
    bindTextarea(docB.getText('t'), b, (v) => seenB.push(v));
    expect(b.value).toBe('The nurses were kind.');

    // Bob's caret is after "kind"
    b.focus();
    b.setSelectionRange(20, 20);
    // Alice types before it
    a.value = 'The night nurses were kind.';
    a.dispatchEvent(new Event('input'));
    expect(b.value).toBe('The night nurses were kind.');
    expect(b.selectionStart).toBe(26);
    expect(seenB.at(-1)).toBe('The night nurses were kind.');

    // Bob types at the same time Alice deletes elsewhere: both merge
    b.value = 'The night nurses were very kind.';
    b.dispatchEvent(new Event('input'));
    a.value = 'The nurses were very kind.';
    a.dispatchEvent(new Event('input'));
    expect(a.value).toBe(b.value);
    expect(a.value).toBe('The nurses were very kind.');
  });
});
