import { computed, reactive, readonly } from 'vue';
import { request } from '../../utils/http/BackendRequest.js';
import { bytesToB64 } from './bytes.js';

/**
 * Live channel of the laptop collaboration: one WebSocket per tab to this
 * computer's node agent (/_live). It carries who is online in the project
 * (here and on the other laptops), change notifications, co-editing updates
 * and cursors. Only active in collaborative projects of the laptop edition;
 * everywhere else connect() finds nothing to do.
 * @module
 */

const state = reactive({
  enabled: false,
  connected: false,
  project: null, // local project id
  gid: null, // global project id
  node: null,
  session: null,
  role: null,
  review: false,
  me: null,
  users: [],
});

let socket = null;
let token = null;
let url = null;
let retryDelay = 1000;
let retryTimer = null;
let refreshTimer = null;
let lastView = null;
const subscriptions = new Map(); // doc -> token
const eventListeners = new Set();
const docListeners = new Map(); // doc -> Set(fn)

const fetchToken = async (projectId) => {
  const { response, error } = await request({
    url: route('collab.live', { project: projectId }),
    type: 'get',
  });
  if (error || !response || response.status >= 400) return null;
  return response.data;
};

const send = (msg) => {
  if (socket && socket.readyState === WebSocket.OPEN) {
    socket.send(JSON.stringify(msg));
    return true;
  }
  return false;
};

const onMessage = (raw) => {
  let msg;
  try {
    msg = JSON.parse(raw.data);
  } catch {
    return;
  }
  switch (msg.t) {
    case 'hello':
      state.session = msg.session;
      state.node = msg.node;
      break;
    case 'presence':
      state.users = msg.users ?? [];
      break;
    case 'ev': {
      const events = msg.events ?? [];
      eventListeners.forEach((fn) => fn(events));
      events
        .filter((e) => e.kind === 'yupd')
        .forEach((e) =>
          (docListeners.get(e.doc) ?? []).forEach((fn) =>
            fn({ t: 'yupd', gen: e.gen, updates: e.u ?? [] })
          )
        );
      break;
    }
    case 'y':
    case 'aw':
      (docListeners.get(msg.doc) ?? []).forEach((fn) => fn(msg));
      break;
  }
};

const open = () => {
  clearTimeout(retryTimer);
  const scheme = location.protocol === 'https:' ? 'wss' : 'ws';
  socket = new WebSocket(
    `${scheme}://${location.host}${url}?token=${encodeURIComponent(token)}`
  );
  socket.addEventListener('open', () => {
    state.connected = true;
    retryDelay = 1000;
    if (lastView) send(lastView);
    subscriptions.forEach((t, doc) => send({ t: 'sub', doc, token: t }));
  });
  socket.addEventListener('message', onMessage);
  socket.addEventListener('close', () => {
    state.connected = false;
    state.users = [];
    socket = null;
    if (!state.enabled) return;
    // the agent restarts with the web server; tokens expire: fetch a new one
    retryTimer = setTimeout(async () => {
      const data = state.project ? await fetchToken(state.project) : null;
      if (data?.enabled) {
        token = data.token;
        open();
      } else {
        retryTimer = setTimeout(open, retryDelay);
      }
      retryDelay = Math.min(retryDelay * 2, 30000);
    }, retryDelay);
  });
};

/**
 * @param projectId {number|string} local project id
 * @return {Promise<boolean>} whether the project is collaborative
 */
const connect = async (projectId) => {
  if (!projectId) return false;
  if (state.project === projectId && state.enabled) return true;
  disconnect();
  const data = await fetchToken(projectId);
  if (!data?.enabled || typeof WebSocket === 'undefined') {
    state.enabled = false;
    return false;
  }
  Object.assign(state, {
    enabled: true,
    project: projectId,
    gid: data.project,
    node: data.node,
    role: data.role,
    review: !!data.review,
    me: data.me,
  });
  token = data.token;
  url = data.url;
  open();
  clearInterval(refreshTimer);
  refreshTimer = setInterval(
    async () => {
      const next = await fetchToken(projectId);
      if (next?.enabled) token = next.token;
    },
    10 * 60 * 1000
  );
  return true;
};

const disconnect = () => {
  state.enabled = false;
  clearTimeout(retryTimer);
  clearInterval(refreshTimer);
  if (socket) socket.close();
  socket = null;
  subscriptions.clear();
  Object.assign(state, {
    connected: false,
    project: null,
    gid: null,
    users: [],
  });
};

/**
 * Tell the others where this tab is (page, open source or document).
 */
const view = ({ view = '', doc = '', edit = false, idle = false } = {}) => {
  lastView = { t: 'view', view, doc, edit, idle };
  send(lastView);
};

const subscribe = (doc, docToken, fn) => {
  subscriptions.set(doc, docToken);
  if (!docListeners.has(doc)) docListeners.set(doc, new Set());
  docListeners.get(doc).add(fn);
  send({ t: 'sub', doc, token: docToken });
  return () => {
    docListeners.get(doc)?.delete(fn);
    if (!docListeners.get(doc)?.size) {
      docListeners.delete(doc);
      subscriptions.delete(doc);
      send({ t: 'unsub', doc });
    }
  };
};

/** @param bytes {Uint8Array} */
const sendUpdate = (doc, bytes) => send({ t: 'y', doc, u: bytesToB64(bytes) });

/** @param bytes {Uint8Array} */
const sendAwareness = (doc, bytes) =>
  send({ t: 'aw', doc, u: bytesToB64(bytes) });

const onEvents = (fn) => {
  eventListeners.add(fn);
  return () => eventListeners.delete(fn);
};

/** One entry per person (several tabs of one person count once). */
const people = computed(() => {
  const byUser = new Map();
  state.users.forEach((u) => {
    if (u.user === state.me?.gid && u.node === state.node) return;
    const known = byUser.get(u.user);
    if (!known || (known.idle && !u.idle)) byUser.set(u.user, u);
  });
  return [...byUser.values()].sort((a, b) => a.name.localeCompare(b.name));
});

export const useClusterLive = () => ({
  state: readonly(state),
  people,
  connect,
  disconnect,
  view,
  subscribe,
  sendUpdate,
  sendAwareness,
  onEvents,
});
