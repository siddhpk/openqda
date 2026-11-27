<template>
  <DialogBase
    :show="show"
    :title="title"
    :show-close-button="true"
    static
    @close="close"
  >
    <template #body>
      <div class="space-y-4 text-sm" :data-test="`wizard-${step}`">
        <!-- progress -->
        <ol class="flex gap-1" aria-label="Steps">
          <li
            v-for="(s, i) in steps"
            :key="s"
            class="h-1 grow rounded"
            :class="i <= stepIndex ? 'bg-primary' : 'bg-foreground/15'"
          />
        </ol>

        <!-- sign in (both ways) -->
        <template v-if="step === 'connect'">
          <p>
            Sign in to Tailscale with <strong>your own</strong> free account
            (Google, Microsoft, GitHub or Apple). Nobody administers anyone
            else's account, and only OpenQDA joins, not the rest of this
            computer.
          </p>
          <Button
            data-test="wizard-signin"
            :disabled="working"
            @click="signIn"
            >{{
              popupOpen ? 'Open the sign-in window again' : 'Sign in'
            }}</Button
          >
          <Waiting v-if="popupOpen"
            >Finish in the small window; this continues by itself.</Waiting
          >
        </template>

        <!-- invite: which project and role -->
        <template v-else-if="step === 'project'">
          <label class="block space-y-1">
            <span class="font-medium">Project</span>
            <select
              v-model="projectId"
              class="w-full rounded border-input bg-surface"
              data-test="wizard-project"
            >
              <option v-for="p in choices" :key="p.id" :value="p.id">
                {{ p.name }}
              </option>
            </select>
          </label>
          <fieldset class="space-y-1">
            <legend class="font-medium">They work as</legend>
            <label class="flex gap-2"
              ><input v-model="role" type="radio" value="assistant" />
              <span
                ><strong>Assistant</strong>: their changes to others' work wait
                for your review</span
              ></label
            >
            <label class="flex gap-2"
              ><input v-model="role" type="radio" value="primary" />
              <span
                ><strong>Primary</strong>: their changes merge directly, they
                can invite and review</span
              ></label
            >
          </fieldset>
          <Button
            data-test="wizard-next"
            :disabled="working || !projectId"
            @click="chooseProject"
            >Continue</Button
          >
        </template>

        <!-- invite: share this computer -->
        <template v-else-if="step === 'share'">
          <template v-if="oauth">
            <p>
              The shortest way: create a one-time key and send the invitation.
              The invited person does not need a Tailscale account.
            </p>
            <Button
              data-test="wizard-invite-key"
              :disabled="working"
              @click="createInvite('auto')"
              >Invite without a Tailscale account</Button
            >
          </template>
          <details
            class="space-y-2 rounded border p-3"
            :open="!oauth"
            data-test="wizard-own-account"
          >
            <summary class="cursor-pointer font-medium">
              They use their own Tailscale account
            </summary>
            <p>
              Let them reach this computer's OpenQDA. In the window that opens,
              press <strong>Share…</strong>, then <strong>Copy</strong> the
              link. Come back here: the link is picked up for you.
            </p>
            <Button data-test="wizard-open-share" @click="openSharePage"
              >Open the sharing window</Button
            >
            <div class="flex gap-2">
              <input
                v-model="shareLink"
                placeholder="https://login.tailscale.com/…"
                class="grow rounded border-input bg-surface text-xs"
                data-test="wizard-share-link"
                @paste="onPasteLink"
              />
              <Button variant="outline" size="sm" @click="pasteLink"
                >Paste</Button
              >
              <Button
                v-if="shareLink"
                size="sm"
                :disabled="working"
                data-test="wizard-create"
                @click="
                  useLink(shareLink) ||
                  fail('That is not a Tailscale share link.')
                "
                >Create</Button
              >
            </div>
            <Waiting v-if="popupOpen && !shareLink"
              >Waiting for the copied link…</Waiting
            >
          </details>
        </template>

        <!-- invite: send it -->
        <template v-else-if="step === 'send'">
          <p>
            Send this invitation privately. It works once, for 7 days. They
            paste it under <strong>Collaboration → Join a project</strong>.
          </p>
          <textarea
            :value="inviteText"
            readonly
            rows="4"
            class="w-full font-mono text-xs rounded border-input bg-surface"
            data-test="wizard-invite"
          ></textarea>
          <div class="flex flex-wrap gap-2">
            <Button data-test="wizard-copy" @click="copyInvite">{{
              copied ? 'Copied' : 'Copy invitation'
            }}</Button>
            <a
              class="inline-flex items-center rounded border px-3 text-sm"
              :href="mailto"
              >Send by email</a
            >
          </div>
        </template>

        <!-- join: paste the invitation -->
        <template v-else-if="step === 'paste'">
          <p>Paste the invitation you received.</p>
          <textarea
            v-model="inviteInput"
            rows="4"
            class="w-full font-mono text-xs rounded border-input bg-surface"
            placeholder="openqda-invite:…"
            data-test="wizard-invite-input"
            @paste="onPasteInvite"
          ></textarea>
          <Button
            data-test="wizard-join"
            :disabled="working || !inviteInput.trim()"
            @click="join(false)"
            >Join</Button
          >
          <div v-if="confirmMove" class="space-y-2 text-amber-800">
            <p>
              This computer is in the network
              <strong>{{ confirmMove.current }}</strong
              >; the invitation moves OpenQDA to
              <strong>{{ confirmMove.target }}</strong
              >.
            </p>
            <Button variant="destructive" size="sm" @click="join(true)"
              >Move and join</Button
            >
          </div>
        </template>

        <!-- join: accept the shared computer / wait for the inviter -->
        <template v-else-if="step === 'accept'">
          <p v-if="shareToAccept">
            Accept the computer
            <strong>{{ inviterName || 'they' }}</strong> shares with you. In the
            window, press <strong>Accept</strong>.
          </p>
          <Button
            v-if="shareToAccept"
            data-test="wizard-accept"
            @click="openAccept"
            >{{
              popupOpen ? 'Open the window again' : 'Accept the shared computer'
            }}</Button
          >
          <Waiting
            >Connecting to {{ inviterName || 'the inviting laptop' }}; the
            project arrives as soon as it is online.</Waiting
          >
          <p v-if="joinFailed" class="text-destructive">{{ joinFailed }}</p>
        </template>

        <!-- join: done -->
        <template v-else-if="step === 'done'">
          <p class="text-green-800" data-test="wizard-done">
            <strong>{{ joinedProject?.name }}</strong> is on this computer and
            stays in step with your collaborators.
          </p>
          <a
            v-if="joinedProject"
            class="inline-block rounded bg-primary text-primary-foreground px-3 py-1.5"
            :href="projectHref(joinedProject.id)"
            >Open the project</a
          >
        </template>

        <p v-if="error" class="text-destructive" data-test="wizard-error">
          {{ error }}
        </p>
      </div>
    </template>
  </DialogBase>
