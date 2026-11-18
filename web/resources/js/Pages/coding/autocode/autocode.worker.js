import { runAutocoder } from './stats/autocoder.js';

/**
 * Web worker wrapper so that the statistics never block the editor.
 * Receives vectors and code ids only – never text.
 * Messages out: { type: 'progress', label, value } | { type: 'done', result } | { type: 'error', message }
 */
self.onmessage = (event) => {
  try {
    const result = runAutocoder(event.data, (label, value) =>
      self.postMessage({ type: 'progress', label, value })
    );
    self.postMessage({ type: 'done', result });
  } catch (e) {
    self.postMessage({ type: 'error', message: e?.message ?? String(e) });
  }
};
