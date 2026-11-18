/**
 * Segmentation of the open source into analysis units with offsets that
 * match the coding editor's (Quill) index space, so accepted suggestions
 * can be stored as ordinary selections.
 * @module
 */

export const MIN_UNIT_CHARS = 12;

/**
 * Text of the editor where every embed (image, formula …) counts as one
 * character, exactly like Quill's own indices. (quill.getText() would drop
 * embeds and shift all following offsets.)
 * @param quill {object} Quill instance
 * @return {string}
 */
export const editorText = (quill) => {
  const ops = quill?.getContents?.()?.ops ?? [];
  let text = '';
  for (const op of ops) {
    if (typeof op.insert === 'string') text += op.insert;
    else if (op.insert) text += '￼';
  }
  return text;
};

const trimRange = (text, start, end) => {
  while (start < end && /\s/.test(text[start])) start++;
  while (end > start && /\s/.test(text[end - 1])) end--;
  return [start, end];
};

const splitSentences = (paragraph, offset, locale) => {
  const out = [];
  if (typeof Intl !== 'undefined' && Intl.Segmenter) {
    const segmenter = new Intl.Segmenter(locale, { granularity: 'sentence' });
    for (const { segment, index } of segmenter.segment(paragraph)) {
      out.push([offset + index, offset + index + segment.length]);
    }
    return out;
  }
  const re = /[^.!?…]+(?:[.!?…]+|$)/g;
  let m;
  while ((m = re.exec(paragraph)) !== null) {
    if (!m[0].length) break;
    out.push([offset + m.index, offset + m.index + m[0].length]);
  }
  return out;
};

/**
 * @param text {string} editor text (see editorText)
 * @param granularity {'sentence'|'paragraph'}
 * @param locale {string=} BCP-47 tag for sentence rules (default: browser)
 * @return {{start: number, end: number, text: string}[]}
 */
export const segmentText = (text, granularity = 'sentence', locale) => {
  const units = [];
  let pos = 0;
  const paragraphs = text.split('\n');
  for (const paragraph of paragraphs) {
    const pStart = pos;
    pos += paragraph.length + 1;
    if (!paragraph.trim()) continue;
    const ranges =
      granularity === 'paragraph'
        ? [[pStart, pStart + paragraph.length]]
        : splitSentences(paragraph, pStart, locale);
    for (const [s, e] of ranges) {
      const [start, end] = trimRange(text, s, e);
      const unit = text.slice(start, end).replace(/￼/g, '').trim();
      if (unit.length >= MIN_UNIT_CHARS) {
        units.push({ start, end, text: unit });
      }
    }
  }
  return units;
};
