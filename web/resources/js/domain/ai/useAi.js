import { usePage } from '@inertiajs/vue3';
import { computed, reactive, toRefs } from 'vue';
import { request } from '../../utils/http/BackendRequest.js';

/**
 * @module
 */

/** @private status per project id */
const state = reactive({
  status: {},
  loading: false,
});

const unwrap = ({ response, error }, fallback) => {
  if (error || !response || response.status >= 400) {
    throw new Error(response?.data?.message ?? error?.message ?? fallback);
  }
  return response.data;
};

/**
 * Decodes base64 packed little-endian float32 vectors.
 * @param b64 {string}
 * @return {Float32Array}
 */
export const decodeVector = (b64) => {
  const binary = atob(b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  const view = new DataView(bytes.buffer);
  const out = new Float32Array(bytes.length / 4);
  for (let i = 0; i < out.length; i++) out[i] = view.getFloat32(i * 4, true);
  return out;
};

/**
 * Client for the optional AI layer (text embeddings for autocoding).
 * When the server has AI disabled (page prop ai.enabled === false) every
 * consumer hides its controls.
 */
export const useAi = () => {
  const page = usePage();
  const projectId = page.props.projectId ?? page.props.project?.id;
  const globallyEnabled = computed(() => !!page.props.ai?.enabled);
  const { loading } = toRefs(state);
  const status = computed(() => state.status[projectId] ?? null);
  const projectEnabled = computed(
    () => globallyEnabled.value && !!status.value?.projectEnabled
  );

  const loadStatus = async (force = false) => {
    if (!globallyEnabled.value || !projectId) return null;
    if (state.status[projectId] && !force) return state.status[projectId];
    const data = unwrap(
      await request({
        url: route('ai.status', { project: projectId }),
        type: 'get',
      }),
      'Could not load AI status.'
    );
    state.status[projectId] = data;
    return data;
  };

  const setConsent = async (enabled, acknowledgeCloud = false) => {
    const data = unwrap(
      await request({
        url: route('ai.consent', { project: projectId }),
        type: 'post',
        body: { enabled, acknowledge_cloud: acknowledgeCloud },
      }),
      'Could not change the AI setting.'
    );
    state.status[projectId] = data;
    return data;
  };

  const setRedactionTerms = async (terms) => {
    const data = unwrap(
      await request({
        url: route('ai.redaction-terms', { project: projectId }),
        type: 'put',
        body: { terms },
      }),
      'Could not save the redaction list.'
    );
    state.status[projectId] = data;
    return data;
  };

  const purgeCache = async () =>
    unwrap(
      await request({
        url: route('ai.purge', { project: projectId }),
        type: 'delete',
      }),
      'Could not delete cached AI data.'
    );

  /**
   * Embeds texts in batches; reports progress.
   * @param texts {string[]}
   * @param onProgress {function(number, number):void=}
   * @return {Promise<{vectors: Float32Array[], dimensions: number, model: string, provider: string}>}
   */
  const embed = async (texts, onProgress = () => {}) => {
    const batch = 128;
    const vectors = [];
    let meta = {};
    for (let i = 0; i < texts.length; i += batch) {
      const data = unwrap(
        await request({
          url: route('ai.embed', { project: projectId }),
          type: 'post',
          body: { texts: texts.slice(i, i + batch) },
        }),
        'Embedding failed.'
      );
      meta = data;
      data.vectors.forEach((v) => vectors.push(decodeVector(v)));
      onProgress(Math.min(i + batch, texts.length), texts.length);
    }
    return {
      vectors,
      dimensions: meta.dimensions,
      model: meta.model,
      provider: meta.provider,
    };
  };

  /**
   * Source texts (HTML) and selections of all coded sources plus targets.
   */
  const corpus = async (targetSourceIds) =>
    unwrap(
      await request({
        url: route('autocode.corpus', { project: projectId }),
        type: 'post',
        body: { target_source_ids: targetSourceIds },
      }),
      'Could not load the sources.'
    );

  /**
   * Writes accepted suggestions as selections (bulk, with provenance).
   * @param items {{source_id: string, code_id: string, start: number, end: number, text: string}[]}
   */
  const acceptBulk = async (runId, items) => {
    const created = [];
    for (let i = 0; i < items.length; i += 500) {
      const data = unwrap(
        await request({
          url: route('autocode.runs.accept', {
            project: projectId,
            run: runId,
          }),
          type: 'post',
          body: { items: items.slice(i, i + 500) },
        }),
        'Could not save the accepted suggestions.'
      );
      created.push(...data.created);
    }
    return created;
  };

  /**
   * Records decisions on suggestions (accepted, modified, rejected,
   * deferred). The text is sent only so the server can check it against the
   * source; it stores positions, code ids, scores and a keyed hash.
   */
  const recordDecisions = async (runId, items) => {
    let summary = null;
    for (let i = 0; i < items.length; i += 500) {
      summary = unwrap(
        await request({
          url: route('autocode.runs.decisions', {
            project: projectId,
            run: runId,
          }),
          type: 'post',
          body: { items: items.slice(i, i + 500) },
        }),
        'Could not record the decisions on the suggestions.'
      ).summary;
    }
    return summary;
  };

  const storeRun = async ({ sourceId, params, metrics }) =>
    unwrap(
      await request({
        url: route('autocode.runs.store', { project: projectId }),
        type: 'post',
        body: { source_id: sourceId, params, metrics },
      }),
      'Could not record the autocoding run.'
    ).run;

  const updateRun = async (runId, accepted) =>
    unwrap(
      await request({
        url: route('autocode.runs.update', { project: projectId, run: runId }),
        type: 'patch',
        body: { accepted },
      }),
      'Could not update the autocoding run.'
    ).run;

  return {
    projectId,
    globallyEnabled,
    projectEnabled,
    status,
    loading,
    loadStatus,
    setConsent,
    setRedactionTerms,
    purgeCache,
    embed,
    corpus,
    acceptBulk,
    recordDecisions,
    storeRun,
    updateRun,
  };
};
