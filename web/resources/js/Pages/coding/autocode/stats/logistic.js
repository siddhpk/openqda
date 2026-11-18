import { lbfgs } from './lbfgs.js';

/**
 * One-vs-rest logistic regression: one independent ridge-penalised binary
 * model per code ("does this unit belong to code c?"). Codes do not compete
 * for probability mass, so a unit can score high for several overlapping
 * codes at once, and adding a code does not change the scores of the others.
 *
 * The C binary models are fitted together in one L-BFGS run for speed; as
 * their losses are independent, the optimum is the same as fitting them one
 * by one. Each code gets balanced weights (positives and negatives carry the
 * same total weight), so rare codes are not drowned out.
 *
 * Outputs are scores in [0, 1]. They are not calibrated probabilities: with
 * balanced weights and a ridge penalty they mainly rank units.
 * @module
 */

const sigmoid = (z) =>
  z >= 0 ? 1 / (1 + Math.exp(-z)) : Math.exp(z) / (1 + Math.exp(z));

/**
 * Balanced per-code weights: wPos[c] for positives, wNeg[c] for negatives.
 * @param Y {Uint8Array} n × C labels
 */
export const codeWeights = (Y, n, C, cap = 50) => {
  const wPos = new Float64Array(C);
  const wNeg = new Float64Array(C);
  for (let c = 0; c < C; c++) {
    let P = 0;
    for (let i = 0; i < n; i++) P += Y[i * C + c];
    wPos[c] = P ? Math.min(cap, n / (2 * P)) : 0;
    wNeg[c] = n - P ? Math.min(cap, n / (2 * (n - P))) : 0;
  }
  return { wPos, wNeg };
};

/**
 * Sum over codes of the weighted mean binary cross-entropy, plus λ/2‖W‖²
 * (intercepts unpenalised), and its gradient. W is C × (k + 1).
 */
export const logisticLossAndGradient = (W, X, Y, C, weights, lambda, grad) => {
  const { rows: n, cols: k } = X;
  const p = k + 1;
  const { wPos, wNeg } = weights;
  grad.fill(0);
  let loss = 0;
  const inv = 1 / (n || 1);
  for (let i = 0; i < n; i++) {
    const xi = i * k;
    for (let c = 0; c < C; c++) {
      const wc = c * p;
      let z = W[wc + k];
      for (let j = 0; j < k; j++) z += W[wc + j] * X.data[xi + j];
      const y = Y[i * C + c];
      const w = y ? wPos[c] : wNeg[c];
      if (!w) continue;
      // numerically stable log(1 + e^−|z|) form of the cross-entropy
      const l = Math.max(z, 0) - z * y + Math.log1p(Math.exp(-Math.abs(z)));
      loss += w * l * inv;
      const g = w * (sigmoid(z) - y) * inv;
      for (let j = 0; j < k; j++) grad[wc + j] += g * X.data[xi + j];
      grad[wc + k] += g;
    }
  }
  for (let c = 0; c < C; c++) {
    for (let j = 0; j < k; j++) {
      const v = W[c * p + j];
      loss += 0.5 * lambda * v * v;
      grad[c * p + j] += lambda * v;
    }
  }
  return loss;
};

/**
 * @param X {import('./linalg.js').Matrix} n × k
 * @param Y {Uint8Array} n × C labels (several 1s per row allowed)
 */
export const fitLogistic = (
  X,
  Y,
  C,
  { lambda, init = null, maxIter = 150 }
) => {
  const weights = codeWeights(Y, X.rows, C);
  const x0 = init ?? new Float64Array(C * (X.cols + 1));
  const { x, fx, iterations } = lbfgs(
    (W, grad) => logisticLossAndGradient(W, X, Y, C, weights, lambda, grad),
    x0,
    { maxIter }
  );
  return { type: 'logistic', W: x, C, k: X.cols, lambda, loss: fx, iterations };
};

/** scores in [0, 1] per row and code */
export const predictLogistic = (model, X) => {
  const { W, C, k } = model;
  const p = k + 1;
  const out = [];
  for (let i = 0; i < X.rows; i++) {
    const s = new Float64Array(C);
    for (let c = 0; c < C; c++) {
      let z = W[c * p + k];
      for (let j = 0; j < k; j++) z += W[c * p + j] * X.data[i * k + j];
      s[c] = sigmoid(z);
    }
    out.push(s);
  }
  return out;
};

/** balanced binary cross-entropy of scores against labels (model selection) */
export const logisticLoss = (scores, Y, C) => {
  const n = scores.length;
  const { wPos, wNeg } = codeWeights(Y, n, C);
  let total = 0;
  for (let i = 0; i < n; i++) {
    for (let c = 0; c < C; c++) {
      const y = Y[i * C + c];
      const s = Math.min(Math.max(scores[i][c], 1e-12), 1 - 1e-12);
      total -= (y ? wPos[c] : wNeg[c]) * (y ? Math.log(s) : Math.log(1 - s));
    }
  }
  return total / (n || 1);
};
