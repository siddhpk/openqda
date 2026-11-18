<script setup>
import { computed } from 'vue';
import ProfileImage from '../../../Components/user/ProfileImage.vue';
import Button from '../../../Components/interactive/Button.vue';
import { useLiveCoding } from '../../../collab/useLiveCoding.js';
import { useUsers } from '../../../domain/teams/useUsers.js';

/*
 | Team members who have the same source open, and a notice when someone
 | changed many coded segments at once.
 */
const { viewers, stale, reload, dismissStale } = useLiveCoding();
const { getMemberBy } = useUsers();

const shown = computed(() => viewers.value.slice(0, 4));
const more = computed(() => Math.max(0, viewers.value.length - 4));
const names = computed(() => viewers.value.map((v) => v.name).join(', '));
const staleBy = computed(
  () => getMemberBy(stale.value?.userId)?.name ?? 'A team member'
);
</script>

<template>
  <div
    v-if="viewers.length"
    class="flex items-center -space-x-1"
    :title="`Also viewing this source: ${names}`"
  >
    <ProfileImage
      v-for="viewer in shown"
      :key="viewer.id"
      size="compact"
      :name="viewer.name"
      :src="viewer.profile_photo_url"
      class="ring-2 ring-surface rounded-full"
    />
    <span v-if="more" class="ps-2 text-xs text-foreground/70">+{{ more }}</span>
  </div>
  <div
    v-if="stale"
    role="status"
    class="flex items-center gap-2 text-sm text-foreground/80"
  >
    <span
      >{{ staleBy }} added {{ stale.count }} coded segment{{
        stale.count === 1 ? '' : 's'
      }}.</span
    >
    <Button variant="outline-secondary" @click="reload">Reload</Button>
    <Button variant="outline" @click="dismissStale" title="Dismiss">✕</Button>
  </div>
</template>
