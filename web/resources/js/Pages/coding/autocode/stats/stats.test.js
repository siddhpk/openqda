import { describe, expect, it } from 'vitest';
import { matrix } from './linalg.js';
import {
  codeWeights,
  logisticLossAndGradient,
  fitLogistic,
  predictLogistic,
} from './logistic.js';
import { tuneThresholds, multiLabelMetrics } from './thresholds.js';
import { createGaussian, createRandom } from './random.js';
import {
  stratifiedFolds,
  groupedFolds,
  planValidation,
  innerSplit,
} from './validation.js';
import {
  resolveClasses,
  fitCentroids,
  centroidScores,
  tuneCutoffs,
  cutoffScore,
  normalize,
  runAutocoder,
} from './autocoder.js';

const randomMatrix = (rows, cols, seed = 1) => {
  const g = createGaussian(createRandom(seed));
  return matrix(
    rows,
    cols,
    Float64Array.from({ length: rows * cols }, () => g())
  );
};

describe('one-vs-rest logistic regression', () => {
  it('has an analytic gradient that matches finite differences', () => {
    const X = randomMatrix(15, 2, 9);
    const C = 3;
    const Y = new Uint8Array(15 * C);
    for (let i = 0; i < 15; i++) {
      Y[i * C + (i % C)] = 1;
      if (i % 4 === 0) Y[i * C + ((i + 1) % C)] = 1; // doubly coded
    }
    const weights = codeWeights(Y, 15, C);
    const W = Float64Array.from({ length: C * 3 }, (_, i) => Math.sin(i));
    const grad = new Float64Array(W.length);
    const tmp = new Float64Array(W.length);
    logisticLossAndGradient(W, X, Y, C, weights, 0.1, grad);
    for (let i = 0; i < W.length; i++) {
      const a = Float64Array.from(W);
      const b = Float64Array.from(W);
      a[i] += 1e-6;
      b[i] -= 1e-6;
      const num =
        (logisticLossAndGradient(a, X, Y, C, weights, 0.1, tmp) -
          logisticLossAndGradient(b, X, Y, C, weights, 0.1, tmp)) /
        2e-6;
      expect(grad[i]).toBeCloseTo(num, 5);
    }
  });

  it('keeps coefficients finite with many more dimensions than examples', () => {
    const X = randomMatrix(20, 300, 3); // p ≫ n
    const Y = new Uint8Array(20 * 2);
    for (let i = 0; i < 20; i++) Y[i * 2 + (i % 2)] = 1;
    const model = fitLogistic(X, Y, 2, { lambda: 0.1 });
    model.W.forEach((v) => expect(Number.isFinite(v)).toBe(true));
    predictLogistic(model, X).forEach((s) =>
      s.forEach((x) => expect(x >= 0 && x <= 1).toBe(true))
    );
  });

  it('judges every code independently: adding a code leaves the others unchanged', () => {
    const X = randomMatrix(30, 5, 4);
    const two = new Uint8Array(30 * 2);
    const three = new Uint8Array(30 * 3);
    for (let i = 0; i < 30; i++) {
      const a = X.data[i * 5] > 0 ? 1 : 0;
      const b = X.data[i * 5 + 1] > 0 ? 1 : 0;
      two[i * 2] = a;
      two[i * 2 + 1] = b;
      three[i * 3] = a;
      three[i * 3 + 1] = b;
      three[i * 3 + 2] = X.data[i * 5 + 2] > 0 ? 1 : 0;
    }
    const s2 = predictLogistic(
      fitLogistic(X, two, 2, { lambda: 0.1, maxIter: 400 }),
      X
    );
    const s3 = predictLogistic(
      fitLogistic(X, three, 3, { lambda: 0.1, maxIter: 400 }),
      X
    );
    s2.forEach((s, i) => {
      expect(s[0]).toBeCloseTo(s3[i][0], 3);
      expect(s[1]).toBeCloseTo(s3[i][1], 3);
    });
    // a unit can score high for two codes at once
    const both = s3.findIndex((_, i) => two[i * 2] && two[i * 2 + 1]);
    expect(s3[both][0]).toBeGreaterThan(0.5);
    expect(s3[both][1]).toBeGreaterThan(0.5);
  });
});

