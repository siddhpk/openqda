<template>
  <AuthenticatedLayout
    :menu="false"
    :showFooter="false"
    title="Sharing & autocoding"
  >
    <template #main>
      <div class="max-w-2xl mx-auto p-4 space-y-6 text-sm">
        <!-- active sharing -->
        <section
          v-if="active"
          class="rounded-lg border-2 border-green-600 p-5 space-y-4"
          data-test="sharing-active"
        >
          <div class="flex items-center gap-2">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-600" />
            <h2 class="text-base font-semibold">
              {{
                s.mode === 'internet'
                  ? 'Sharing with a link'
                  : 'Sharing on this network'
              }}
            </h2>
          </div>
          <p>
            Send both to your colleagues. They enter the code, then sign in with
            their own OpenQDA account (or create one) and see the projects you
            invite them to.
          </p>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 items-center">
            <dt class="font-medium">Link</dt>
            <dd class="font-mono text-base break-all" data-test="share-url">
              {{ s.url }}
            </dd>
            <dt class="font-medium">Access code</dt>
            <dd
              class="font-mono text-2xl tracking-widest"
              data-test="share-code"
            >
              {{ s.code }}
            </dd>
          </dl>
          <div class="flex flex-wrap gap-2">
            <Button @click="copyInvite">{{
              copied ? 'Copied' : 'Copy invitation'
            }}</Button>
            <Button variant="outline" @click="call('code')">New code</Button>
            <Button
              variant="outline-secondary"
              data-test="stop-sharing"
              @click="call('stop')"
              >Stop sharing</Button
            >
          </div>
          <p class="text-xs opacity-80">
            <template v-if="s.keepOn"
              >Stays on until you stop it or close OpenQDA.</template
            >
            <template v-else
              >Stops by itself at {{ time(s.expiresAt) }}.</template
            >
            <template v-if="s.mode === 'lan'">
              Only people on the same network can connect; this connection is
              not encrypted, so use it on trusted networks only.</template
            >
            <template v-if="s.failures">
              {{ s.failures }} wrong code attempt(s) so far.</template
            >
          </p>
        </section>

        <!-- not sharing -->
        <template v-else>
          <section>
            <h2 class="text-base font-semibold mb-1">Share this OpenQDA</h2>
            <p>
              Colleagues work in their own browser on the projects stored on
              this computer, as long as it is on and OpenQDA is running.
            </p>
            <p
              v-if="stoppedMessage"
              class="mt-2 text-amber-700"
              data-test="stopped-reason"
            >
              {{ stoppedMessage }}
            </p>
          </section>

          <section class="rounded-lg border p-5 space-y-3">
            <div class="flex items-center justify-between gap-2">
              <h3 class="font-semibold">Share with a link</h3>
              <span class="text-xs rounded bg-green-100 text-green-800 px-2"
                >Recommended</span
              >
            </div>
            <p>
              Works from anywhere, including the same office. The connection is
              encrypted end to end through Tailscale Funnel; Tailscale cannot
              read it.
            </p>
            <div v-if="!ts.installed" class="space-y-1">
              <p>
                One-time setup: install the free Tailscale app on this computer
                and sign in. Your colleagues do not need it.
              </p>
              <a
                class="underline"
                href="https://tailscale.com/download"
                target="_blank"
                rel="noopener"
                >Download Tailscale</a
              >
            </div>
            <p v-else-if="!ts.running" class="text-amber-700">
              {{ ts.message }}
            </p>
            <label class="flex items-center gap-2">
              <input v-model="keepOn" type="checkbox" class="rounded" />
              Keep sharing on until I stop it (otherwise it stops after
              {{ maxHours }} hours)
            </label>
            <div class="flex flex-wrap items-center gap-2">
              <Button
                :disabled="busy || !ts.running"
                data-test="start-internet"
                @click="start('internet')"
                >Start sharing</Button
              >
              <Button variant="ghost" :disabled="busy" @click="refresh"
                >Check again</Button
              >
            </div>
          </section>

          <section class="rounded-lg border p-5 space-y-3">
            <h3 class="font-semibold">Share on this network only</h3>
            <p>
              For a room without internet: people on the same Wi-Fi or office
              network open the address shown. Not encrypted; use trusted
              networks only. Your computer may ask once whether OpenQDA may
              accept connections; choose Allow (private networks).
            </p>
            <p v-if="data.lanAddress" class="text-xs opacity-80">
              This computer's address: {{ data.lanAddress }}
            </p>
            <Button
              variant="outline"
              :disabled="busy || !data.lanAddress"
              data-test="start-lan"
              @click="start('lan')"
              >Start sharing on this network</Button
            >
          </section>
        </template>

        <p v-if="error" class="text-red-700" role="alert" data-test="error">
          {{ error }}
          <a
            v-if="actionUrl"
            :href="actionUrl"
            target="_blank"
            rel="noopener"
            class="underline ml-1"
            >Open Tailscale</a
          >
        </p>

        <!-- autocoding (AI) -->
        <section
          v-if="ai.installed !== false"
          class="rounded-lg border p-5 space-y-3"
          data-test="ai-section"
        >
          <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold">Autocoding (AI)</h3>
            <span
              class="text-xs rounded px-2"
              :class="
                ai.enabled
                  ? 'bg-green-100 text-green-800'
                  : 'bg-gray-100 text-gray-700'
              "
              data-test="ai-state"
              >{{ ai.enabled ? 'On' : 'Off' }}</span
            >
          </div>
          <p>
            Suggests codes for sentences or paragraphs, learned from the
            passages you have already coded. It uses a small language model
            (embeddings) that runs on this computer through Ollama: nothing is
            sent to the internet.
          </p>
          <div v-if="!ai.ollama" class="space-y-1">
            <p>
              One-time setup: install the free Ollama app on this computer and
              start it.
            </p>
            <div class="flex flex-wrap items-center gap-3">
              <a
                class="underline"
                href="https://ollama.com/download"
                target="_blank"
                rel="noopener"
                >Download Ollama</a
              >
              <Button variant="ghost" :disabled="busy" @click="refresh"
                >Check again</Button
              >
            </div>
          </div>
          <template v-else>
            <label class="flex flex-col gap-1">
              <span class="font-medium">Model</span>
              <select
                v-model="aiModel"
                class="rounded border px-2 py-1 max-w-md"
                data-test="ai-model"
                :disabled="ai.enabled"
              >
                <option v-for="m in ai.models" :key="m.name" :value="m.name">
                  {{ m.name }} – {{ m.label
                  }}{{ m.installed ? ' – downloaded' : '' }}
                </option>
              </select>
            </label>
            <div class="flex flex-wrap items-center gap-2">
              <Button
                v-if="!selectedInstalled"
                variant="outline"
                :disabled="busy || pulling"
                data-test="ai-pull"
                @click="pullModel"
                >{{ pulling ? 'Downloading…' : 'Download model' }}</Button
              >
              <Button
                v-if="!ai.enabled"
                :disabled="busy || pulling || !selectedInstalled"
                data-test="ai-on"
                @click="setAi(true)"
                >Turn on autocoding</Button
              >
              <Button
                v-else
                variant="outline-secondary"
                :disabled="busy"
                data-test="ai-off"
                @click="setAi(false)"
                >Turn off autocoding</Button
              >
            </div>
            <p v-if="pulling" class="text-xs opacity-80">
              The download runs in Ollama and can take a few minutes; keep this
              page open.
            </p>
            <p v-if="ai.enabled" class="text-xs opacity-80">
              Next: open a source on the Coding page and click the chip icon.
              Each project's owner switches autocoding on once for that project.
            </p>
          </template>
        </section>

        <!-- this computer -->
        <section class="rounded-lg border p-5 space-y-3">
          <h3 class="font-semibold">This computer</h3>
          <label class="flex items-center gap-2">
            <input
              type="checkbox"
              class="rounded"
              :checked="s.autostart"
              data-test="autostart"
              @change="setAutostart($event.target.checked)"
            />
            Start OpenQDA when I sign in to this computer (useful when this
            computer stays on for others)
          </label>
          <Button
            variant="outline-secondary"
            data-test="shutdown"
            @click="shutdown"
            >Shut down OpenQDA</Button
          >
          <p v-if="shutdownMessage">{{ shutdownMessage }}</p>
        </section>
      </div>
    </template>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout.vue';
