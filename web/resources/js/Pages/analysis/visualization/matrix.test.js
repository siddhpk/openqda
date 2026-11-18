import { describe, expect, it } from 'vitest';
import {
  buildColumns,
  cooccurrence,
  jaccard,
  orderCodes,
} from '../../../../../plugins/visualization/src/matrix/helpers.js';

describe('matrix helpers', () => {
  const codes = [
    {
      id: 'p',
      name: 'Parent',
      text: [{ id: 's1', source_id: 'A', start: '0', end: '10', text: 'x' }],
    },
    {
      id: 'c',
      name: 'Child',
      parent: 'p',
      text: [
        { id: 's2', source_id: 'A', start: '5', end: '20', text: 'y' },
        { id: 's3', source_id: 'B', start: '0', end: '5', text: 'z' },
      ],
    },
    {
      id: 'o',
      name: 'Other',
      text: [{ id: 's4', source_id: 'A', start: '200', end: '210', text: 'w' }],
    },
  ];

  it('orders codes hierarchically', () => {
    expect(orderCodes(codes).map((r) => [r.code.id, r.depth])).toEqual([
      ['o', 0],
      ['p', 0],
      ['c', 1],
    ]);
  });

  it('groups sources by a variable', () => {
    const cols = buildColumns(
      [
        { id: 'A', name: 'A', variables: { gender: 'f' } },
        { id: 'B', name: 'B', variables: { gender: 'm' } },
        { id: 'C', name: 'C', variables: {} },
      ],
      'gender'
    );
    expect(cols.map((c) => c.sourceIds)).toEqual([['C'], ['A'], ['B']]);
  });

  it('counts overlapping and nearby segments', () => {
    const exact = cooccurrence(codes);
    expect(exact.counts[0][1]).toBe(1); // s1 overlaps s2
    expect(exact.counts[0][2]).toBe(0);
    expect(exact.counts[1][1]).toBe(2);
    const near = cooccurrence(codes, { distance: 200 });
    expect(near.counts[1][2]).toBe(1); // s2 ends at 20, s4 starts at 200
    expect(jaccard(exact.counts, 0, 1)).toBeCloseTo(1 / (1 + 2 - 1), 10);
  });
});
