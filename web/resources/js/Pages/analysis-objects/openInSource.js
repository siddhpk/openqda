import { usePage } from '@inertiajs/vue3';

/**
 * Opens a source on the coding page (new tab) with a passage selected.
 */
export const openInSource = (sourceId, start, end) => {
  const project = usePage().props.projectId;
  const params = { project, source: sourceId };
  if (Number.isInteger(start) && Number.isInteger(end))
    params.focus = `${start}-${end}`;
  window.open(route('coding.show', params), '_blank', 'noopener');
};