import Button from '../Components/interactive/Button.vue';

const props = defineProps({ initial: Object });

const data = ref(props.initial);
const busy = ref(false);
const error = ref(null);
const actionUrl = ref(null);
const keepOn = ref(false);
const copied = ref(false);
const shutdownMessage = ref(null);

const s = computed(() => data.value?.sharing ?? { mode: 'off' });
const ai = computed(
  () => data.value?.ai ?? { enabled: false, ollama: false, models: [] }
);
const aiModel = ref(
  props.initial?.ai?.model || props.initial?.ai?.models?.[0]?.name || ''
);
const pulling = ref(false);
const selectedInstalled = computed(
  () => !!ai.value.models?.find((m) => m.name === aiModel.value)?.installed
);
const ts = computed(() => data.value?.tailscale ?? {});
const maxHours = computed(() => data.value?.maxHours ?? 8);
const active = computed(() => s.value.mode && s.value.mode !== 'off');

const reasons = {
  expired: 'Sharing stopped after the time limit.',
  too_many_wrong_codes:
    'Sharing stopped because a wrong access code was entered too often. Start again to get a new code.',
};
const stoppedMessage = computed(() => reasons[s.value.stoppedReason] ?? null);

const time = (iso) =>
  iso
    ? new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      })
    : '';

