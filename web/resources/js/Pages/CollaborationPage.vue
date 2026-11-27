<template>
  <AuthenticatedLayout :menu="false" :showFooter="false" title="Collaboration">
    <template #main>
      <div
        class="max-w-2xl mx-auto p-4 space-y-6 text-sm"
        data-test="collaboration-page"
      >
        <section class="space-y-3">
          <h2 class="text-base font-semibold">Collaborate across laptops</h2>
          <p>
            Everyone works on their own laptop, also offline. Online, you see
            who is working and changes arrive within a second; after time
            offline, the copies merge by themselves.
          </p>
          <template v-if="d.enabled">
            <div class="flex flex-wrap gap-2">
              <Button data-test="open-invite" @click="openWizard('invite')"
                >Invite people</Button
              >
              <Button
                variant="outline"
                data-test="open-join"
                @click="openWizard('join')"
                >Join a project</Button
              >
            </div>
            <p class="flex items-center gap-2 text-xs opacity-80">
              <span
                class="inline-block w-2 h-2 rounded-full"
                :class="connected ? 'bg-green-600' : 'bg-foreground/40'"
              />
              {{
                connected
                  ? `Connected to Tailscale as ${d.tailscale.user || d.tailscale.dns}`
                  : 'Not connected yet: the first step of either button signs you in.'
              }}
            </p>
          </template>
          <p v-else class="text-amber-700">
            Collaboration is available in the OpenQDA laptop edition.
          </p>
        </section>

        <p v-if="error" class="text-destructive" data-test="collab-error">
          {{ error }}
        </p>

        <template v-if="d.enabled">
          <!-- joins waiting for the inviting laptop -->
          <ul
            v-if="d.joins?.length"
            class="divide-y border rounded"
            data-test="pending-joins"
          >
            <li
              v-for="j in d.joins"
              :key="j.id"
              class="p-2 flex flex-wrap items-center gap-2"
            >
              <span class="grow">
                <strong>{{ j.project_name }}</strong>
                <span
                  v-if="j.status === 'pending'"
                  class="ms-2 text-xs opacity-80"
                  >waiting for {{ j.inviter || 'the inviting laptop' }}…</span
                >
                <span v-else class="ms-2 text-xs text-destructive">{{
                  j.message || 'The invitation was refused.'
                }}</span>
              </span>
              <a
                v-if="j.status === 'pending' && j.share_link"
                class="text-xs underline"
                :href="j.share_link"
                target="_blank"
                rel="noopener"
                >accept the shared computer</a
              >
              <Button
                size="sm"
                variant="ghost"
                @click="collab.dismissJoin(j.id)"
                >{{ j.status === 'pending' ? 'Cancel' : 'Dismiss' }}</Button
              >
            </li>
          </ul>

          <CollabProjectCard
            v-for="p in d.projects"
            :key="p.id"
            :project="p"
            :busy="busy"
            @invite="openWizard('invite', p.id)"
            @member="(node, action) => collab.member(p.id, node, action)"
            @revoke="(id) => collab.revoke(p.id, id)"
            @leave="collab.leave(p.id)"
            @sync="collab.sync"
            @refresh="collab.refresh"
          />

          <details class="rounded-lg border p-4" data-test="advanced">
            <summary class="cursor-pointer font-medium">
              Connection and settings
            </summary>
            <div class="space-y-4 pt-4">
              <TailscaleCard
                :ts="d.tailscale"
                :agent="d.agent"
                :busy="busy"
                :auth-url="authUrl"
                :oauth="d.oauth"
                :tag="d.tag"
                :policy="d.policy"
                @connect="collab.connect"
                @disconnect="collab.disconnect"
                @sign-out="collab.signOut"
                @refresh="collab.refresh"
                @settings="collab.saveSettings"
                @tag="collab.tagThisComputer"
              />
              <div class="space-y-1">
                <label class="block font-medium" for="collab-name"
                  >Name shown to collaborators</label
                >
                <div class="flex gap-2">
                  <input
                    id="collab-name"
                    v-model="name"
                    class="text-sm rounded border-input bg-surface grow"
                    maxlength="80"
                  />
                  <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    @click="collab.saveSettings({ name })"
                    >Save</Button
                  >
                </div>
              </div>
            </div>
          </details>
        </template>

        <!-- inside the page's single root: the layout's <Transition> shows only one element -->
        <CollabWizard
          v-if="wizard.open"
          :show="true"
          :mode="wizard.mode"
          :project-id="wizard.projectId"
          @close="wizard.open = false"
        />
      </div>
    </template>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout.vue';
import Button from '../Components/interactive/Button.vue';
import TailscaleCard from './collab/TailscaleCard.vue';
import CollabProjectCard from './collab/CollabProjectCard.vue';
import CollabWizard from './collab/CollabWizard.vue';
import { useCollab } from '../domain/collab/useCollab.js';

const props = defineProps({ initial: { type: Object, required: true } });
const collab = useCollab(props.initial);
const { busy, error, authUrl } = collab;
const d = computed(() => collab.data.value ?? props.initial);
const connected = computed(() => d.value.tailscale?.state === 'Running');
const name = ref(props.initial.name ?? '');

// one guided dialog for inviting and joining
const wizard = reactive({ open: false, mode: 'invite', projectId: null });
const openWizard = (mode, projectId = null) => {
  collab.error.value = null;
  Object.assign(wizard, { open: true, mode, projectId });
};

// peers come and go: keep the page current while it is open
let timer = null;
onMounted(() => {
  timer = setInterval(
    () => !busy.value && !wizard.open && collab.refresh(),
    10000
  );
});
onUnmounted(() => clearInterval(timer));
</script>