</template>

<script setup>
import { computed, h, onUnmounted, ref, watch } from 'vue';
import DialogBase from '../../dialogs/DialogBase.vue';
import Button from '../../Components/interactive/Button.vue';
import { useCollab } from '../../domain/collab/useCollab.js';
import {
  isShareLink,
  openPopup,
  shareLinkFromClipboard,
} from '../../domain/collab/usePopup.js';
import { Routes } from '../../routes/Routes.js';

/**
 * One dialog for the whole way: invite people ("invite") or join a project
 * ("join"). Steps on Tailscale's website open in a small window; the dialog
 * notices when they are done (signed in, link copied, share accepted) and
 * continues by itself.
 */
const props = defineProps({
  show: Boolean,
  mode: { type: String, default: 'invite' }, // invite | join
  projectId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const Waiting = (_, { slots }) =>
  h('p', { class: 'flex items-center gap-2 text-xs opacity-80' }, [
    h('span', {
      class:
        'inline-block h-3 w-3 rounded-full border-2 border-primary border-t-transparent animate-spin',
    }),
    slots.default?.(),
  ]);

const collab = useCollab();
const data = computed(() => collab.data.value ?? {});
const connected = computed(() => data.value.tailscale?.state === 'Running');
const oauth = computed(() => !!data.value.oauth);

const step = ref('connect');
const working = ref(false);
const error = ref(null);
const popupOpen = ref(false);
let popup = null;
let timer = null;

// invite
const projectId = ref(props.projectId);
const role = ref('assistant');
const shareLink = ref('');
const inviteText = ref('');
const copied = ref(false);
// join
const inviteInput = ref('');
const confirmMove = ref(null);
const shareToAccept = ref(null);
const inviterName = ref('');
const joinGid = ref(null);
const joinFailed = ref(null);

const steps = computed(() =>
  props.mode === 'invite'
    ? ['connect', 'project', 'share', 'send']
    : ['paste', 'connect', 'accept', 'done']
);
const stepIndex = computed(() => steps.value.indexOf(step.value));
const title = computed(() =>
  props.mode === 'invite' ? 'Invite people' : 'Join a project'
);
const choices = computed(() => [
  ...(data.value.projects ?? []).filter((p) => p.role === 'primary'),
  ...(data.value.available ?? []),
]);
const joinedProject = computed(() =>
  (data.value.projects ?? []).find((p) => p.gid === joinGid.value)
);
const mailto = computed(
  () =>
    `mailto:?subject=${encodeURIComponent('Join my OpenQDA project')}&body=${encodeURIComponent(
      `Open OpenQDA → Collaboration → Join a project, and paste this invitation:\n\n${inviteText.value}\n`
    )}`
);
const projectHref = (id) => Routes.project.path(id);

const closePopup = () => {
  popup?.close();
  popup = null;
  popupOpen.value = false;
};

const fail = (message) => {
  error.value = message ?? collab.error.value ?? 'Something went wrong.';
};

// ---- the flow ---------------------------------------------------------------

const start = () => {
  error.value = null;
  if (props.mode === 'invite') {
    projectId.value = props.projectId ?? choices.value[0]?.id ?? null;
    step.value = connected.value ? 'project' : 'connect';
  } else {
    step.value = 'paste';
  }
  clearInterval(timer);
  timer = setInterval(tick, 2000);
};

/** Everything that finishes outside OpenQDA is noticed here. */
const tick = async () => {
  if (!props.show) return;
  if (popup && popup.closed) popupOpen.value = false;
  await collab.refresh();
  if (step.value === 'connect' && connected.value) {
    closePopup();
    if (props.mode === 'invite') step.value = 'project';
    else join(false);
  }
  if (step.value === 'accept' && joinGid.value) {
    const pending = (data.value.joins ?? []).find(
      (j) => j.project_gid === joinGid.value
    );
    if (pending?.status === 'failed') joinFailed.value = pending.message;
    if (joinedProject.value) {
      closePopup();
      step.value = 'done';
    }
  }
};

const signIn = async () => {
  popup = popup && !popup.closed ? popup : openPopup();
  popupOpen.value = true;
  working.value = true;
  const res = await collab.connect();
  working.value = false;
  const url = res?.authUrl ?? res?.tailscale?.auth_url;
  if (url) popup.go(url);
  else if (connected.value) tick();
  else {
    closePopup();
    fail();
  }
};

const chooseProject = async () => {
  error.value = null;
  const p = choices.value.find((c) => c.id === projectId.value);
  if (p && !p.gid) {
    working.value = true;
    const ok = await collab.enable(p.id);
    working.value = false;
    if (!ok) return fail();
  }
  step.value = 'share';
};

const openSharePage = () => {
  const ip = data.value.tailscale?.ip;
  popup = openPopup();
  popupOpen.value = true;
  popup.go(
    ip
      ? `https://login.tailscale.com/admin/machines/${ip}`
      : 'https://login.tailscale.com/admin/machines'
  );
};

const useLink = (text) => {
  if (!isShareLink(text?.trim())) return false;
  shareLink.value = text.trim();
  createInvite('share');
  return true;
};

const pasteLink = async () => {
  const link = await shareLinkFromClipboard();
  if (!useLink(link))
    fail('Copy the share link in the Tailscale window first.');
};

const onPasteLink = (e) => {
  if (useLink(e.clipboardData?.getData('text'))) e.preventDefault();
};

// coming back from the Tailscale window: pick up the copied link
const onFocus = async () => {
  if (props.show && step.value === 'share' && !shareLink.value) {
    useLink(await shareLinkFromClipboard());
  }
};
window.addEventListener('focus', onFocus);

const createInvite = async (keyMode) => {
  error.value = null;
  working.value = true;
  const text = await collab.invite(projectId.value, {
    role: role.value,
    keyMode,
    shareLink: keyMode === 'share' ? shareLink.value : null,
  });
  working.value = false;
  if (!text) return fail();
  closePopup();
  inviteText.value = text;
  step.value = 'send';
};

const copyInvite = async () => {
  await navigator.clipboard?.writeText(inviteText.value);
  copied.value = true;
  setTimeout(() => (copied.value = false), 1500);
};

const onPasteInvite = (e) => {
  const text = e.clipboardData?.getData('text') ?? '';
  if (text.trim().startsWith('openqda-invite:')) {
    inviteInput.value = text.trim();
    e.preventDefault();
    join(false);
  }
};

const join = async (move) => {
  error.value = null;
  confirmMove.value = null;
  working.value = true;
  const r = await collab.join(inviteInput.value, move);
  working.value = false;
  if (!r) return fail();
  joinGid.value = r.project ?? joinGid.value;
  if (r.status === 'needs_signin') {
    step.value = 'connect';
  } else if (r.status === 'needs_confirm') {
    step.value = 'paste';
    confirmMove.value = r;
  } else {
    shareToAccept.value = r.share_link ?? null;
    inviterName.value = r.inviter ?? '';
    // one click opens the window (a window opened after waiting is blocked)
    step.value = 'accept';
  }
};

const openAccept = () => {
  popup = popup && !popup.closed ? popup : openPopup();
  popupOpen.value = true;
  popup.go(shareToAccept.value);
};

const close = () => {
  clearInterval(timer);
  closePopup();
  emit('close');
};

watch(
  () => props.show,
  (open) => (open ? start() : clearInterval(timer)),
  { immediate: true }
);
onUnmounted(() => {
  clearInterval(timer);
  window.removeEventListener('focus', onFocus);
});
</script>
