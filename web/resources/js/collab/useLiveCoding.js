import { reactive, toRefs } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useEcho } from './useEcho.js';
import { Codes } from '../domain/codes/Codes.js';
import { Selections } from '../Pages/coding/selections/Selections.js';
import { useCodingEditor } from '../Pages/coding/useCodingEditor.js';
import { applyCodeChange, applySelectionChange } from './liveCodingSync.js';

const state = reactive({
  /** other team members who have the same source open */
  viewers: [],
  /** set when someone changed many segments at once: {count, userId} */
  stale: null,
  /** channels joined: {source, project} */
  joined: null,
});

/**
 * Live coding on the coding page: shows who else has the source open and
 * applies their changes to codes and coded segments without a reload.
 * Requires the WebSocket server (Reverb); without it the page works as
 * before and changes appear on the next page load.
 */
export const useLiveCoding = () => {
  const { viewers, stale } = toRefs(state);
  const page = usePage();

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

  const ownId = () => page.props.auth?.user?.id;

  const setViewers = (users) => {
    const others = new Map();
    users
      .filter((u) => u && u.id !== ownId())
      .forEach((u) => others.set(u.id, u));
    state.viewers = [...others.values()];
  };

  const stop = () => {
    const echo = useEcho().echo();
    if (echo && state.joined) {
      echo.leave(`coding.${state.joined.source}`);
      echo.leave(`coding-project.${state.joined.project}`);
    }
    state.joined = null;
    state.viewers = [];
    state.stale = null;
  };

  const start = () => {
    const { projectId, source } = page.props;
    const echo = useEcho().echo();
    if (!echo || !projectId || !source?.id) return false;
    if (state.joined?.source === source.id) return true;
    stop();

    echo
      .join(`coding.${source.id}`)
      .here((users) => setViewers(users))
      .joining((user) => setViewers([...state.viewers, user]))
      .leaving((user) =>
        setViewers(state.viewers.filter((u) => u.id !== user.id))
      )
      .listen('.selection.changed', (event) => {
        if (event?.action === 'stale') {
          state.stale = {
            count: event.selection?.count ?? 0,
            userId: event.userId,
          };
          return;
        }
        applySelectionChange(stores(), event);
      });

    echo
      .private(`coding-project.${projectId}`)
      .listen('.code.changed', (event) => applyCodeChange(stores(), event));

    state.joined = { source: source.id, project: projectId };
    return true;
  };

  // the stores only initialise once per page load, so a partial Inertia
  // reload would not show the new segments
  const reload = () => {
    state.stale = null;
    window.location.reload();
  };

  const dismissStale = () => {
    state.stale = null;
  };

  return { viewers, stale, start, stop, reload, dismissStale };
};
