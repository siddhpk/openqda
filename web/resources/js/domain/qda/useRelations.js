import { usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { request } from '../../utils/http/BackendRequest.js';

/**
 * @module
 */

/** @private one list per project */
const state = reactive({ byProject: {} });

const ensureError = ({ response, error }, message) => {
  if (error || !response || response.status >= 400) {
    throw new Error(response?.data?.message ?? error?.message ?? message);
  }
};

/**
 * Relationships between codes (non-hierarchical) for the current project.
 * Initialised from the page prop "relations" on first use.
 */
export const useRelations = () => {
  const page = usePage();
  const projectId = page.props.projectId ?? page.props.project?.id;
  if (!state.byProject[projectId]) {
    state.byProject[projectId] = [...(page.props.relations ?? [])];
  }

  const relations = computed(() => state.byProject[projectId] ?? []);

  const relationsOf = (codeId) =>
    relations.value.filter((r) => r.from === codeId || r.to === codeId);

  const createRelation = async ({
    from,
    to,
    type,
    label,
    directed,
    valence,
    description,
  }) => {
    const body = { from, to, type };
    if (label) body.label = label;
    if (typeof directed === 'boolean') body.directed = directed;
    if (valence === 1 || valence === -1 || valence === 0)
      body.valence = valence;
    if (description) body.description = description;
    const res = await request({
      url: route('code-relations.store', { project: projectId }),
      type: 'post',
      body,
    });
    ensureError(res, 'Could not create the relationship.');
    const relation = res.response.data.relation;
    state.byProject[projectId].push(relation);
    return relation;
  };

  const updateRelation = async (id, values) => {
    const res = await request({
      url: route('code-relations.update', { project: projectId, relation: id }),
      type: 'patch',
      body: values,
    });
    ensureError(res, 'Could not update the relationship.');
    const updated = res.response.data.relation;
    const list = state.byProject[projectId];
    const index = list.findIndex((r) => r.id === id);
    if (index > -1) list[index] = updated;
    return updated;
  };

  const deleteRelation = async (id) => {
    const res = await request({
      url: route('code-relations.destroy', {
        project: projectId,
        relation: id,
      }),
      type: 'delete',
    });
    ensureError(res, 'Could not delete the relationship.');
    const list = state.byProject[projectId];
    const index = list.findIndex((r) => r.id === id);
    if (index > -1) list.splice(index, 1);
    return true;
  };

  /** drop relations that point to codes which no longer exist */
  const prune = (existingIds) => {
    const ids = new Set(existingIds);
    state.byProject[projectId] = state.byProject[projectId].filter(
      (r) => ids.has(r.from) && ids.has(r.to)
    );
  };

  return {
    projectId,
    relations,
    relationsOf,
    createRelation,
    updateRelation,
    deleteRelation,
    prune,
  };
};
