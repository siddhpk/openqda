import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Codes } from '../codes/Codes.js';
import { Selections } from '../../Pages/coding/selections/Selections.js';
import { useCodingEditor } from '../../Pages/coding/useCodingEditor.js';
import { useClusterLive } from './useClusterLive.js';

/**
 * Coding view of a collaborative project: codings and code changes from
 * the other laptops appear without reloading. The in-place updates reuse
 * the apply functions of the Live Team Coding module when it is installed;
 * without it, the view offers a reload.
 * @module
 */

const syncModules = import.meta.glob('../../collab/liveCodingSync.js');

const toSelection = (row) => ({
  id: row.id,
  code_id: row.code_id,
  start: row.start_position,
  end: row.end_position,
  creating_user_id: row.creating_user_id,
  origin: row.origin,
});

const toCode = (row) => ({
  id: row.id,
  name: row.name,
  color: row.color,
  description: row.description,
  codebook: row.codebook_id,
  parent_id: row.parent_id,
  kind: row.kind,
});

export const useClusterCoding = () => {
  const page = usePage();
  const live = useClusterLive();
  const stale = ref(false);
  let off = null;
  let apply = null;

  const stores = () => {
    const { projectId, source } = page.props;
    const key = source?.id ? `${projectId}-${source.id}` : `${projectId}`;
    const editor = useCodingEditor();
    return {
      codes: Codes.by(key),
      selections: Selections.by(key),
      getText: (index, length) => editor.instance()?.getText(index, length),
    };
  };

  const handle = (events) => {
    const sourceId = page.props.source?.id;
    for (const e of events) {
      if (e.tbl === 'selections') {
        if (e.kind === 'upsert' && e.row?.source_id !== sourceId) continue;
        const selection =
          e.kind === 'delete' ? { id: e.id } : toSelection(e.row);
        const event = {
          action: e.kind === 'delete' ? 'deleted' : 'created',
          selection,
        };
        if (!apply) {
          stale.value = true;
          continue;
        }
        const ctx = stores();
        if (e.kind !== 'delete' && ctx.selections.entries[selection.id])
          event.action = 'updated';
        apply.applySelectionChange(ctx, event);
      } else if (e.tbl === 'codes') {
        if (!apply) {
          stale.value = true;
          continue;
        }
        const code = e.kind === 'delete' ? { id: e.id } : toCode(e.row);
        apply.applyCodeChange(stores(), {
          action: e.kind === 'delete' ? 'deleted' : 'updated',
          code,
        }) ||
          (e.kind !== 'delete' &&
            apply.applyCodeChange(stores(), { action: 'created', code }));
      } else if (['sources', 'codebooks'].includes(e.tbl)) {
        stale.value = true;
      }
    }
  };

  const start = async () => {
    stop();
    const loader = syncModules['../../collab/liveCodingSync.js'];
    apply = loader ? await loader() : null;
    off = live.onEvents(handle);
    live.view({ view: 'coding', doc: page.props.source?.id ?? '' });
  };

  const stop = () => {
    if (off) off();
    off = null;
  };

  const reload = () => window.location.reload();

  return { stale, start, stop, reload };
};
