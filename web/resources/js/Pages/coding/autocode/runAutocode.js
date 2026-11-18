import { runAutocoder } from './stats/autocoder.js';

/**
 * Runs the pipeline in a Web Worker when available (falls back to the main
 * thread, e.g. in tests).
 * @param payload {object} see runAutocoder (vectors and code ids only)
 * @param onProgress {function(string, number):void}
 * @return {Promise<object>}
 */
export const runAutocode = (payload, onProgress = () => {}) => {
  if (typeof Worker === 'undefined') {
    return Promise.resolve().then(() => runAutocoder(payload, onProgress));
  }
  return new Promise((resolve, reject) => {
    const worker = new Worker(
      new URL('./autocode.worker.js', import.meta.url),
      {
        type: 'module',
      }
    );
    worker.onmessage = ({ data }) => {
      if (data.type === 'progress') onProgress(data.label, data.value);
      if (data.type === 'done') {
        worker.terminate();
        resolve(data.result);
      }
      if (data.type === 'error') {
        worker.terminate();
        reject(new Error(data.message));
      }
    };
    worker.onerror = (e) => {
      worker.terminate();
      reject(new Error(e.message || 'Autocoding failed.'));
    };
    worker.postMessage(payload);
  });
};
