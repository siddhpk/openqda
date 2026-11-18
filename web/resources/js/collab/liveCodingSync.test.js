import { describe, it, expect, beforeEach } from 'vitest';
import { AbstractStore } from '../state/AbstractStore.js';
import { applySelectionChange, applyCodeChange } from './liveCodingSync.js';

const TEXT = 'The quick brown fox jumps over the lazy dog.';

describe('liveCodingSync', () => {
  let codes;
  let selections;
  let ctx;

  beforeEach(() => {
    codes = new AbstractStore({ projectId: 1, key: 'codes' });
    selections = new AbstractStore({ projectId: 1, key: 'selections' });
    const parent = {
      id: 'c1',
      name: 'Animals',
      color: '#f00',
      codebook: 7,
      parent: null,
      children: [],
      text: [],
      active: true,
    };
    const child = {
      id: 'c2',
      name: 'Fox',
      color: '#0f0',
      codebook: 7,
      parent,
      children: [],
      text: [],
      active: true,
    };
    parent.children.push(child);
    codes.add(parent, child);
    ctx = {
      codes,
      selections,
      getText: (index, length) => TEXT.substring(index, index + length),
    };
  });

  describe('selections', () => {
    const created = {
      action: 'created',
      selection: {
        id: 's1',
        code_id: 'c2',
        start: 16,
        end: 19,
        creating_user_id: 42,
        origin: null,
      },
    };

    it('adds a segment with its coder and the text from the open source', () => {
      expect(applySelectionChange(ctx, created)).toBe(true);
      const entry = selections.entry('s1');
      expect(entry.text).toBe('fox');
      expect(entry.length).toBe(3);
      expect(entry.creating_user_id).toBe(42);
      expect(entry.code.id).toBe('c2');
      expect(codes.entry('c2').text.map((s) => s.id)).toEqual(['s1']);
    });

    it('ignores a segment that is already known', () => {
      applySelectionChange(ctx, created);
      expect(applySelectionChange(ctx, created)).toBe(false);
      expect(selections.size.value).toBe(1);
      expect(codes.entry('c2').text).toHaveLength(1);
    });

    it('ignores segments of codes that are not loaded and invalid spans', () => {
      const unknownCode = {
        ...created,
        selection: { ...created.selection, code_id: 'nope' },
      };
      const emptySpan = {
        ...created,
        selection: { ...created.selection, id: 's2', end: 16 },
      };
      expect(applySelectionChange(ctx, unknownCode)).toBe(false);
      expect(applySelectionChange(ctx, emptySpan)).toBe(false);
      expect(selections.size.value).toBe(0);
    });

    it('moves a recoded segment to the new code', () => {
      applySelectionChange(ctx, created);
      const recoded = {
        action: 'updated',
        selection: { ...created.selection, code_id: 'c1' },
      };
      expect(applySelectionChange(ctx, recoded)).toBe(true);
      expect(selections.entry('s1').code.id).toBe('c1');
      expect(codes.entry('c2').text).toHaveLength(0);
      expect(codes.entry('c1').text.map((s) => s.id)).toEqual(['s1']);
      expect(applySelectionChange(ctx, recoded)).toBe(false);
    });

    it('removes a deleted segment', () => {
      applySelectionChange(ctx, created);
      const deleted = { action: 'deleted', selection: { id: 's1' } };
      expect(applySelectionChange(ctx, deleted)).toBe(true);
      expect(selections.size.value).toBe(0);
      expect(codes.entry('c2').text).toHaveLength(0);
      expect(applySelectionChange(ctx, deleted)).toBe(false);
    });
  });

  describe('codes', () => {
    it('adds a new code under its parent', () => {
      const event = {
        action: 'created',
        code: {
          id: 'c3',
          name: 'Dog',
          color: '#00f',
          codebook: 7,
          parent_id: 'c1',
        },
      };
      expect(applyCodeChange(ctx, event)).toBe(true);
      const dog = codes.entry('c3');
      expect(dog.parent.id).toBe('c1');
      expect(dog.codebook).toBe(7);
      expect(codes.entry('c1').children.map((c) => c.id)).toContain('c3');
      expect(applyCodeChange(ctx, event)).toBe(false);
    });

    it('renames, recolours and moves a code', () => {
      const event = {
        action: 'updated',
        code: { id: 'c2', name: 'Red fox', color: '#0ff', parent_id: null },
      };
      expect(applyCodeChange(ctx, event)).toBe(true);
      const fox = codes.entry('c2');
      expect(fox.name).toBe('Red fox');
      expect(fox.color).toBe('#0ff');
      expect(fox.parent).toBeNull();
      expect(codes.entry('c1').children).toHaveLength(0);
      expect(applyCodeChange(ctx, event)).toBe(false);
    });

    it('removes a deleted code with its subcodes and their segments', () => {
      applySelectionChange(ctx, {
        action: 'created',
        selection: { id: 's1', code_id: 'c2', start: 0, end: 3 },
      });
      const event = { action: 'deleted', code: { id: 'c1' } };
      expect(applyCodeChange(ctx, event)).toBe(true);
      expect(codes.size.value).toBe(0);
      expect(selections.size.value).toBe(0);
      expect(applyCodeChange(ctx, event)).toBe(false);
    });
  });
});