const apply = (payload) => {
  if (payload?.sharing) {
    data.value = payload;
  }
};

const refresh = async () => {
  const { data: payload } = await axios.get(route('desktop.sharing.status'));
  apply(payload);
};

const request = async (fn) => {
  busy.value = true;
  error.value = null;
  actionUrl.value = null;
  try {
    apply((await fn()).data);
  } catch (e) {
    const payload = e.response?.data ?? {};
    apply(payload);
    error.value = payload.message ?? 'Something went wrong.';
    actionUrl.value = payload.actionUrl ?? null;
  } finally {
    busy.value = false;
  }
};

const start = (mode) =>
  request(() =>
    axios.post(route('desktop.sharing.start'), { mode, keep_on: keepOn.value })
  );
const call = (what) =>
  request(() =>
    axios.post(
      route(what === 'stop' ? 'desktop.sharing.stop' : 'desktop.sharing.code')
    )
  );
const setAutostart = (enabled) =>
  request(() => axios.post(route('desktop.autostart'), { enabled }));

const setAi = (enabled) =>
  request(() =>
    axios.post(route('desktop.ai'), { enabled, model: aiModel.value })
  );
const pullModel = async () => {
  pulling.value = true;
  try {
    await request(() =>
      axios.post(route('desktop.ai.pull'), { model: aiModel.value })
    );
  } finally {
    pulling.value = false;
  }
};

const copyInvite = async () => {
  const text = `Join my OpenQDA: ${s.value.url}\nAccess code: ${s.value.code}`;
  try {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
  } catch {
    window.prompt('Copy this invitation:', text);
  }
};

const shutdown = async () => {
  if (
    !window.confirm(
      'Shut down OpenQDA? Anyone working through your link is disconnected; their saved work stays here.'
    )
  ) {
    return;
  }
  const { data: payload } = await axios.post(route('desktop.shutdown'));
  shutdownMessage.value = payload.message;
};

// keep the page in sync (failures, expiry, changes from another tab)
let timer;
onMounted(() => {
  timer = setInterval(() => refresh().catch(() => {}), 15000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
