/**
 * Small seeded random number generators so that autocoding runs are
 * reproducible (same data + same seed = same model).
 * @module
 */

/**
 * mulberry32 PRNG
 * @param seed {number}
 * @return {function(): number} uniform in [0, 1)
 */
export const createRandom = (seed = 1) => {
  let a = seed >>> 0 || 1;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
};

/**
 * Standard normal samples via Box–Muller.
 * @param random {function(): number}
 */
export const createGaussian = (random) => {
  let spare = null;
  return () => {
    if (spare !== null) {
      const s = spare;
      spare = null;
      return s;
    }
    let u = 0;
    let v = 0;
    while (u === 0) u = random();
    while (v === 0) v = random();
    const mag = Math.sqrt(-2.0 * Math.log(u));
    spare = mag * Math.sin(2 * Math.PI * v);
    return mag * Math.cos(2 * Math.PI * v);
  };
};

/**
 * In-place Fisher–Yates shuffle.
 */
export const shuffle = (array, random) => {
  for (let i = array.length - 1; i > 0; i--) {
    const j = Math.floor(random() * (i + 1));
    [array[i], array[j]] = [array[j], array[i]];
  }
  return array;
};
