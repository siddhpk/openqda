import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../utils/http/BackendRequest.js', () => ({
  request: vi.fn(async () => ({
    response: {
      status: 200,
      data: {
        enabled: true,
        url: '/_live',
        token: 'tok',
        project: 'gid-1',
        node: 'nself',
        role: 'primary',
        review: true,
        me: { gid: 'u-me', name: 'Me', color: '#000' },
      },
    },
  })),
}));

class FakeSocket {
  static last = null;
  static OPEN = 1;
  constructor(url) {
    this.url = url;
    this.readyState = 0;
    this.sent = [];
    this.listeners = {};
    FakeSocket.last = this;
  }
  addEventListener(type, fn) {
    (this.listeners[type] ??= []).push(fn);
  }
  send(msg) {
    this.sent.push(JSON.parse(msg));
  }
  close() {
    this.readyState = 3;
  }
  emit(type, data) {
    if (type === 'open') this.readyState = 1;
    (this.listeners[type] ?? []).forEach((fn) =>
      fn(data === undefined ? {} : { data: JSON.stringify(data) })
    );
  }
}

describe('useClusterLive', () => {
  let live;
  beforeEach(async () => {
    vi.stubGlobal(
      'route',
      (name, params) => `/${name}/${params?.project ?? ''}`
    );
    vi.stubGlobal('WebSocket', FakeSocket);
    vi.resetModules();
    const mod = await import('./useClusterLive.js');
    live = mod.useClusterLive();
  });
  afterEach(() => {
    live.disconnect();
    vi.unstubAllGlobals();
  });

  it('connects with the token and resends the view after (re)connecting', async () => {
    expect(await live.connect(7)).toBe(true);
    const socket = FakeSocket.last;
    expect(socket.url).toContain('/_live?token=tok');
    live.view({ view: 'coding', doc: 'src-1' });
    socket.emit('open');
    expect(socket.sent).toContainEqual({
      t: 'view',
      view: 'coding',
      doc: 'src-1',
      edit: false,
      idle: false,
    });
    expect(live.state.connected).toBe(true);
    expect(live.state.review).toBe(true);
  });

  it('lists each other person once and dispatches change events', async () => {
    await live.connect(7);
    const socket = FakeSocket.last;
    socket.emit('open');
    socket.emit('message', { t: 'hello', session: 's1', node: 'nself' });
    socket.emit('message', {
      t: 'presence',
      users: [
        { node: 'nbob', session: 'a', user: 'u-bob', name: 'Bob', idle: true },
        { node: 'nbob', session: 'b', user: 'u-bob', name: 'Bob', idle: false },
        { node: 'nself', session: 's2', user: 'u-me', name: 'Me' },
        { node: 'ncarol', session: 'c', user: 'u-carol', name: 'Carol' },
      ],
    });
    expect(live.people.value.map((p) => [p.name, p.idle ?? false])).toEqual([
      ['Bob', false],
      ['Carol', false],
    ]);
    const seen = [];
    const off = live.onEvents((events) => seen.push(...events));
    socket.emit('message', {
      t: 'ev',
      events: [{ tbl: 'codes', id: 'c1', kind: 'upsert' }],
    });
    off();
    socket.emit('message', {
      t: 'ev',
      events: [{ tbl: 'codes', id: 'c2', kind: 'upsert' }],
    });
    expect(seen.map((e) => e.id)).toEqual(['c1']);
  });

  it('routes document updates to the subscribers of that document', async () => {
    await live.connect(7);
    const socket = FakeSocket.last;
    socket.emit('open');
    const got = [];
    const off = live.subscribe('notes:g1:content', 'doc-token', (m) =>
      got.push(m)
    );
    expect(socket.sent).toContainEqual({
      t: 'sub',
      doc: 'notes:g1:content',
      token: 'doc-token',
    });
    live.sendUpdate('notes:g1:content', new Uint8Array([1, 2, 3]));
    expect(socket.sent.at(-1)).toEqual({
      t: 'y',
      doc: 'notes:g1:content',
      u: 'AQID',
    });
    socket.emit('message', {
      t: 'y',
      doc: 'notes:g1:content',
      u: 'AQID',
      from: 'nbob:x',
    });
    socket.emit('message', { t: 'y', doc: 'notes:other:content', u: 'AQID' });
    socket.emit('message', {
      t: 'ev',
      events: [
        { kind: 'yupd', doc: 'notes:g1:content', gen: 'g', u: ['AQ=='] },
      ],
    });
    expect(got.map((m) => m.t)).toEqual(['y', 'yupd']);
    off();
    expect(socket.sent.at(-1)).toEqual({ t: 'unsub', doc: 'notes:g1:content' });
  });
});
