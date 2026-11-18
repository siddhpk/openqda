import { describe, expect, it } from 'vitest';
import { editorText, segmentText } from './segment.js';

describe('segmentText', () => {
  const text =
    'I moved here in 2019. It was hard at first!  Did it get better?\n\nYes, after a year it did.\n';

  it('splits sentences with offsets that point into the original text', () => {
    const units = segmentText(text, 'sentence', 'en');
    expect(units.map((u) => u.text)).toEqual([
      'I moved here in 2019.',
      'It was hard at first!',
      'Did it get better?',
      'Yes, after a year it did.',
    ]);
    units.forEach((u) => expect(text.slice(u.start, u.end)).toBe(u.text));
  });

  it('splits paragraphs', () => {
    const units = segmentText(text, 'paragraph');
    expect(units).toHaveLength(2);
    units.forEach((u) => expect(text.slice(u.start, u.end)).toBe(u.text));
  });

  it('keeps Quill indices when the document contains embeds', () => {
    const quill = {
      getContents: () => ({
        ops: [
          { insert: 'Intro text here.\n' },
          { insert: { image: 'x.png' } },
          { insert: '\nAfter the image comes text.\n' },
        ],
      }),
    };
    const t = editorText(quill);
    expect(t.length).toBe(17 + 1 + 29);
    const units = segmentText(t, 'paragraph');
    expect(units.at(-1).start).toBe(19);
    expect(units.at(-1).text).toBe('After the image comes text.');
  });
});