describe('validation', () => {
  it('keeps every source in one fold and balances the folds', () => {
    const groups = [];
    ['s1', 's2', 's3', 's4', 's5', 's6'].forEach((g, i) => {
      for (let j = 0; j < 10 + i * 3; j++) groups.push(g);
    });
    const fold = groupedFolds(groups, 3, createRandom(1));
    const foldOf = new Map();
    groups.forEach((g, i) => {
      if (foldOf.has(g)) expect(fold[i]).toBe(foldOf.get(g));
      foldOf.set(g, fold[i]);
    });
    const sizes = [0, 0, 0];
    fold.forEach((f) => sizes[f]++);
    expect(Math.max(...sizes) - Math.min(...sizes)).toBeLessThanOrEqual(15);
  });

  it('groups by source when there are enough sources and falls back to units otherwise', () => {
    const sets = Array.from(
      { length: 40 },
      (_, i) => new Set(i % 2 ? ['a'] : [])
    );
    const many = planValidation({
      groups: sets.map((_, i) => `s${i % 4}`),
      sets,
      random: createRandom(1),
    });
    expect(many.scheme).toBe('sources');
    expect(many.k).toBe(4);
    expect(many.warnings).toHaveLength(0);
    const few = planValidation({
      groups: sets.map((_, i) => `s${i % 2}`),
      sets,
      random: createRandom(1),
    });
    expect(few.scheme).toBe('units');
    expect(few.warnings[0]).toMatch(/new sources/);
    const units = planValidation({
      groups: sets.map((_, i) => `s${i % 4}`),
      sets,
      mode: 'units',
      random: createRandom(1),
    });
    expect(units.scheme).toBe('units');
    expect(units.warnings).toHaveLength(0);
    expect(
      planValidation({
        groups: ['a'],
        sets: [new Set()],
        random: createRandom(1),
      }).scheme
    ).toBe('none');
  });

  it('makes inner splits from whole sources too', () => {
    const groups = Array.from({ length: 60 }, (_, i) => `s${i % 6}`);
    const sets = groups.map(() => new Set(['a']));
    const rows = groups.map((_, i) => i).filter((i) => groups[i] !== 's0');
    const { train, val } = innerSplit(
      rows,
      groups,
      sets,
      'sources',
      createRandom(2)
    );
    expect(train.length + val.length).toBe(rows.length);
    const trainGroups = new Set(train.map((r) => groups[r]));
    val.forEach((r) => expect(trainGroups.has(groups[r])).toBe(false));
    expect(val.some((r) => groups[r] === 's0')).toBe(false);
  });
});

describe('similarity method', () => {
  it('builds normalised centroids, including a "no code" centroid', () => {
    const vectors = [
      normalize([1, 0, 0]),
      normalize([0.9, 0.1, 0]),
      normalize([0, 1, 0]),
      normalize([0, 0, 1]),
    ];
    const sets = [
      new Set(['a']),
      new Set(['a', 'b']),
      new Set(['b']),
      new Set(),
    ];
    const model = fitCentroids(
      vectors,
      sets,
      [0, 1, 2, 3],
      new Map([
        ['a', 0],
        ['b', 1],
      ]),
      2
    );
    expect(Math.hypot(...model.centroids[0])).toBeCloseTo(1, 6);
    expect(model.none).not.toBeNull();
    const s = centroidScores(model, normalize([1, 0, 0]));
    expect(s[0]).toBeGreaterThan(s[1]);
    const s2 = centroidScores(model, normalize([0, 0, 1]));
    expect(s2[0]).toBeLessThan(0);
  });

  it('tunes cut-offs on raw margins and centres the score on them', () => {
    const scores = [0.5, 0.45, 0.4, 0.2, 0.1, -0.3].map((x) =>
      Float64Array.of(x)
    );
    const Y = Uint8Array.of(1, 1, 1, 0, 0, 0);
    const { cut, spread } = tuneCutoffs(scores, Y, 1);
    expect(cut[0]).toBeGreaterThan(0.2);
    expect(cut[0]).toBeLessThan(0.4);
    expect(cutoffScore(cut[0], cut[0], spread[0])).toBeCloseTo(0.5, 10);
    expect(cutoffScore(0.5, cut[0], spread[0])).toBeGreaterThan(0.5);
    expect(
      Number.isNaN(
        tuneCutoffs([Float64Array.of(1)], Uint8Array.of(1), 1).cut[0]
      )
    ).toBe(true);
  });
});

