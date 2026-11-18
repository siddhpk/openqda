import { shuffle } from './random.js';

/**
 * How suggestions are evaluated, so that the reported figures say what can
 * be expected on new material:
 *
 *  - "sources" (default): folds are made of whole sources. Units from one
 *    interview resemble each other, so if the same source sat on both sides
 *    of the split the figures would be too optimistic for a new source.
 *  - "units": folds of individual units, stratified by code. This answers a
 *    different question – how well the rest of already-coded sources can be
 *    coded – and is used when there are too few coded sources to group.
 *
 * Evaluation is nested: cut-offs and the penalty are chosen on an inner
 * split of each training fold, never on the fold that is scored, so the
 * reported figures are not tuned on the data they describe.
 * @module
 */

export const NONE = '__none__';

/** folds stratified by each unit's rarest class */
export const stratifiedFolds = (sets, folds, random) => {
  const freq = new Map();
  sets.forEach((s) => s.forEach((c) => freq.set(c, (freq.get(c) ?? 0) + 1)));
  const strata = new Map();
  sets.forEach((s, i) => {
    const key = [...s].sort((a, b) => freq.get(a) - freq.get(b))[0] ?? NONE;
    if (!strata.has(key)) strata.set(key, []);
    strata.get(key).push(i);
  });
  const fold = new Int32Array(sets.length);
  let offset = 0;
  for (const idx of strata.values()) {
    shuffle(idx, random);
    idx.forEach((i, j) => (fold[i] = (j + offset) % folds));
    offset += idx.length;
  }
  return fold;
};

/**
 * Assigns whole groups (sources) to folds, largest first, each to the fold
 * with the fewest units so far. Ties between equally large groups are broken
 * at random (seeded).
 * @param groups {string[]} group of every unit
 * @return {Int32Array} fold of every unit
 */
export const groupedFolds = (groups, folds, random) => {
  const members = new Map();
  groups.forEach((g, i) => {
    if (!members.has(g)) members.set(g, []);
    members.get(g).push(i);
  });
  const order = shuffle([...members.keys()], random).sort(
    (a, b) => members.get(b).length - members.get(a).length
  );
  const size = new Array(folds).fill(0);
  const fold = new Int32Array(groups.length);
  order.forEach((g) => {
    let f = 0;
    for (let i = 1; i < folds; i++) if (size[i] < size[f]) f = i;
    members.get(g).forEach((i) => (fold[i] = f));
    size[f] += members.get(g).length;
  });
  return fold;
};

const distinct = (list) => new Set(list).size;

/**
 * Outer folds for the whole training set.
 * @param input.groups {(string|null)[]} source of every unit (null = unknown)
 * @param input.sets {Set<string>[]} codes of every unit
 * @param input.mode {'sources'|'units'}
 * @return {{scheme: 'sources'|'units'|'none', k: number, fold: Int32Array|null, groups: number, warnings: string[]}}
 */
export const planValidation = ({
  groups,
  sets,
  mode = 'sources',
  folds = 5,
  minUnits = 25,
  random,
}) => {
  const n = sets.length;
  const warnings = [];
  const known = groups.every((g) => g !== null && g !== undefined);
  const nGroups = known ? distinct(groups) : 0;
  if (n < minUnits) {
    warnings.push(
      'Too few coded units to evaluate the suggestions: there are no quality figures, and default cut-offs are used.'
    );
    return { scheme: 'none', k: 0, fold: null, groups: nGroups, warnings };
  }
  if (mode === 'sources' && nGroups >= 3) {
    const k = Math.min(folds, nGroups);
    return {
      scheme: 'sources',
      k,
      fold: groupedFolds(groups, k, random),
      groups: nGroups,
      warnings,
    };
  }
  if (mode === 'sources') {
    warnings.push(
      `Only ${nGroups || 'unknown'} coded source${nGroups === 1 ? '' : 's'}: the suggestions were evaluated on held-out units of the same sources. Expect lower quality on new sources.`
    );
  }
  return {
    scheme: 'units',
    k: folds,
    fold: stratifiedFolds(sets, folds, random),
    groups: nGroups,
    warnings,
  };
};

/**
 * Inner split of a training fold: about a quarter held out, made the same
 * way as the outer folds (whole sources if possible).
 * @param rows {number[]} unit indices of the training fold
 * @return {{train: number[], val: number[]}}
 */
export const innerSplit = (rows, groups, sets, scheme, random) => {
  const subGroups = rows.map((r) => groups[r]);
  const fold =
    scheme === 'sources' && distinct(subGroups) >= 2
      ? groupedFolds(subGroups, Math.min(4, distinct(subGroups)), random)
      : stratifiedFolds(
          rows.map((r) => sets[r]),
          4,
          random
        );
  const train = [];
  const val = [];
  rows.forEach((r, i) => (fold[i] === 0 ? val : train).push(r));
  return { train, val };
};
