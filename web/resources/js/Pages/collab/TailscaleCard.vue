<template>
  <section class="rounded-lg border p-5 space-y-3" data-test="tailscale-card">
    <div class="flex items-center justify-between gap-2">
      <h3 class="font-semibold">Team network (Tailscale)</h3>
      <span
        class="inline-flex items-center gap-1 text-xs rounded px-2 py-0.5"
        :class="running ? 'bg-green-100 text-green-800' : 'bg-foreground/10'"
        data-test="tailscale-state"
      >
        <span
          class="inline-block w-2 h-2 rounded-full"
          :class="running ? 'bg-green-600' : 'bg-foreground/40'"
        />
        {{ stateLabel }}
      </span>
    </div>

    <template v-if="!agent">
      <p class="text-amber-700">
        The collaboration service of OpenQDA is not running. Restart OpenQDA.
      </p>
    </template>
    <template v-else-if="running">
      <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
        <dt class="font-medium">Network</dt>
        <dd data-test="tailnet">{{ ts.tailnet || '—' }}</dd>
        <dt class="font-medium">This computer</dt>
        <dd class="font-mono break-all">{{ ts.dns || ts.ip }}</dd>
        <template v-if="ts.user">
          <dt class="font-medium">Signed in as</dt>
          <dd>{{ ts.user }}</dd>
        </template>
        <template v-if="ts.tags?.length">
          <dt class="font-medium">Tags</dt>
          <dd>{{ ts.tags.join(', ') }}</dd>
        </template>
      </dl>
      <p class="text-xs opacity-80">
        Only OpenQDA is part of this private network, not the rest of your
        computer. Collaborators' laptops reach each other directly and
        encrypted; nothing is reachable from the internet.
      </p>
      <div class="flex flex-wrap gap-2">
        <Button
          variant="ghost"
          size="sm"
          :disabled="busy"
          @click="emit('refresh')"
          >Check again</Button
        >
        <Button
          variant="outline-secondary"
          size="sm"
          :disabled="busy"
          @click="confirmDisconnect"
          >Disconnect</Button
        >
      </div>
    </template>
    <template v-else>
      <p>
        Collaborators work on their own laptops; OpenQDA keeps the copies in
        step over a private network. Everyone signs in once with their own free
        Tailscale account (Google, Microsoft, GitHub or Apple); nobody
        administers anyone else's account. Only OpenQDA joins, not the rest of
        this computer.
      </p>
      <p v-if="authUrl || ts.auth_url" class="font-medium" data-test="auth-url">
        Finish in your browser:
        <a
          class="underline"
          :href="authUrl || ts.auth_url"
          target="_blank"
          rel="noopener"
          >open the Tailscale sign-in page</a
        >, then come back and press "Check again".
      </p>
      <div class="flex flex-wrap gap-2">
        <Button :disabled="busy" data-test="connect" @click="emit('connect')"
          >Connect to Tailscale</Button
        >
        <Button variant="ghost" :disabled="busy" @click="emit('refresh')"
          >Check again</Button
        >
      </div>
    </template>

    <div v-if="agent && (ts.started || running)" class="pt-2 border-t">
      <Button
        variant="ghost"
        size="sm"
        :disabled="busy"
        data-test="sign-out"
        @click="confirmSignOut"
        >Sign out and forget this device</Button
      >
    </div>

    <details v-if="running" class="pt-2 border-t" data-test="one-click">
      <summary class="cursor-pointer font-medium">
        Advanced: one shared team network {{ oauth ? '(set up)' : '' }}
      </summary>
      <div class="space-y-3 pt-3">
        <p>
          Not needed for sharing. Only if your team prefers one network that you
          administer: an invitation can then carry a single-use key, so the
          invited person's OpenQDA joins your network without an account. Set up
          once in the Tailscale admin console:
        </p>
        <ol class="list-decimal ps-5 space-y-1">
          <li>
            Access controls: add the rules below, so OpenQDA laptops can reach
            only each other.
          </li>
          <li>
            Settings → OAuth clients: create a client with the scope
            <em>Auth Keys (write)</em> and the tag <code>{{ tag }}</code
            >, and paste it here.
          </li>
          <li>Tag this computer, so the rules include it.</li>
        </ol>
        <div class="relative">
          <pre
            class="text-xs bg-foreground/5 rounded p-3 overflow-x-auto"
            data-test="policy"
            >{{ policy }}</pre
          >
          <Button
            class="absolute top-1 right-1"
            size="sm"
            variant="ghost"
            @click="copyPolicy"
            >{{ copied ? 'Copied' : 'Copy' }}</Button
          >
        </div>
        <form class="grid gap-2" @submit.prevent="saveOauth">
          <input
            v-model="clientId"
            placeholder="OAuth client ID"
            autocomplete="off"
            class="text-sm rounded border-input bg-surface"
          />
          <input
            v-model="clientSecret"
            placeholder="OAuth client secret (tskey-client-…)"
            type="password"
            autocomplete="off"
            class="text-sm rounded border-input bg-surface"
          />
          <div class="flex flex-wrap gap-2">
            <Button
              size="sm"
              type="submit"
              :disabled="busy || !clientId || !clientSecret"
              >Save client</Button
            >
            <Button
              v-if="oauth"
              size="sm"
              type="button"
              variant="ghost"
              @click="emit('settings', { clear_oauth: true })"
              >Remove client</Button
            >
            <Button
              size="sm"
              type="button"
              variant="outline"
              :disabled="busy"
              @click="emit('tag')"
              >Tag this computer</Button
            >
          </div>
        </form>
        <p class="text-xs opacity-80">
          The client secret is stored encrypted on this computer and used only
          to create invitation keys. Without it you can still paste a key you
          created by hand, or invite people who sign in with their own Tailscale
          account (free for up to 6 people in one network).
        </p>
      </div>
    </details>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import Button from '../../Components/interactive/Button.vue';

const props = defineProps({
  ts: { type: Object, default: () => ({}) },
  agent: Boolean,
  busy: Boolean,
  authUrl: { type: String, default: null },
  oauth: Boolean,
  tag: { type: String, default: 'tag:openqda' },
  policy: { type: String, default: '' },
});
const emit = defineEmits([
  'connect',
  'disconnect',
  'sign-out',
  'refresh',
  'settings',
  'tag',
]);

const running = computed(() => props.ts?.state === 'Running');
const stateLabel = computed(
  () =>
    ({
      Running: 'Connected',
      NeedsLogin: 'Sign-in needed',
      NeedsMachineAuth: 'Waiting for approval',
      Starting: 'Starting',
      Stopped: 'Not connected',
      Unavailable: 'Unavailable',
    })[props.ts?.state] ??
    props.ts?.state ??
    'Not connected'
);

const clientId = ref('');
const clientSecret = ref('');
const saveOauth = () => {
  emit('settings', {
    oauth_client_id: clientId.value,
    oauth_client_secret: clientSecret.value,
  });
  clientSecret.value = '';
};

const copied = ref(false);
const copyPolicy = async () => {
  await navigator.clipboard?.writeText(props.policy);
  copied.value = true;
  setTimeout(() => (copied.value = false), 1500);
};

const confirmDisconnect = () => {
  if (
    window.confirm(
      'Disconnect this computer from the team network? Collaboration pauses until you connect again; nothing is deleted.'
    )
  ) {
    emit('disconnect');
  }
};

const confirmSignOut = () => {
  if (
    window.confirm(
      'Sign out and forget this Tailscale device? Reconnecting may create a new device. Your projects are kept.'
    )
  ) {
    emit('sign-out');
  }
};
</script>
