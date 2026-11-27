import { computed, ref, shallowRef } from 'vue';
import * as Y from 'yjs';
import {
  Awareness,
  applyAwarenessUpdate,
  encodeAwarenessUpdate,
  removeAwarenessStates,
} from 'y-protocols/awareness';
import { request } from '../../utils/http/BackendRequest.js';
import { useClusterLive } from './useClusterLive.js';
import { b64ToBytes, bytesToB64 } from './bytes.js';

/**
 * Live co-editing of one text (a memo, or the text of an unlocked source)
 * in a collaborative project of the laptop edition.
 *
 * The editors share a Yjs document. Updates go out twice: right away over
 * the live channel to the other open editors, and in small batches to the
 * server, which stores them as signed operations so that editors who were
 * offline merge character by character when they come back.
 *
 * A text without stored updates is seeded the same way on every laptop
 * (fixed client id 0, same content), so two people who open it for the first
 * time at once do not duplicate it.
 *
 * Exactly one editor saves the text the usual way (the save leader: the
 * smallest node and tab id among the editors); its save names the document
 * (X-OpenQDA-YDoc), so the next editor continues the same document.
 * @module
 */

const FLUSH_MS = 400;

/** The shared text of a fresh document, identical on every laptop. */
export const seedUpdate = (fill) => {
  const seed = new Y.Doc();
  seed.clientID = 0;
  fill(seed.getText('t'));
  const update = Y.encodeStateAsUpdate(seed);
  seed.destroy();
  return update;
};

/** The editor that saves: smallest "node:session" among the editors. */
export const pickLeader = (editors) =>
  editors.length ? [...editors].sort()[0] : null;

const loadDoc = async (key) => {
  const { response, error } = await request({
    url: route('collab.docs.open'),
    type: 'get',
    query: { key },
  });
  if (error || !response || response.status >= 400) return null;
  return response.data;
};

export const useCoEdit = () => {
  const live = useClusterLive();
  const active = ref(false);
  const canEdit = ref(false);
  const needsFinalize = ref(false);
  const doc = ref(null);
  const gen = ref(null);
  const ydoc = shallowRef(null);
  const awareness = shallowRef(null);
  let unsubscribe = null;
  let pending = [];
  let flushTimer = null;
  let onUpdate = null;
  let onAwareness = null;

  const flush = async () => {
    clearTimeout(flushTimer);
    flushTimer = null;
    if (!pending.length || !doc.value) return;
    const updates = pending.splice(0);
    const { response, error } = await request({
      url: route('collab.docs.append'),
      type: 'post',
      body: { doc: doc.value, gen: gen.value, updates },
    });
    if (error || !response || response.status >= 400) {
      // keep them for the next try (the server ignores nothing twice: Yjs
      // updates are idempotent)
      pending = updates.concat(pending);
    }
  };

  const queue = (bytes) => {
    pending.push(bytesToB64(bytes));
    if (!flushTimer) flushTimer = setTimeout(flush, FLUSH_MS);
  };

  /**
   * Join the shared document of a text.
   * @param key {string} "notes:<id>:content" or "sources:<id>:html"
   * @param seed {(ytext: Y.Text) => void} fills a fresh document with the current text
   * @return {Promise<boolean>} whether live co-editing is on
   */
  const start = async (key, seed) => {
    stop();
    if (!live.state.enabled) return false;
    const data = await loadDoc(key);
    if (!data?.collaborative) return false;
    doc.value = data.doc;
    gen.value = data.gen;
    canEdit.value = !!data.can_edit;
    needsFinalize.value = !!data.needs_finalize;

    const d = new Y.Doc();
    (data.updates ?? []).forEach((u) =>
      Y.applyUpdate(d, b64ToBytes(u), 'remote')
    );
    if (d.getText('t').length === 0 && !(data.updates ?? []).length) {
      const s = seedUpdate(seed);
      Y.applyUpdate(d, s, 'seed');
      if (canEdit.value) queue(s);
    }
    const aw = new Awareness(d);
    const me = live.state.me ?? {};
    aw.setLocalStateField('user', {
      name: me.name ?? '',
      color: me.color ?? '#2563eb',
    });

    onUpdate = (update, origin) => {
      if (origin === 'remote' || origin === 'seed' || !canEdit.value) return;
      live.sendUpdate(doc.value, update);
      queue(update);
    };
    d.on('update', onUpdate);
    onAwareness = ({ added, updated, removed }, origin) => {
      if (origin === 'remote') return;
      const changed = added.concat(updated, removed);
      live.sendAwareness(doc.value, encodeAwarenessUpdate(aw, changed));
    };
    aw.on('update', onAwareness);

    unsubscribe = live.subscribe(data.doc, data.token, (msg) => {
      if (msg.t === 'y') Y.applyUpdate(d, b64ToBytes(msg.u), 'remote');
      if (msg.t === 'aw') applyAwarenessUpdate(aw, b64ToBytes(msg.u), 'remote');
      if (msg.t === 'yupd' && msg.gen === gen.value) {
        msg.updates.forEach((u) => Y.applyUpdate(d, b64ToBytes(u), 'remote'));
      }
    });
    ydoc.value = d;
    awareness.value = aw;
    active.value = true;
    live.view({ view: 'editing', doc: data.doc, edit: canEdit.value });
    return true;
  };

  const stop = () => {
    if (pending.length) flush();
    if (unsubscribe) unsubscribe();
    unsubscribe = null;
    if (awareness.value) {
      removeAwarenessStates(awareness.value, [ydoc.value.clientID], 'local');
      awareness.value.off('update', onAwareness);
      awareness.value.destroy();
    }
    if (ydoc.value) {
      ydoc.value.off('update', onUpdate);
      ydoc.value.destroy();
    }
    ydoc.value = null;
    awareness.value = null;
    active.value = false;
    if (doc.value) live.view({ view: 'editing-done' });
    doc.value = null;
  };

  /** Editors of this document, here and on the other laptops. */
  const editors = computed(() => {
    const list = live.state.users
      .filter((u) => u.doc === doc.value && u.edit)
      .map((u) => `${u.node}:${u.session}`);
    if (canEdit.value && live.state.session) {
      list.push(`${live.state.node}:${live.state.session}`);
    }
    return list;
  });

  const isLeader = computed(
    () =>
      active.value &&
      canEdit.value &&
      pickLeader(editors.value) === `${live.state.node}:${live.state.session}`
  );

  /** Header for the save request of the leader. */
  const saveHeaders = () =>
    active.value && doc.value
      ? { 'X-OpenQDA-YDoc': `${doc.value}@${gen.value}` }
      : {};

  const text = () => ydoc.value?.getText('t');

  return {
    active,
    canEdit,
    needsFinalize,
    doc,
    gen,
    ydoc,
    awareness,
    editors,
    isLeader,
    start,
    stop,
    flush,
    saveHeaders,
    text,
  };
};
