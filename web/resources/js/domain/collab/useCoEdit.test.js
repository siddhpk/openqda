import { describe, expect, it } from 'vitest';
import * as Y from 'yjs';
import { pickLeader, seedUpdate } from './useCoEdit.js';

describe('seedUpdate', () => {
  it('seeds the same text identically on every laptop, so first openers do not duplicate it', () => {
    const fill = (t) => t.insert(0, 'Interview with a nurse');
    const alice = new Y.Doc();
    const bob = new Y.Doc();
    Y.applyUpdate(alice, seedUpdate(fill));
    Y.applyUpdate(bob, seedUpdate(fill));
    // both type, then exchange everything
    alice.getText('t').insert(0, 'A: ');
    bob.getText('t').insert(22, ' (2024)');
    Y.applyUpdate(alice, Y.encodeStateAsUpdate(bob));
    Y.applyUpdate(bob, Y.encodeStateAsUpdate(alice));
    expect(alice.getText('t').toString()).toBe(
      'A: Interview with a nurse (2024)'
    );
    expect(bob.getText('t').toString()).toBe(alice.getText('t').toString());
  });

  it('keeps rich text (Quill deltas) identical too', () => {
    const fill = (t) =>
      t.applyDelta([
        { insert: 'Title', attributes: { header: 1 } },
        { insert: '\nBody\n' },
      ]);
    expect(seedUpdate(fill)).toEqual(seedUpdate(fill));
  });
});

describe('pickLeader', () => {
  it('chooses one editor deterministically', () => {
    expect(pickLeader([])).toBeNull();
    expect(pickLeader(['nb:2', 'na:9', 'nb:1'])).toBe('na:9');
    expect(pickLeader(['na:9', 'nb:1'])).toBe(pickLeader(['nb:1', 'na:9']));
  });
});
