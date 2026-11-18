/**
 * Pure helpers for the matrix displays.
 * @module
 */

const num = (v) => Number(v ?? 0);

/** codes in hierarchical order (parents before children, alphabetical) */
export const orderCodes = (codes) => {
  const byId = new Map(codes.map((c) => [c.id, c]));
  const pid = (c) => (typeof c.parent === 'object' ? c.parent?.id : c.parent);
  const children = new Map();
  codes.forEach((c) => {
    const p = byId.has(pid(c)) ? pid(c) : null;
    if (!children.has(p)) children.set(p, []);
    children.get(p).push(c);
  });
  const out = [];
  const walk = (p, depth) =>
    (children.get(p) ?? [])
      .toSorted((a, b) => a.name.localeCompare(b.name))
      .forEach((c) => {
        out.push({ code: c, depth });
        walk(c.id, depth + 1);
      });
  walk(null, 0);
  return out;
};

/** variable names present on sources (internal flags excluded) */
export const variableNames = (sources) => {
  const names = new Set();
  sources.forEach((s) =>
    Object.keys(s.variables ?? {}).forEach(
      (k) => k !== 'isLocked' && names.add(k)
    )
  );
  return [...names].sort();
};

/**
 * Columns: one per source, or one per value of a source variable
 * ("partitioning variables").
 */
export const buildColumns = (sources, variable = null) => {
  if (!variable)
    return sources.map((s) => ({
      key: s.id,
      label: s.name,
      sourceIds: [s.id],
    }));
  const groups = new Map();
  sources.forEach((s) => {
    const raw = s.variables?.[variable];
    const value =
      raw === null || raw === undefined || raw === ''
        ? '(no value)'
        : String(raw);
    if (!groups.has(value)) groups.set(value, []);
    groups.get(value).push(s.id);
  });
  return [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }))
    .map(([value, ids]) => ({
      key: `${variable}=${value}`,
      label: `${value} (${ids.length})`,
      sourceIds: ids,
    }));
};

/** segments of a code that belong to a set of sources */
export const segmentsIn = (code, sourceIds) => {
  const set = new Set(sourceIds);
  return (code.text ?? []).filter((t) => set.has(t.source_id));
};

/**
 * Code co-occurrence: number of pairs of segments of code a and code b in
 * the same source whose ranges overlap or are at most `distance`
 * characters apart.
 */
export const cooccurrence = (
  codes,
  { distance = 0, sourceIds = null } = {}
) => {
  const allowed = sourceIds ? new Set(sourceIds) : null;
  const segs = codes.map((c) =>
    (c.text ?? [])
      .filter((t) => !allowed || allowed.has(t.source_id))
      .map((t) => ({
        source: t.source_id,
        start: num(t.start ?? t.start_position),
        end: num(t.end ?? t.end_position),
        text: t.text,
      }))
  );
  const n = codes.length;
  const counts = Array.from({ length: n }, () => new Array(n).fill(0));
  const pairs = Array.from({ length: n }, () =>
    Array.from({ length: n }, () => [])
  );
  for (let i = 0; i < n; i++) {
    counts[i][i] = segs[i].length;
    for (let j = i + 1; j < n; j++) {
      for (const a of segs[i]) {
        for (const b of segs[j]) {
          if (a.source !== b.source) continue;
          const gap = Math.max(a.start, b.start) - Math.min(a.end, b.end);
          if (gap <= distance) {
            counts[i][j]++;
            counts[j][i]++;
            if (pairs[i][j].length < 50) pairs[i][j].push([a, b]);
          }
        }
      }
      pairs[j][i] = pairs[i][j];
    }
  }
  return { counts, pairs };
};

/** Jaccard-like normalisation: pairs / (nA + nB − pairs) */
export const jaccard = (counts, i, j) => {
  const c = counts[i][j];
  const denom = counts[i][i] + counts[j][j] - c;
  return denom > 0 ? c / denom : 0;
};

export const toCsv = (rows) =>
  rows
    .map((r) =>
      r.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')
    )
    .join('\n');
