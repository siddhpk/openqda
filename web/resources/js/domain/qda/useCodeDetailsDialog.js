import { reactive, toRefs } from 'vue';

/** @private */
const state = reactive({ code: null, show: false, tab: 'details' });

/**
 * Global open/close state of the code details dialog, so any code tree item
 * or mind map node can open it while a single dialog instance is mounted.
 */
export const useCodeDetailsDialog = () => {
  const { code, show, tab } = toRefs(state);
  const open = (target, initialTab = 'details') => {
    state.code = target;
    state.tab = initialTab;
    state.show = true;
  };
  const close = () => {
    state.show = false;
    state.code = null;
  };
  return { code, show, tab, open, close };
};
