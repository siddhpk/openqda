import Quill from 'quill';
import { formats } from '../../../editor/EditorConfig.js';
import { editorText } from './segment.js';

/**
 * Converts stored source HTML into exactly the text (and index space) the
 * coding editor shows, by loading it into an invisible Quill instance with
 * the same formats and the same paste path as CodingEditor.vue. Needed to
 * autocode sources that are not open in the editor (batch autocoding).
 * @param html {string}
 * @return {string}
 */
export const htmlToEditorText = (html) => {
  const host = document.createElement('div');
  host.setAttribute('aria-hidden', 'true');
  host.style.cssText =
    'position:absolute;left:-100000px;top:0;width:800px;height:1px;overflow:hidden;visibility:hidden';
  const el = document.createElement('div');
  host.appendChild(el);
  document.body.appendChild(host);
  try {
    // the editor's extra attribute formats (id, title, class, notes) do not
    // change the text, so the standard formats are enough here
    const quill = new Quill(el, {
      formats,
      modules: { toolbar: false, history: false },
    });
    quill.enable(false);
    quill.clipboard.dangerouslyPasteHTML(html ?? '');
    return editorText(quill);
  } finally {
    host.remove();
  }
};
