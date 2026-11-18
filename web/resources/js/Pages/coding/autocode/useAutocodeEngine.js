import { markRaw, reactive, toRaw } from 'vue';
import { useAi } from '../../../domain/ai/useAi.js';
import { buildCorpus } from './corpus.js';
import { htmlToEditorText } from './headlessText.js';
import { runAutocode } from './runAutocode.js';
import { NONE } from './stats/autocoder.js';

/**
 * Shared state and steps of autocoding, used by the coding-page panel
 * (one source, accepted codes appear live in the editor) and the batch page
 * (many sources, accepted codes are written in bulk).
 *
 * Source texts live in memory only and are never sent to the worker; the
 * worker receives vectors and code ids.
 */
export const useAutocodeEngine = () => {
  const ai = useAi();

  const options = reactive({
    granularity: 'sentence',
    method: 'auto', // auto | similarity | logistic
    validation: 'sources', // sources | units – how suggestions are evaluated
    multiple: true, // several codes per unit
    minExamples: 5,
    rollUp: true,
    includeCoded: false,
    thresholdMode: 'tuned', // tuned | fixed
    fixedThreshold: 0.35,
    seed: 1,
  });

  const state = reactive({
    step: 'idle', // idle | running | configure | done
    progress: 0,
    label: '',
    error: null,
    corpus: null,
    sourceNames: {},
    include: {},
    targetIds: [],
    result: null,
    items: [],
    run: null,
    accepted: 0,
    decisionErrors: 0,
  });

  const fail = (e) => {
    state.error = e?.message ?? String(e);
    state.step = state.corpus ? 'configure' : 'idle';
  };

  const prepare = async (targetIds, { textOverrides = {} } = {}) => {
    state.error = null;
    state.step = 'running';
    state.label = 'Loading sources';
    state.progress = 0.02;
    try {
      const data = await ai.corpus(targetIds);
      const locale = document.documentElement.lang || navigator.language;
      const sources = data.sources.map((s) => ({
        id: s.id,
        name: s.name,
        target: s.target,
        coded: s.coded,
        text: textOverrides[s.id] ?? htmlToEditorText(s.content),
      }));
      // plain (non-reactive) data: cheaper for thousands of units and
      // structured-cloneable for the worker
      state.corpus = markRaw(
        buildCorpus({
          sources,
          selections: data.selections,
          granularity: options.granularity,
          includeCoded: options.includeCoded,
          locale,
        })
      );
      state.sourceNames = Object.fromEntries(
        sources.map((s) => [s.id, s.name])
      );
      state.include = Object.fromEntries(
        Object.keys(state.corpus.stats.counts).map((id) => [id, true])
      );
      state.targetIds = targetIds;
      state.step = 'configure';
    } catch (e) {
      fail(e);
    }
  };

  const run = async (codes) => {
    state.error = null;
    state.step = 'running';
    state.progress = 0;
    try {
      const { train, targets } = toRaw(state.corpus);
      if (!targets.length)
        throw new Error(
          'There are no units to autocode in the selected sources.'
        );
      const include = Object.keys(state.include).filter(
        (id) => state.include[id]
      );

      const texts = [];
      const position = new Map();
      const add = (t) => {
        if (!position.has(t)) {
          position.set(t, texts.length);
          texts.push(t);
        }
        return position.get(t);
      };
      const trainIdx = train.map((u) => add(u.text));
      const targetIdx = targets.map((u) => add(u.text));

      state.label = 'Embedding texts';
      const embedding = await ai.embed(
        texts,
        (done, total) => (state.progress = 0.5 * (done / total))
      );
      const v = embedding.vectors;

      const result = await runAutocode(
        {
          train: train.map((u, i) => ({
            vector: v[trainIdx[i]],
            labels: u.labels,
            group: u.sourceId, // evaluation keeps each source on one side
          })),
          targets: targets.map((_, i) => ({ vector: v[targetIdx[i]] })),
          codes: codes.map((c) => ({
            id: c.id,
            name: c.name,
            parent_id:
              c.parent_id ??
              (typeof c.parent === 'object' ? c.parent?.id : c.parent) ??
              null,
          })),
          options: {
            method: options.method,
            validation: options.validation,
            maxCodes: options.multiple ? null : 1,
            minExamples: options.minExamples,
            rollUp: options.rollUp,
            include,
            seed: options.seed,
          },
        },
        (label, value) => {
          state.label = label;
          state.progress = 0.5 + 0.5 * value;
        }
      );

      state.result = markRaw(result);
      state.accepted = 0;
      state.items = result.predictions.map((p) => ({
        index: p.index,
        unit: targets[p.index],
        scores: p.scores,
        chosen: null, // null = follow the suggestion; array once the user changes it
        status: 'open',
        deferred: false,
      }));
      state.run = await ai.storeRun({
        sourceId: state.targetIds.length === 1 ? state.targetIds[0] : null,
        params: {
          granularity: options.granularity,
          method: options.method,
          validation: options.validation,
          multiple: options.multiple,
          minExamples: options.minExamples,
          rollUp: options.rollUp,
          includeCoded: options.includeCoded,
          seed: options.seed,
          targets: state.targetIds.length,
          include,
          embedding: { dimensions: embedding.dimensions },
        },
        metrics: {
          ...result.model,
          microF1: result.metrics?.microF1 ?? null,
          macroF1: result.metrics?.macroF1 ?? null,
          exactMatch: result.metrics?.exactMatch ?? null,
          validation: result.validation,
          perClass: result.metrics?.perClass ?? [],
          comparison: result.comparison,
          classes: result.classes.map(({ id, count, rolledUp, threshold }) => ({
            id,
            count,
            rolledUp,
            threshold,
          })),
          excluded: result.excluded.map(({ id, count }) => ({ id, count })),
          warnings: result.warnings,
        },
      });
      state.step = 'done';
    } catch (e) {
      fail(e);
    }
  };

  const threshold = (entry) =>
    options.thresholdMode === 'fixed'
      ? options.fixedThreshold
      : entry.threshold;

  /** codes suggested for an item under the current threshold settings */
  const suggested = (item) => {
    let list = item.scores
      .filter(
        (x) =>
          x.classId !== NONE && x.threshold !== null && x.score >= threshold(x)
      )
      .map((x) => x.classId);
    if (!options.multiple) list = list.slice(0, 1);
    return list;
  };

  /** codes that will be applied when the item is accepted */
  const chosen = (item) => item.chosen ?? suggested(item);

  const toggle = (item, classId) => {
    if (classId === NONE) return;
    const current = new Set(chosen(item));
    if (current.has(classId)) current.delete(classId);
    else {
      if (!options.multiple) current.clear();
      current.add(classId);
    }
    item.chosen = [...current];
  };

  const same = (a, b) =>
    a.length === b.length && [...a].sort().join() === [...b].sort().join();

  /**
   * Sends decisions to the server without blocking the work; failures are
   * counted and shown, never thrown at the researcher mid-review.
   */
  const record = (items, decisionOf) => {
    if (!state.run?.id || !items.length) return;
    const payload = items.map((item) => {
      const suggestedNow = suggested(item);
      const picked = chosen(item);
      return {
        source_id: item.unit.sourceId,
        start: item.unit.start,
        end: item.unit.end,
        text: item.unit.text,
        decision: decisionOf(item, suggestedNow, picked),
        suggested: suggestedNow,
        chosen: picked,
        scores: Object.fromEntries(
          item.scores
            .filter((x) => x.classId !== NONE)
            .map((x) => [x.classId, Math.round(x.score * 1e4) / 1e4])
        ),
      };
    });
    ai.recordDecisions(state.run.id, payload).catch(() => {
      state.decisionErrors += payload.length;
    });
  };

  /**
   * Accepts items with a writer that persists (item, codeId) pairs and
   * returns the number written.
   */
  const accept = async (items, writer) => {
    const pairs = items.flatMap((item) =>
      chosen(item).map((codeId) => ({ item, codeId }))
    );
    if (!pairs.length) return 0;
    const written = await writer(pairs);
    const done = items.filter((item) => chosen(item).length);
    record(done, (_item, suggestedNow, picked) =>
      same(suggestedNow, picked) ? 'accepted' : 'modified'
    );
    done.forEach((item) => (item.status = 'accepted'));
    state.accepted += written;
    return written;
  };

  const dismiss = (item) => {
    record([item], () => 'rejected');
    item.status = 'dismissed';
  };

  /** keeps the item open but moves it to the end of the list */
  const defer = (item) => {
    record([item], () => 'deferred');
    item.deferred = true;
  };

  const reset = () => {
    Object.assign(state, {
      step: 'idle',
      result: null,
      items: [],
      corpus: null,
      run: null,
      error: null,
      progress: 0,
      decisionErrors: 0,
    });
  };

  return {
    ai,
    options,
    state,
    prepare,
    run,
    suggested,
    chosen,
    toggle,
    accept,
    dismiss,
    defer,
    reset,
    NONE,
  };
};
