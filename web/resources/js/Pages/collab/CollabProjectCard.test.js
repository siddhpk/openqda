import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/vue';
import CollabProjectCard from './CollabProjectCard.vue';

// the card as the server returns it right after pressing "Collaborate"
const project = (role) => ({
  id: 1,
  gid: 'g',
  name: 'Interviews',
  role,
  founder: true,
  members: [
    {
      node: 'na',
      name: 'Alice',
      role: 'primary',
      removed: false,
      me: true,
      online: true,
      direct: false,
      last_seen: null,
      people: [],
    },
  ],
  invites: [],
  reviews: 0,
  my_pending: 0,
  ops: 3,
});

describe('CollabProjectCard', () => {
  it('shows members and an invite button to a Primary', async () => {
    const { getByText, queryByText, emitted } = render(CollabProjectCard, {
      props: { project: project('primary') },
    });
    expect(getByText('Review (0)')).toBeTruthy();
    expect(queryByText(/waiting for review/)).toBeNull();
    expect(getByText(/Alice/)).toBeTruthy();
    getByText('Invite people').click();
    await new Promise((r) => setTimeout(r));
    expect(emitted().invite).toBeTruthy();
  });

  it('tells an Assistant why there are no invitations', () => {
    const { getByText, queryByText } = render(CollabProjectCard, {
      props: { project: project('assistant') },
    });
    expect(getByText(/Only Primaries invite people/)).toBeTruthy();
    expect(getByText('My changes waiting for review (0)')).toBeTruthy();
    expect(queryByText('Invite people')).toBeNull();
  });

  it('explains when this computer is not on the team', () => {
    const { getByText } = render(CollabProjectCard, {
      props: { project: project(null) },
    });
    expect(getByText(/not on this project's team any more/)).toBeTruthy();
  });
});
