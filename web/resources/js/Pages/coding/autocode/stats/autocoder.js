import { stack } from './linalg.js';
import { fitLogistic, predictLogistic, logisticLoss } from './logistic.js';
import { tuneThresholds, multiLabelMetrics } from './thresholds.js';
import { createRandom, shuffle } from './random.js';
import { NONE, planValidation, innerSplit } from './validation.js';

/**
 * Autocoder for an average local machine. Works on the full (L2-normalised)
 * embeddings – no dimensionality reduction that could discard what
 * distinguishes your codes. Every code is judged on its own, so a unit can
 * receive several (overlapping) codes. Methods:
 *
 *  - "similarity": every code is represented by the mean embedding of its
 *    coded units (its centroid); a unit's margin for a code is its cosine
 *    similarity to that centroid minus its similarity to the "no code"
 *    centroid. No training, works with a handful of examples per code.
 *  - "logistic": one ridge-penalised logistic regression per code
 *    (one-vs-rest), fitted on the embeddings. Codes do not compete, so
 *    overlapping codes are modelled as such. Better once codes have roughly
 *    15+ examples each.
 *  - "auto": both are evaluated in the same way and the one with the higher
 *    micro-F1 is used (ties: similarity).
 *
 * Evaluation (see validation.js) is nested and, by default, grouped by
 * source: cut-offs and the penalty are tuned inside each training fold, and
 * the reported precision, recall and F1 come only from folds that played no
 * part in tuning. For the suggestions themselves the model is refitted on
 * all coded units, with cut-offs tuned on the pooled held-out scores.
 *
 * Scores are numbers between 0 and 1 compared with a per-code cut-off. They
 * rank units; they are not probabilities.
 *
 * Input: vectors, code ids and source ids only – never text.
 * @module
 */

export { NONE };

export const DEFAULTS = {
  method: 'auto', // auto | similarity | logistic
  validation: 'sources', // sources | units
  folds: 4,
  maxCodes: null, // null = several codes per unit, 1 = one code per unit
  minExamples: 5,
  rollUp: true,
  lambdas: [0.1, 0.01], // inputs are unit-length vectors: λ = 1 practically never wins
  maxTrain: 4000, // more training units than this: "no code" units are sampled
  fallbackThreshold: 0.5,
  seed: 1,
};

/**
 * Maps codes to modelled classes, rolling sparse codes up into their parent.
 * @param counts {Map<string, number>} coded units per code
 * @param codes {{id: string, name: string, parent_id?: string|null}[]}
 */
export const resolveClasses = (
  counts,
  codes,
  { minExamples, rollUp, include = null }
) => {
  const byId = new Map(codes.map((c) => [c.id, c]));
  const allowed = include ? new Set(include) : null;
  const own = new Map(
    [...counts].filter(([id]) => byId.has(id) && (!allowed || allowed.has(id)))
  );
  const target = new Map([...own.keys()].map((id) => [id, id]));
  const root = (id) => {
    let cur = id;
    while (target.get(cur) !== cur) cur = target.get(cur);
    return cur;
  };
  const totals = () => {
    const t = new Map();
    for (const [id, n] of own) t.set(root(id), (t.get(root(id)) ?? 0) + n);
    return t;
  };
  if (rollUp) {
    let changed = true;
    while (changed) {
      changed = false;
      for (const [cls, n] of totals()) {
        const parent = byId.get(cls)?.parent_id;
        if (
          n >= minExamples ||
          !parent ||
          !byId.has(parent) ||
          (allowed && !allowed.has(parent))
        )
          continue;
        if (!target.has(parent)) target.set(parent, parent);
        target.set(cls, parent);
        changed = true;
      }
    }
  }
  const classOf = new Map();
  const classes = [];
  const excluded = [];
  for (const [cls, n] of totals()) {
    const name = byId.get(cls)?.name ?? cls;
    if (n >= minExamples) {
      const members = [...own.keys()].filter((id) => root(id) === cls);
      members.forEach((id) => classOf.set(id, cls));
      classes.push({
        id: cls,
        name,
        count: n,
        rolledUp: members.filter((id) => id !== cls),
      });
    } else {
      excluded.push({ id: cls, name, count: n });
    }
  }
  classes.sort((a, b) => b.count - a.count);
  return { classOf, classes, excluded };
};

