<template>
  <section
    class="rounded-lg border p-5 space-y-4"
    :data-test="`collab-project-${project.id}`"
  >
    <div class="flex flex-wrap items-center gap-2">
      <h3 class="font-semibold grow">{{ project.name }}</h3>
      <span
        class="text-xs rounded px-2 py-0.5"
        :class="roleClass(project.role)"
        >{{ roleLabel(project.role) }}</span
      >
      <span
        v-if="project.my_pending"
        class="text-xs rounded px-2 py-0.5 bg-amber-100 text-amber-800"
        >{{ project.my_pending }} of your changes waiting for review</span
      >
    </div>

    <!-- members -->
    <ul class="divide-y border rounded" data-test="members">
      <li
        v-for="m in project.members"
        :key="m.node"
        class="p-2 flex flex-wrap items-center gap-2"
      >
        <span
          class="inline-block w-2.5 h-2.5 rounded-full"
          :class="
            m.removed
              ? 'bg-foreground/20'
              : m.online
                ? 'bg-green-600'
                : 'bg-foreground/30'
          "
          :title="m.online ? 'online' : 'offline'"
        />
        <span class="grow" :class="m.removed && 'line-through opacity-60'">
          {{ m.name
          }}<span v-if="m.me" class="opacity-70"> (this computer)</span>
          <span v-if="m.people?.length" class="ms-1 text-xs opacity-80"
            >— {{ m.people.join(', ') }}</span
          >
          <span
            v-else-if="!m.me && !m.online && m.last_seen && !m.removed"
            class="ms-1 text-xs opacity-60"
            >last seen {{ new Date(m.last_seen).toLocaleString() }}</span
          >
          <span
            v-if="m.online && !m.me && !m.direct"
            class="ms-1 text-xs opacity-60"
            >(relayed)</span
          >
        </span>
        <span class="text-xs rounded px-2 py-0.5" :class="roleClass(m.role)">{{
          m.removed ? 'removed' : roleLabel(m.role)
        }}</span>
        <template v-if="isPrimary && !m.removed && !m.me">
          <Button
            size="sm"
            variant="ghost"
            :disabled="busy"
            @click="
              emit(
                'member',
                m.node,
                m.role === 'primary' ? 'assistant' : 'primary'
              )
            "
            >{{
              m.role === 'primary' ? 'Make Assistant' : 'Make Primary'
            }}</Button
          >
          <Button
            size="sm"
            variant="ghost"
            :disabled="busy"
            @click="confirmRemove(m)"
            >Remove</Button
          >
        </template>
      </li>
    </ul>

    <!-- invite -->
    <p
      v-if="project.role === 'assistant'"
      class="text-xs rounded bg-foreground/5 p-2"
      data-test="assistant-note"
    >
      You are an <strong>Assistant</strong> in this project: your additions and
      changes to your own items reach everyone; changes to others' work wait for
      a Primary. Only Primaries invite people.
    </p>
    <p
      v-else-if="!isPrimary"
      class="text-xs rounded bg-amber-100 text-amber-900 p-2"
      data-test="not-member-note"
    >
      This computer is not on this project's team any more (a Primary removed
      it, or OpenQDA was reinstalled with a new identity). Ask a Primary for a
      new invitation, or press Leave to keep the project as an ordinary one.
    </p>

    <div v-if="isPrimary" class="space-y-2">
      <Button size="sm" data-test="invite" @click="emit('invite')"
        >Invite people</Button
      >
      <ul v-if="project.invites?.length" class="text-xs space-y-1">
        <li
          v-for="i in project.invites"
          :key="i.id"
          class="flex items-center gap-2"
        >
          <span class="grow"
            >Open invitation as {{ roleLabel(i.role)
            }}{{ i.key_mode !== 'none' ? ' (with key)' : '' }}, until
            {{ new Date(i.expires_at).toLocaleDateString() }}</span
          >
          <Button size="sm" variant="ghost" @click="emit('revoke', i.id)"
            >Revoke</Button
          >
        </li>
      </ul>
    </div>

    <!-- review -->
    <div class="flex flex-wrap items-center gap-2">
      <Button
        size="sm"
        :variant="project.reviews ? 'default' : 'outline'"
        data-test="toggle-review"
        @click="showReview = !showReview"
        >{{
          isPrimary
            ? `Review (${project.reviews})`
            : `My changes waiting for review (${project.my_pending})`
        }}</Button
      >
      <Button size="sm" variant="ghost" :disabled="busy" @click="emit('sync')"
        >Sync now</Button
      >
      <span class="grow" />
      <Button
        size="sm"
        variant="outline-secondary"
        :disabled="busy"
        @click="confirmLeave"
        >Leave</Button
      >
    </div>
    <ReviewList
      v-if="showReview"
      :project-id="project.id"
      @changed="emit('refresh')"
    />
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import Button from '../../Components/interactive/Button.vue';
import ReviewList from './ReviewList.vue';

const props = defineProps({
  project: { type: Object, required: true },
  busy: Boolean,
});
const emit = defineEmits([
  'invite',
  'member',
  'revoke',
  'leave',
  'sync',
  'refresh',
]);

const isPrimary = computed(() => props.project.role === 'primary');
const showReview = ref(false);

const roleLabel = (r) =>
  ({ primary: 'Primary', assistant: 'Assistant' })[r] ?? '—';
const roleClass = (r) =>
  r === 'primary' ? 'bg-primary/15 text-primary' : 'bg-foreground/10';

const confirmRemove = (m) => {
  if (
    window.confirm(
      `Remove ${m.name} from "${props.project.name}"? Their later changes no longer count. The copy on their laptop stays there.`
    )
  ) {
    emit('member', m.node, 'remove');
  }
};

const confirmLeave = () => {
  if (
    window.confirm(
      `Stop collaborating on "${props.project.name}"? The project stays on this computer as an ordinary project.`
    )
  ) {
    emit('leave');
  }
};
</script>
