import { reactive, toRefs } from 'vue';
import { request } from '../../utils/http/BackendRequest.js';

/**
 * Client of the Collaboration page (laptop edition, person at this
 * computer): Tailscale connection, collaborative projects, invitations,
 * roles and reviews.
 * @module
 */

const state = reactive({
  data: null,
  busy: false,
  error: null,
  authUrl: null,
});

const call = async (url, type = 'post', body = undefined) => {
  state.busy = true;
  state.error = null;
  const { response, error } = await request({ url, type, body });
  state.busy = false;
  const data = response?.data ?? {};
  if (error || !response || response.status >= 400) {
    const errors = data.errors ? Object.values(data.errors).flat() : [];
    state.error =
      errors[0] ?? data.message ?? error?.message ?? 'Something went wrong.';
    if (data.projects) state.data = data;
    return null;
  }
  if (data.projects) state.data = data;
  return data;
};

export const useCollab = (initial = null) => {
  if (initial && !state.data) state.data = initial;

  const refresh = () => call(route('desktop.collab.status'), 'get');

  const connect = async () => {
    const data = await call(route('desktop.collab.connect'));
    state.authUrl = data?.authUrl ?? data?.tailscale?.auth_url ?? null;
    return data;
  };

  const disconnect = () => call(route('desktop.collab.disconnect'));
  const tagThisComputer = () => call(route('desktop.collab.tag'));
  const saveSettings = (settings) =>
    call(route('desktop.collab.settings'), 'post', settings);
  const sync = () => call(route('desktop.collab.sync'));
  const enable = (projectId) =>
    call(route('desktop.collab.enable', { project: projectId }));

  /** @return {Promise<string|null>} the invitation text */
  const invite = async (projectId, { role, keyMode, key, shareLink }) => {
    const data = await call(
      route('desktop.collab.invite', { project: projectId }),
      'post',
      {
        role,
        key_mode: keyMode,
        key: key || null,
        share_link: shareLink || null,
      }
    );
    return data?.text ?? null;
  };

  const revoke = (projectId, inviteId) =>
    call(
      route('desktop.collab.revoke', { project: projectId, invite: inviteId }),
      'delete'
    );

  const member = (projectId, node, action) =>
    call(route('desktop.collab.member', { project: projectId }), 'post', {
      node,
      action,
    });

  const leave = (projectId) =>
    call(route('desktop.collab.leave', { project: projectId }));

  /** @return {Promise<{status: string, current?: string, target?: string, message?: string}|null>} */
  const join = async (invite, move = false) => {
    const data = await call(route('desktop.collab.join'), 'post', {
      invite,
      move,
    });
    return data?.join ?? null;
  };

  const dismissJoin = (joinId) =>
    call(route('desktop.collab.dismiss', { join: joinId }), 'delete');

  const reviews = (projectId) =>
    call(route('desktop.collab.reviews', { project: projectId }), 'get');

  const decide = (projectId, ops, decision) =>
    call(route('desktop.collab.decide', { project: projectId }), 'post', {
      ops,
      decision,
    });

  const orphan = (projectId, tbl, gid, action) =>
    call(route('desktop.collab.orphan', { project: projectId }), 'post', {
      tbl,
      gid,
      action,
    });

  return {
    ...toRefs(state),
    refresh,
    connect,
    disconnect,
    tagThisComputer,
    saveSettings,
    sync,
    enable,
    invite,
    revoke,
    member,
    leave,
    join,
    dismissJoin,
    reviews,
    decide,
    orphan,
  };
};