/** L2-normalised copy as Float32Array */
export const normalize = (v) => {
  let s = 0;
  for (let i = 0; i < v.length; i++) s += v[i] * v[i];
  const inv = s > 0 ? 1 / Math.sqrt(s) : 0;
  return Float32Array.from(v, (x) => x * inv);
};

const dot = (a, b) => {
  let s = 0;
  for (let i = 0; i < a.length; i++) s += a[i] * b[i];
  return s;
};

const sigmoid = (z) =>
  z >= 0 ? 1 / (1 + Math.exp(-z)) : Math.exp(z) / (1 + Math.exp(z));

// ---------------------------------------------------------------------------
// similarity (centroid) method
// ---------------------------------------------------------------------------

/**
 * Normalised mean vector per class (units with several codes count for each).
 * @param rows {number[]} indices into vectors/sets
 * @return {{centroids: Float32Array[], none: Float32Array|null}}
 */
export const fitCentroids = (vectors, sets, rows, classIndex, C) => {
  const d = vectors[0].length;
  const sums = Array.from({ length: C }, () => new Float64Array(d));
  const none = new Float64Array(d);
  let noneCount = 0;
  for (const r of rows) {
    if (!sets[r].size) {
      for (let j = 0; j < d; j++) none[j] += vectors[r][j];
      noneCount++;
    }
    for (const cls of sets[r]) {
      const s = sums[classIndex.get(cls)];
      for (let j = 0; j < d; j++) s[j] += vectors[r][j];
    }
  }
  return {
    centroids: sums.map(normalize),
    none: noneCount ? normalize(none) : null,
  };
};

/** margin of a unit for every class: cos(unit, code) − cos(unit, no code) */
export const centroidScores = (model, v) => {
  const base = model.none ? dot(v, model.none) : 0;
  return Float64Array.from(model.centroids, (c) => dot(v, c) - base);
};

/**
 * Per-code cut-offs on raw similarity margins, chosen to maximise F1 on
 * held-out units (one sorted sweep per code), plus the spread of the
 * margins used to map them to a 0–1 score around the cut-off.
 * @param scores {Float64Array[]} margins per held-out unit
 * @param Y {Uint8Array} held-out labels (n × C)
 * @return {{cut: Float64Array, spread: Float64Array}|null}
 */
export const tuneCutoffs = (scores, Y, C) => {
  if (!scores.length) return null;
  const cut = new Float64Array(C);
  const spread = new Float64Array(C);
  for (let c = 0; c < C; c++) {
    const pairs = scores
      .map((s, i) => ({ s: s[c], y: Y[i * C + c] }))
      .sort((a, b) => b.s - a.s);
    const P = pairs.reduce((n, p) => n + p.y, 0);
    const mean = pairs.reduce((m, p) => m + p.s, 0) / pairs.length;
    spread[c] = Math.max(
      0.01,
      Math.sqrt(pairs.reduce((v, p) => v + (p.s - mean) ** 2, 0) / pairs.length)
    );
    if (P < 2) {
      cut[c] = Number.NaN; // decided by the caller's default
      continue;
    }
    let tp = 0;
    let best = -1;
    for (let i = 0; i < pairs.length; i++) {
      tp += pairs[i].y;
      if (i + 1 < pairs.length && pairs[i + 1].s === pairs[i].s) continue; // evaluate at the end of ties
      const f1 = (2 * tp) / (i + 1 + P);
      if (f1 > best) {
        best = f1;
        cut[c] =
          i + 1 < pairs.length ? (pairs[i].s + pairs[i + 1].s) / 2 : pairs[i].s;
      }
    }
  }
  return { cut, spread };
};

/**
 * Maps a similarity margin to a score in [0, 1] that is exactly 0.5 at the
 * tuned cut-off. A display scale, not a probability.
 */
export const cutoffScore = (margin, cut, spread) =>
  sigmoid((margin - cut) / (spread / 2));

// ---------------------------------------------------------------------------
// methods: select (tune on an inner split), fit, raw scores, cut-offs
// ---------------------------------------------------------------------------