describe('thresholds, classes and folds', () => {
  it('tunes per-code thresholds', () => {
    const probs = [0.9, 0.8, 0.35, 0.3, 0.2, 0.1].map((p) =>
      Float64Array.of(p)
    );
    const Y = Uint8Array.of(1, 1, 1, 0, 0, 0);
    const t = tuneThresholds(probs, Y, 1);
    expect(t[0]).toBeGreaterThan(0.3);
    expect(multiLabelMetrics(probs, Y, 1, t).microF1).toBe(1);
    // per-row cut-offs (one set per fold)
    const perRow = probs.map((_, i) => Float64Array.of(i < 3 ? 0.3 : 0.95));
    const m = multiLabelMetrics(probs, Y, 1, perRow);
    expect(m.perClass[0].recall).toBe(1);
    expect(m.perClass[0].precision).toBe(1);
    expect(m.perClass[0].support).toBe(3);
  });

  it('rolls sparse codes up into their parent and excludes sparse roots', () => {
    const codes = [
      { id: 'p', name: 'Parent' },
      { id: 'a', name: 'A', parent_id: 'p' },
      { id: 'b', name: 'B', parent_id: 'p' },
      { id: 'c', name: 'C' },
      { id: 'x', name: 'Lonely' },
    ];
    const counts = new Map([
      ['a', 2],
      ['b', 3],
      ['c', 6],
      ['x', 1],
    ]);
    const { classes, excluded, classOf } = resolveClasses(counts, codes, {
      minExamples: 5,
      rollUp: true,
    });
    expect(classes.map((c) => c.id).sort()).toEqual(['c', 'p']);
    expect(classOf.get('a')).toBe('p');
    expect(excluded.map((e) => e.id)).toEqual(['x']);
  });

  it('stratifies by the rarest code', () => {
    const sets = Array.from(
      { length: 30 },
      (_, i) => new Set(i < 6 ? ['rare', 'common'] : ['common'])
    );
    const fold = stratifiedFolds(sets, 3, createRandom(1));
    const perFold = [0, 0, 0];
    for (let i = 0; i < 6; i++) perFold[fold[i]]++;
    expect(perFold).toEqual([2, 2, 2]);
  });
});

