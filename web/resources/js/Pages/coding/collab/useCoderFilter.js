import { computed, reactive, toRefs } from 'vue';
import { usePage } from '@inertiajs/vue3';

const state = reactive({
  // 'all' | 'me' | a team member's user id
  coder: 'all',
});

/**
 * Which team member's coding the editor shows: everyone's, one's own or
 * that of one other member. Shared by the filter control and the editor.
 */
export const useCoderFilter = () => {
  const { coder } = toRefs(state);
  const ownId = usePage().props.auth?.user?.id ?? null;

  /** user id whose segments are shown, or null for everyone */
  const coderId = computed(() => {
    if (state.coder === 'all') return null;
    if (state.coder === 'me') return ownId;
    return Number(state.coder);
  });

  const isVisible = (selection) =>
    coderId.value === null ||
    Number(selection?.creating_user_id) === coderId.value;

  const setCoder = (value) => {
    state.coder = value ?? 'all';
  };

  return { coder, coderId, isVisible, setCoder };
};