const similarityMethod = (ctx) => {
  const { vectors, sets, classIndex, C } = ctx;
  const defaults = (model) => ({ cut: model.none ? 0.05 : 0.55, spread: 0.1 });
  const fit = (rows) => fitCentroids(vectors, sets, rows, classIndex, C);
  const raw = (model, vecs) => vecs.map((v) => centroidScores(model, v));
  const tune = (model, rawScores, Ysub) => {
    const def = defaults(model);
    const tuned = rawScores.length ? tuneCutoffs(rawScores, Ysub, C) : null;
    return {
      cut: Float64Array.from({ length: C }, (_, c) =>
        tuned && Number.isFinite(tuned.cut[c]) ? tuned.cut[c] : def.cut
      ),
      spread: Float64Array.from({ length: C }, (_, c) =>
        tuned ? tuned.spread[c] : def.spread
      ),
    };
  };
  const scores = (rawScores, tuning) =>
    rawScores.map((s) =>
      Float64Array.from(s, (x, c) =>
        cutoffScore(x, tuning.cut[c], tuning.spread[c])
      )
    );
  return {
    name: 'similarity',
    select(train, val) {
      const model = fit(train);
      return {
        hyper: null,
        tuning: tune(
          model,
          raw(
            model,
            val.map((r) => vectors[r])
          ),
          ctx.labels(val)
        ),
      };
    },
    fit: (rows) => fit(rows),
    raw,
    tune,
    scores,
    thresholds: () => new Float64Array(C).fill(0.5),
    describe: (model) => ({
      type: 'similarity',
      name: model.none
        ? 'Similarity to code examples (centroids, relative to uncoded text)'
        : 'Similarity to code examples (centroids)',
      parameters: C * ctx.dimensions,
    }),
  };
};

const logisticMethod = (ctx, opt) => {
  const { vectors, C } = ctx;
  const fit = (rows, lambda, { init = null, maxIter = 150 } = {}) =>
    fitLogistic(stack(rows.map((r) => vectors[r])), ctx.labels(rows), C, {
      lambda,
      init,
      maxIter,
    });
  const raw = (model, vecs) => predictLogistic(model, stack(vecs));
  const tune = (_model, rawScores, Ysub) => ({
    thresholds: rawScores.length
      ? tuneThresholds(rawScores, Ysub, C)
      : new Float64Array(C).fill(opt.fallbackThreshold),
  });
  return {
    name: 'logistic',
    select(train, val) {
      const Yval = ctx.labels(val);
      const Xval = val.map((r) => vectors[r]);
      let best = null;
      let init = null;
      // strongest penalty first; each fit warm-starts the next
      for (const lambda of opt.lambdas) {
        const model = fit(train, lambda, { init, maxIter: 40 });
        init = model.W;
        const s = raw(model, Xval);
        const loss = logisticLoss(s, Yval, C);
        if (!best || loss < best.loss) best = { lambda, loss, s };
      }
      return { hyper: best.lambda, tuning: tune(null, best.s, Yval) };
    },
    fit: (rows, lambda) => fit(rows, lambda ?? 0.1, { maxIter: 60 }),
    fitFinal: (rows, lambda) => fit(rows, lambda ?? 0.1, { maxIter: 120 }),
    raw,
    tune,
    scores: (rawScores) => rawScores,
    thresholds: (tuning) => tuning.thresholds,
    describe: (model) => ({
      type: 'logistic',
      name: 'Logistic regression, one model per code (ridge penalty)',
      lambda: model.lambda,
      parameters: C * (ctx.dimensions + 1),
    }),
  };
};

/** most frequent value (ties: the larger, i.e. the stronger penalty) */
const mode = (values) => {
  const counts = new Map();
  values.forEach((v) => counts.set(v, (counts.get(v) ?? 0) + 1));
  return [...counts].sort((a, b) => b[1] - a[1] || b[0] - a[0])[0]?.[0];
};

// ---------------------------------------------------------------------------
// pipeline
// ---------------------------------------------------------------------------

/**
 * @param input {object}
 * @param input.train {{vector: ArrayLike<number>, labels: string[], group?: string}[]} units, their codes ([] = no code) and source
 * @param input.targets {{vector: ArrayLike<number>}[]}
 * @param input.codes {{id: string, name: string, parent_id?: string|null}[]}
 * @param input.options {object=} see DEFAULTS; plus include (code ids)
 * @param onProgress {function(string, number):void=}
 */