describe('autocoder', () => {
  const d = 384; // like all-MiniLM / truncated nomic embeddings
  const g = createGaussian(createRandom(21));
  const centre = () => Float32Array.from({ length: d }, () => g());
  const [A, B, N] = [centre(), centre(), centre()];
  const unit = (...parts) => {
    const v = new Float32Array(d);
    parts.forEach((p) => p.forEach((x, j) => (v[j] += x)));
    for (let j = 0; j < d; j++) v[j] += 1.2 * g();
    return v;
  };
  const codes = [
    { id: 'a', name: 'A' },
    { id: 'b', name: 'B' },
  ];
  const train = [];
  const source = (i) => `s${i % 5}`; // five interviews
  for (let i = 0; i < 25; i++)
    train.push({ vector: unit(A), labels: ['a'], group: source(i) });
  for (let i = 0; i < 25; i++)
    train.push({ vector: unit(B), labels: ['b'], group: source(i) });
  for (let i = 0; i < 20; i++)
    train.push({ vector: unit(A, B), labels: ['a', 'b'], group: source(i) });
  for (let i = 0; i < 40; i++)
    train.push({ vector: unit(N), labels: [], group: source(i) });
  const targets = [];
  const truth = [];
  [
    [[A], 'a'],
    [[B], 'b'],
    [[A, B], 'a,b'],
    [[N], ''],
  ].forEach(([parts, t]) => {
    for (let i = 0; i < 10; i++) {
      targets.push({ vector: unit(...parts) });
      truth.push(t);
    }
  });
  const accuracy = (r) =>
    r.predictions.filter(
      (p, i) => [...p.suggested].sort().join(',') === truth[i]
    ).length / truth.length;

  for (const method of ['similarity', 'logistic']) {
    it(`suggests overlapping codes with ${method}`, () => {
      const r = runAutocoder({ train, targets, codes, options: { method } });
      expect(r.model.type).toBe(method);
      expect(r.model.dimensions).toBe(d);
      expect(r.model.multiCoded).toBe(20);
      expect(r.model.scale).toMatch(/not a probability/);
      expect(r.validation).toMatchObject({
        scheme: 'sources',
        folds: 4,
        groups: 5,
        nested: true,
        evaluated: 110,
      });
      expect(r.metrics.microF1).toBeGreaterThan(0.8);
      const a = r.metrics.perClass.find((pc) => pc.classId === 'a');
      expect(a.support).toBe(45);
      expect(a.examples).toBe(45);
      expect(a.precision).toBeGreaterThan(0.7);
      expect(a.recall).toBeGreaterThan(0.7);
      expect(accuracy(r)).toBeGreaterThan(0.75);
      r.predictions.forEach((p) =>
        p.scores.forEach((x) => expect(x.score >= 0 && x.score <= 1).toBe(true))
      );
    });
  }

  it('evaluates on held-out units when sources are unknown or few', () => {
    const anonymous = train.map(({ vector, labels }) => ({ vector, labels }));
    const r = runAutocoder({ train: anonymous, targets, codes });
    expect(r.validation.scheme).toBe('units');
    expect(r.warnings.join(' ')).toMatch(/new sources/);
    const asked = runAutocoder({
      train,
      targets,
      codes,
      options: { validation: 'units' },
    });
    expect(asked.validation.scheme).toBe('units');
  });

  it('weighs both methods in auto mode and can limit to one code per unit', () => {
    const r = runAutocoder({ train, targets, codes });
    expect(r.comparison.map((c) => c.model)).toEqual([
      'similarity',
      'logistic',
    ]);
    expect(r.comparison.filter((c) => c.chosen)).toHaveLength(1);
    const single = runAutocoder({
      train,
      targets,
      codes,
      options: { maxCodes: 1 },
    });
    single.predictions.forEach((p) =>
      expect(p.suggested.length).toBeLessThanOrEqual(1)
    );
  });

  it('works with only a few examples per code (similarity)', () => {
    const few = [
      ...train.slice(0, 6),
      ...train.slice(25, 31),
      ...train.slice(70, 82),
    ];
    const r = runAutocoder({
      train: few,
      targets,
      codes,
      options: { method: 'similarity', minExamples: 3 },
    });
    expect(r.metrics).toBeNull(); // too small to evaluate
    expect(r.validation.scheme).toBe('none');
    expect(accuracy(r)).toBeGreaterThan(0.5);
  });

  it('stays fast on an average machine (thousands of units, 768 dimensions)', () => {
    const D = 768;
    const big = Array.from({ length: 2000 }, (_, i) => ({
      vector: Float32Array.from(
        { length: D },
        (_, j) => (j % 3 === i % 3 ? 1 : 0) + 0.5 * g()
      ),
      labels: i % 3 === 2 ? [] : [i % 3 === 0 ? 'a' : 'b'],
      group: `s${i % 20}`,
    }));
    const many = Array.from({ length: 3000 }, (_, i) => ({
      vector: Float32Array.from(
        { length: D },
        (_, j) => (j % 3 === i % 2 ? 1 : 0) + 0.5 * g()
      ),
    }));
    let t0 = performance.now();
    runAutocoder({
      train: big,
      targets: many,
      codes,
      options: { method: 'similarity' },
    });
    const similarityMs = performance.now() - t0;
    t0 = performance.now();
    const r = runAutocoder({
      train: big,
      targets: many,
      codes,
      options: { method: 'auto' },
    });
    const autoMs = performance.now() - t0;
    expect(r.predictions).toHaveLength(3000);
    expect(similarityMs).toBeLessThan(2000);
    expect(autoMs).toBeLessThan(20000);
  }, 60000);
});
