/**
 * Per-code decision cut-offs and multi-label metrics. Scores are numbers in
 * [0, 1] (similarities mapped around their cut-off, or logistic outputs);
 * they rank units but are not calibrated probabilities.
 * @module
 */

export const THRESHOLD_GRID = Array.from(
  { length: 18 },
  (_, i) => Math.round((0.05 + i * 0.05) * 100) / 100
);

/**
 * For every code, choose the threshold that maximises F1 on held-out
 * scores. Codes with fewer than `minPositives` positives keep 0.5.
 * @param probs {Float64Array[]} held-out scores per row
 * @param Y {Uint8Array} n × C
 */
export const tuneThresholds = (
  probs,
  Y,
  C,
  { minPositives = 3, grid = THRESHOLD_GRID } = {}
) => {
  const out = new Float64Array(C).fill(0.5);
  for (let c = 0; c < C; c++) {
    let positives = 0;
    for (let i = 0; i < probs.length; i++) positives += Y[i * C + c];
    if (positives < minPositives) continue;
    let bestF1 = -1;
    for (const t of grid) {
      let tp = 0;
      let fp = 0;
      let fn = 0;
      for (let i = 0; i < probs.length; i++) {
        const pred = probs[i][c] >= t;
        const y = Y[i * C + c] === 1;
        if (pred && y) tp++;
        else if (pred) fp++;
        else if (y) fn++;
      }
      const f1 = tp ? (2 * tp) / (2 * tp + fp + fn) : 0;
      // prefer the higher threshold on ties (fewer false suggestions)
      if (f1 >= bestF1) {
        bestF1 = f1;
        out[c] = t;
      }
    }
  }
  return out;
};

/**
 * Micro/macro F1, exact-match rate and per-code precision, recall and
 * support from yes/no decisions.
 * @param pred {Uint8Array} n × C decisions
 * @param Y {Uint8Array} n × C labels
 */
export const metricsFromDecisions = (pred, Y, n, C) => {
  const tp = new Float64Array(C);
  const fp = new Float64Array(C);
  const fn = new Float64Array(C);
  let exact = 0;
  for (let i = 0; i < n; i++) {
    let allRight = true;
    for (let c = 0; c < C; c++) {
      const p = pred[i * C + c] === 1;
      const y = Y[i * C + c] === 1;
      if (p && y) tp[c]++;
      else if (p) {
        fp[c]++;
        allRight = false;
      } else if (y) {
        fn[c]++;
        allRight = false;
      }
    }
    if (allRight) exact++;
  }
  let TP = 0;
  let FP = 0;
  let FN = 0;
  let f1sum = 0;
  const perClass = [];
  for (let c = 0; c < C; c++) {
    TP += tp[c];
    FP += fp[c];
    FN += fn[c];
    const precision = tp[c] + fp[c] ? tp[c] / (tp[c] + fp[c]) : 0;
    const recall = tp[c] + fn[c] ? tp[c] / (tp[c] + fn[c]) : 0;
    const f1 =
      precision + recall ? (2 * precision * recall) / (precision + recall) : 0;
    f1sum += f1;
    perClass.push({
      precision,
      recall,
      f1,
      support: tp[c] + fn[c],
      suggested: tp[c] + fp[c],
    });
  }
  return {
    microF1: TP ? (2 * TP) / (2 * TP + FP + FN) : 0,
    macroF1: C ? f1sum / C : 0,
    exactMatch: n ? exact / n : 0,
    evaluated: n,
    perClass,
  };
};

/**
 * Metrics at the given cut-offs: one vector for all rows, or one per row
 * (when every fold has its own cut-offs).
 */
export const multiLabelMetrics = (probs, Y, C, thresholds) => {
  const n = probs.length;
  const pred = new Uint8Array(n * C);
  for (let i = 0; i < n; i++) {
    const t = Array.isArray(thresholds) ? thresholds[i] : thresholds;
    for (let c = 0; c < C; c++) pred[i * C + c] = probs[i][c] >= t[c] ? 1 : 0;
  }
  return metricsFromDecisions(pred, Y, n, C);
};
