/**
 * Minimal dense matrix helper (row-major Float64Array).
 * @module
 */

/**
 * @typedef Matrix
 * @property rows {number}
 * @property cols {number}
 * @property data {Float64Array} row-major
 */

export const matrix = (rows, cols, data = null) => ({
  rows,
  cols,
  data: data ?? new Float64Array(rows * cols),
});

/** stacks equally long vectors into a matrix */
export const stack = (vectors) => {
  const d = vectors[0]?.length ?? 0;
  const X = matrix(vectors.length, d);
  vectors.forEach((v, i) => X.data.set(v, i * d));
  return X;
};
