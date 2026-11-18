import { segmentText } from './segment.js';

/**
 * Builds training and target units from source texts and stored
 * selections. Both live in the coding editor's index space, so a unit's
 * codes are determined exactly by overlap with selections – several codes
 * per unit are kept (overlapping coding).
 * @module
 */

/** overlap rule: at least half of the unit or half of the selection */
export const overlaps = (u, s) => {
  const overlap = Math.min(u.end, s.end) - Math.max(u.start, s.start);
  if (overlap <= 0) return false;
  return (
    overlap >= 0.5 * (u.end - u.start) || overlap >= 0.5 * (s.end - s.start)
  );
};

/**
 * @param input {object}
 * @param input.sources {{id: string, name: string, text: string, target: boolean, coded: boolean}[]}
 * @param input.selections {{source_id: string, code_id: string, start: number, end: number}[]}
 * @param input.granularity {'sentence'|'paragraph'}
 * @param input.includeCoded {boolean} also predict units that already have codes
 * @param input.locale {string=}
 * @param input.maxNegatives {number=} cap for "no code" units (sampled evenly)
 * @return {{train: {sourceId: string, text: string, labels: string[]}[], targets: {sourceId: string, start: number, end: number, text: string, existing: string[]}[], stats: object}}
 */
export const buildCorpus = ({
  sources,
  selections,
  granularity,
  includeCoded = false,
  locale,
  maxNegatives = 3000,
}) => {
  const bySource = new Map();
  selections.forEach((s) => {
    if (!bySource.has(s.source_id)) bySource.set(s.source_id, []);
    bySource.get(s.source_id).push(s);
  });

  const positives = [];
  const negatives = [];
  const targets = [];
  const perSource = [];
  for (const source of sources) {
    const sels = bySource.get(source.id) ?? [];
    const units = segmentText(source.text, granularity, locale);
    let targetCount = 0;
    for (const unit of units) {
      const labels = [
        ...new Set(sels.filter((s) => overlaps(unit, s)).map((s) => s.code_id)),
      ];
      if (labels.length) {
        positives.push({ sourceId: source.id, text: unit.text, labels });
      } else if (!source.target && sels.length) {
        // uncoded units count as "no code" only in sources that were coded,
        // and never in sources that are being autocoded
        negatives.push({ sourceId: source.id, text: unit.text, labels: [] });
      }
      if (source.target && (!labels.length || includeCoded)) {
        targets.push({
          sourceId: source.id,
          start: unit.start,
          end: unit.end,
          text: unit.text,
          existing: labels,
        });
        targetCount++;
      }
    }
    perSource.push({
      id: source.id,
      name: source.name,
      units: units.length,
      targets: targetCount,
      target: source.target,
    });
  }

  // even sample of negatives across sources
  let sampled = negatives;
  if (negatives.length > maxNegatives) {
    const step = negatives.length / maxNegatives;
    sampled = Array.from(
      { length: maxNegatives },
      (_, i) => negatives[Math.floor(i * step)]
    );
  }

  const counts = {};
  positives.forEach((p) =>
    p.labels.forEach((l) => (counts[l] = (counts[l] ?? 0) + 1))
  );
  return {
    train: positives.concat(sampled),
    targets,
    stats: {
      positives: positives.length,
      negatives: sampled.length,
      multiCoded: positives.filter((p) => p.labels.length > 1).length,
      counts,
      perSource,
    },
  };
};
