/**
 * Limited-memory BFGS minimiser with Armijo backtracking, used to fit the
 * ridge-penalised logistic models. Plain JavaScript, no dependencies.
 * @module
 */

const dot = (a, b) => {
  let s = 0;
  for (let i = 0; i < a.length; i++) s += a[i] * b[i];
  return s;
};

/**
 * @param f {function(Float64Array, Float64Array): number} returns loss, fills grad
 * @param x0 {Float64Array}
 */
export const lbfgs = (
  f,
  x0,
  { maxIter = 200, memory = 8, gtol = 1e-5, ftol = 1e-9 } = {}
) => {
  const n = x0.length;
  let x = Float64Array.from(x0);
  let g = new Float64Array(n);
  let fx = f(x, g);
  const S = [];
  const Y = [];
  const rho = [];
  let iter = 0;
  for (; iter < maxIter; iter++) {
    let gmax = 0;
    for (let i = 0; i < n; i++) gmax = Math.max(gmax, Math.abs(g[i]));
    if (gmax < gtol) break;

    // two-loop recursion → direction d = −H g
    const q = Float64Array.from(g);
    const alpha = new Array(S.length);
    for (let m = S.length - 1; m >= 0; m--) {
      alpha[m] = rho[m] * dot(S[m], q);
      for (let i = 0; i < n; i++) q[i] -= alpha[m] * Y[m][i];
    }
    let gamma = 1;
    if (S.length) {
      const last = S.length - 1;
      gamma = dot(S[last], Y[last]) / dot(Y[last], Y[last]);
    } else {
      gamma = 1 / Math.max(1, Math.sqrt(dot(g, g)));
    }
    for (let i = 0; i < n; i++) q[i] *= gamma;
    for (let m = 0; m < S.length; m++) {
      const beta = rho[m] * dot(Y[m], q);
      for (let i = 0; i < n; i++) q[i] += S[m][i] * (alpha[m] - beta);
    }
    const d = q.map((v) => -v);
    let slope = dot(g, d);
    if (slope >= 0) {
      // not a descent direction: reset memory, use steepest descent
      S.length = 0;
      Y.length = 0;
      rho.length = 0;
      for (let i = 0; i < n; i++) d[i] = -g[i] * gamma;
      slope = dot(g, d);
    }

    let step = 1;
    const xNew = new Float64Array(n);
    const gNew = new Float64Array(n);
    let fNew = Infinity;
    for (let ls = 0; ls < 30; ls++) {
      for (let i = 0; i < n; i++) xNew[i] = x[i] + step * d[i];
      fNew = f(xNew, gNew);
      if (fNew <= fx + 1e-4 * step * slope) break;
      step *= 0.5;
    }
    if (!(fNew < fx + 1e-12)) break; // no progress possible

    const s = new Float64Array(n);
    const yv = new Float64Array(n);
    for (let i = 0; i < n; i++) {
      s[i] = xNew[i] - x[i];
      yv[i] = gNew[i] - g[i];
    }
    const sy = dot(s, yv);
    if (sy > 1e-12) {
      S.push(s);
      Y.push(yv);
      rho.push(1 / sy);
      if (S.length > memory) {
        S.shift();
        Y.shift();
        rho.shift();
      }
    }
    const rel = Math.abs(fx - fNew) / Math.max(1, Math.abs(fx));
    x = xNew;
    g = gNew;
    fx = fNew;
    if (rel < ftol) {
      iter++;
      break;
    }
  }
  return { x, fx, iterations: iter };
};