export const runAutocoder = (
  { train, targets, codes, options = {} },
  onProgress = () => {}
) => {
  const opt = { ...DEFAULTS, ...options };
  const random = createRandom(opt.seed);
  const warnings = [];

  // ---- classes ------------------------------------------------------------
  onProgress('Resolving codes', 0.05);
  const counts = new Map();
  train.forEach((u) =>
    u.labels.forEach((l) => counts.set(l, (counts.get(l) ?? 0) + 1))
  );
  const { classOf, classes, excluded } = resolveClasses(counts, codes, opt);
  if (!classes.length) {
    throw new Error(
      `No code has at least ${opt.minExamples} coded units yet. Code more segments first or lower the minimum.`
    );
  }
  let units = [];
  for (const u of train) {
    const set = new Set(u.labels.map((l) => classOf.get(l)).filter(Boolean));
    if (u.labels.length && !set.size) continue; // coded only with left-out codes: neither code nor "no code"
    units.push({ vector: normalize(u.vector), set, group: u.group ?? null });
  }
  // keep memory and time bounded: sample "no code" units if there are many
  if (units.length > opt.maxTrain) {
    const coded = units.filter((u) => u.set.size);
    const uncoded = shuffle(
      units.filter((u) => !u.set.size),
      random
    );
    units = coded.concat(
      uncoded.slice(0, Math.max(0, opt.maxTrain - coded.length))
    );
  }
  const negatives = units.filter((u) => !u.set.size).length;
  if (!negatives) {
    warnings.push(
      'No uncoded ("no code") units were available from other coded sources, so the model cannot learn when a unit needs no code. Expect too many suggestions.'
    );
  }
  const C = classes.length;
  const classIndex = new Map(classes.map((c, i) => [c.id, i]));
  const n = units.length;
  const vectors = units.map((u) => u.vector);
  const sets = units.map((u) => u.set);
  const groups = units.map((u) => u.group);
  const Y = new Uint8Array(n * C);
  sets.forEach((s, i) =>
    s.forEach((cls) => (Y[i * C + classIndex.get(cls)] = 1))
  );
  const labels = (rows) => {
    const out = new Uint8Array(rows.length * C);
    rows.forEach((r, i) => out.set(Y.subarray(r * C, (r + 1) * C), i * C));
    return out;
  };
  const dimensions = vectors[0]?.length ?? 0;
  const ctx = { vectors, sets, classIndex, C, labels, dimensions };

  const methods = [];
  if (opt.method !== 'logistic') methods.push(similarityMethod(ctx));
  if (opt.method !== 'similarity') methods.push(logisticMethod(ctx, opt));

  // ---- nested evaluation ----------------------------------------------------
  const plan = planValidation({
    groups,
    sets,
    mode: opt.validation,
    folds: opt.folds,
    random,
  });
  warnings.push(...plan.warnings);
  const all = [...Array(n).keys()];
  const results = methods.map((m) => ({
    method: m,
    oofRaw: new Array(n),
    oofScores: new Array(n),
    oofThresholds: new Array(n),
    hypers: [],
    metrics: null,
  }));
  if (plan.scheme !== 'none') {
    const leaks = [];
    for (let f = 0; f < plan.k; f++) {
      const trainRows = all.filter((i) => plan.fold[i] !== f);
      const testRows = all.filter((i) => plan.fold[i] === f);
      if (!testRows.length || !trainRows.length) continue;
      if (plan.scheme === 'sources') {
        const seen = new Set(trainRows.map((r) => groups[r]));
        if (testRows.some((r) => seen.has(groups[r]))) leaks.push(f);
      }
      const inner = innerSplit(trainRows, groups, sets, plan.scheme, random);
      const testVectors = testRows.map((r) => vectors[r]);
      results.forEach((res, mi) => {
        const m = res.method;
        const { hyper, tuning } = m.select(inner.train, inner.val);
        const model = m.fit(trainRows, hyper);
        const raw = m.raw(model, testVectors);
        const scores = m.scores(raw, tuning);
        const thresholds = m.thresholds(tuning);
        testRows.forEach((r, i) => {
          res.oofRaw[r] = raw[i];
          res.oofScores[r] = scores[i];
          res.oofThresholds[r] = thresholds;
        });
        res.hypers.push(hyper);
        onProgress(
          'Evaluating on held-out ' +
            (plan.scheme === 'sources' ? 'sources' : 'units'),
          0.1 +
            0.6 * ((f * results.length + mi + 1) / (plan.k * results.length))
        );
      });
    }
    if (leaks.length)
      throw new Error('Internal error: a source was on both sides of a split.');
    const evaluated = all.filter((r) => results[0].oofScores[r]);
    const Yeval = labels(evaluated);
    results.forEach((res) => {
      res.metrics = multiLabelMetrics(
        evaluated.map((r) => res.oofScores[r]),
        Yeval,
        C,
        evaluated.map((r) => res.oofThresholds[r])
      );
    });
  }

  // ---- choose -----------------------------------------------------------------
  const f1 = (res) => res.metrics?.microF1 ?? -1;
  const chosen = results.reduce((best, res) =>
    f1(res) > f1(best) + 1e-9 ? res : best
  );
  const m = chosen.method;

  // ---- final model on all units, cut-offs from pooled held-out scores ---------
  onProgress('Fitting the final model', 0.75);
  const hyper = m.name === 'logistic' ? (mode(chosen.hypers) ?? 0.1) : null;
  const model = m.fitFinal ? m.fitFinal(all, hyper) : m.fit(all, hyper);
  let tuning;
  if (plan.scheme !== 'none') {
    const evaluated = all.filter((r) => chosen.oofRaw[r]);
    tuning = m.tune(
      model,
      evaluated.map((r) => chosen.oofRaw[r]),
      labels(evaluated)
    );
  } else {
    tuning = m.tune(model, [], new Uint8Array(0));
  }
  const thresholds = m.thresholds(tuning);

  onProgress('Scoring units', 0.85);
  const targetVectors = targets.map((t) => normalize(t.vector));
  const scores = targetVectors.length
    ? m.scores(m.raw(model, targetVectors), tuning)
    : [];

  if (chosen.metrics && chosen.metrics.macroF1 < 0.5) {
    warnings.push(
      `Held-out macro-F1 is low (${chosen.metrics.macroF1.toFixed(2)}). Suggestions will often be wrong; code more examples, or merge or sharpen codes that overlap.`
    );
  }
  chosen.metrics?.perClass.forEach((pc, c) => {
    if (pc.support < 5)
      warnings.push(
        `"${classes[c].name}" had only ${pc.support} held-out unit${pc.support === 1 ? '' : 's'}: its figures are very uncertain.`
      );
  });

  onProgress('Preparing suggestions', 0.95);
  const predictions = scores.map((s, index) => {
    const list = classes
      .map((c, i) => ({ classId: c.id, score: s[i], threshold: thresholds[i] }))
      .sort((x, y) => y.score - x.score);
    let suggested = list
      .filter((x) => x.score >= x.threshold)
      .map((x) => x.classId);
    if (opt.maxCodes) suggested = suggested.slice(0, opt.maxCodes);
    return {
      index,
      scores: list.slice(0, Math.max(3, suggested.length)),
      suggested,
    };
  });

  return {
    classes: classes.map((c, i) => ({ ...c, threshold: thresholds[i] })),
    excluded,
    predictions,
    model: {
      ...m.describe(model),
      scale:
        'score from 0 to 1, compared with a per-code cut-off; not a probability',
      dimensions,
      nTrain: n,
      negatives,
      multiCoded: sets.filter((s) => s.size > 1).length,
      nTargets: targets.length,
    },
    validation: {
      scheme: plan.scheme, // sources | units | none
      folds: plan.k,
      groups: plan.groups,
      nested: plan.scheme !== 'none',
      evaluated: chosen.metrics?.evaluated ?? 0,
    },
    metrics: chosen.metrics
      ? {
          microF1: chosen.metrics.microF1,
          macroF1: chosen.metrics.macroF1,
          exactMatch: chosen.metrics.exactMatch,
          evaluated: chosen.metrics.evaluated,
          perClass: chosen.metrics.perClass.map((pc, c) => ({
            classId: classes[c].id,
            examples: classes[c].count,
            ...pc,
          })),
        }
      : null,
    comparison: results.map((res) => ({
      model: res.method.name,
      microF1: res.metrics?.microF1 ?? null,
      macroF1: res.metrics?.macroF1 ?? null,
      chosen: res === chosen,
    })),
    warnings,
  };
};
