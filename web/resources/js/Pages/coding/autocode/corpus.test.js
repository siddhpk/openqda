import { describe, expect, it } from 'vitest';
import { buildCorpus, overlaps } from './corpus.js';

const text =
  'I lost my job last year.\nMy sister helped me a lot.\nWe watched football together.\n';
// offsets: unit1 0–24, unit2 25–51, unit3 52–81

describe('buildCorpus', () => {
  const sources = [
    { id: 's1', name: 'Coded', text, target: false, coded: true },
    { id: 's2', name: 'Target', text, target: true, coded: false },
  ];
  const selections = [
    { source_id: 's1', code_id: 'work', start: 0, end: 24 },
    { source_id: 's1', code_id: 'money', start: 2, end: 20 }, // overlapping second code
    { source_id: 's1', code_id: 'family', start: 25, end: 51 },
  ];

  it('labels units with all overlapping codes and uses uncoded units as "no code"', () => {
    const { train, targets, stats } = buildCorpus({
      sources,
      selections,
      granularity: 'paragraph',
    });
    const first = train.find((u) => u.text.startsWith('I lost'));
    expect(first.labels.sort()).toEqual(['money', 'work']);
    expect(train.find((u) => u.text.startsWith('We watched')).labels).toEqual(
      []
    );
    expect(stats.multiCoded).toBe(1);
    expect(stats.counts).toEqual({ work: 1, money: 1, family: 1 });
    // the target source contributes no "no code" units, only targets
    expect(train.filter((u) => u.sourceId === 's2')).toHaveLength(0);
    expect(targets.map((t) => [t.sourceId, t.start, t.end])).toEqual([
      ['s2', 0, 24],
      ['s2', 25, 51],
      ['s2', 52, 81],
    ]);
  });

  it('skips already coded target units unless asked to include them', () => {
    const own = [{ id: 's1', name: 'Own', text, target: true, coded: true }];
    const sel = [{ source_id: 's1', code_id: 'work', start: 0, end: 24 }];
    expect(
      buildCorpus({ sources: own, selections: sel, granularity: 'paragraph' })
        .targets
    ).toHaveLength(2);
    const all = buildCorpus({
      sources: own,
      selections: sel,
      granularity: 'paragraph',
      includeCoded: true,
    });
    expect(all.targets).toHaveLength(3);
    expect(all.targets[0].existing).toEqual(['work']);
    // coded units of a target source are still training data
    expect(all.train).toHaveLength(1);
  });

  it('ignores never-coded sources that are not targets', () => {
    const r = buildCorpus({
      sources: [{ id: 'x', name: 'x', text, target: false, coded: false }],
      selections: [],
      granularity: 'sentence',
    });
    expect(r.train).toHaveLength(0);
    expect(r.targets).toHaveLength(0);
  });

  it('applies the half-overlap rule', () => {
    expect(overlaps({ start: 0, end: 10 }, { start: 4, end: 30 })).toBe(true);
    expect(overlaps({ start: 0, end: 100 }, { start: 90, end: 96 })).toBe(true);
    expect(overlaps({ start: 0, end: 100 }, { start: 95, end: 120 })).toBe(
      false
    );
  });
});
