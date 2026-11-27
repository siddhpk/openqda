import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/vue';

const requests = [];
let status = null;
vi.mock('../utils/http/BackendRequest.js', () => ({
  request: async ({ url, type }) => {
    requests.push([type, url]);
    return { response: { status: 200, data: status } };
  },
}));

import CollaborationPage from './CollaborationPage.vue';

const tick = () => new Promise((r) => setTimeout(r, 0));
// like AuthenticatedLayout: the page sits in a <Transition>, which shows
// only one root element of the slot
const Layout = {
  template: '<div><Transition><slot name="main" /></Transition></div>',
};

describe('CollaborationPage', () => {
  beforeEach(() => {
    requests.length = 0;
    status = {
      enabled: true,
      name: 'Me',
      tailscale: { state: 'Running', ip: '100.1.2.3', user: 'me' },
      agent: {},
      projects: [{ id: 1, gid: 'g0', name: 'Interviews', role: 'primary' }],
      available: [],
      joins: [],
    };
    vi.stubGlobal('route', (n) => `/${n}`);
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  const mount = () =>
    render(CollaborationPage, {
      props: { initial: status },
      global: {
        stubs: {
          transition: false,
          AuthenticatedLayout: Layout,
          TailscaleCard: true,
          CollabProjectCard: true,
        },
      },
    });

  it('opens the invite dialog', async () => {
    const { getByText, baseElement } = mount();
    getByText('Invite people').click();
    await tick();
    await tick();
    expect(
      baseElement.querySelector('[data-test="wizard-project"]')
    ).toBeTruthy();
  });

  it('opens the join dialog', async () => {
    const { getByText, baseElement } = mount();
    getByText('Join a project').click();
    await tick();
    await tick();
    expect(
      baseElement.querySelector('[data-test="wizard-invite-input"]')
    ).toBeTruthy();
  });
});
