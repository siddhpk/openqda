<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useCoderFilter } from './useCoderFilter.js';

/*
 | Shows everyone's coding, one's own or one other team member's.
 | Hidden for projects without a team.
 */
const { coder, setCoder } = useCoderFilter();
const page = usePage();

const others = computed(() => {
  const ownId = page.props.auth?.user?.id;
  return (page.props.teamMembers ?? [])
    .filter((member) => member && member.id !== ownId)
    .toSorted((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
});
</script>

<template>
  <label v-if="others.length" class="flex items-center gap-1 text-sm">
    <span class="sr-only md:not-sr-only text-foreground/70">Coded by</span>
    <select
      :value="coder"
      @change="setCoder($event.target.value)"
      class="text-sm rounded-md border-border bg-surface text-foreground py-1 ps-2 pe-7 focus:ring-primary focus:border-primary"
      title="Show the coding of everyone, yourself or one team member"
    >
      <option value="all">Everyone</option>
      <option value="me">Me</option>
      <option v-for="member in others" :key="member.id" :value="member.id">
        {{ member.name }}
      </option>
    </select>
  </label>
</template>
