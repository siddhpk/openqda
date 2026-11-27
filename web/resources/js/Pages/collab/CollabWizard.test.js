import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive, toRefs } from 'vue';
import { render } from '@testing-library/vue';

const state = reactive({ data: null, busy: false, error: null, authUrl: null });
const calls = [];
vi.mock('../../domain/collab/useCollab.js', () => ({
  useCollab: () => ({
    ...toRefs(state),
    refresh: async () => state.data,
    connect: async () => ({ authUrl: 'https://login.tailscale.com/a/x' }),
    enable: async () => state.data,
    invite: async (id, opts) => {
      calls.push(['invite', id, opts]);
      return 'openqda-invite:' + 'a'.repeat(40);
    },
    join: async (text, move) => {
      calls.push(['join', text, move]);
      return {
        status: 'needs_share',
        project: 'g1',
        inviter: 'Alice',
        share_link: 'https://login.tailscale.com/admin/invite/s1',
      };
    },
  }),
}));

import CollabWizard from './CollabWizard.vue';

const tick = () => new Promise((r) => setTimeout(r, 0));

describe('CollabWizard', () => {
  beforeEach(() => {
    calls.length = 0;
    state.data = {
      tailscale: { state: 'Running', ip: '100.1.2.3' },
      projects: [{ id: 1, gid: 'g0', name: 'Interviews', role: 'primary' }],
      available: [],
      joins: [],
      oauth: true,
    };
    vi.stubGlobal('route', (n) => `/${n}`);
    vi.spyOn(window, 'open').mockImplementation(() => ({
      closed: false,
      location: {},
      focus() {},
      close() {},
    }));
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('invites: a pasted share link creates the invitation', async () => {
    const { getByText, baseElement } = render(CollabWizard, {
      props: { show: true, mode: 'invite', projectId: 1 },
    });
    await tick();
    getByText('Continue').click(); // project and role
    await tick();
    getByText('Open the sharing window').click();
    expect(window.open).toHaveBeenCalled();
    const input = baseElement.querySelector('[data-test="wizard-share-link"]');
    const paste = new Event('paste', { bubbles: true, cancelable: true });
    paste.clipboardData = {
      getData: () => 'https://login.tailscale.com/admin/invite/s1',
    };
    input.dispatchEvent(paste);
    await tick();
    await tick();
    expect(calls[0]).toEqual([
      'invite',
      1,
      {
        role: 'assistant',
        keyMode: 'share',
        shareLink: 'https://login.tailscale.com/admin/invite/s1',
      },
    ]);
    expect(getByText('Copy invitation')).toBeTruthy();
  });

  it('invites without a Tailscale account when one-time keys are configured', async () => {
    const { getByText } = render(CollabWizard, {
      props: { show: true, mode: 'invite', projectId: 1 },
    });
    await tick();
    getByText('Continue').click();
    await tick();
    getByText('Invite without a Tailscale account').click();
    await tick();
    await tick();
    expect(calls[0]).toEqual([
      'invite',
      1,
      {
        role: 'assistant',
        keyMode: 'auto',
        shareLink: null,
      },
    ]);
    expect(getByText('Copy invitation')).toBeTruthy();
  });
});
