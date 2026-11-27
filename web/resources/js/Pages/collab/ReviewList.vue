<template>
  <div class="space-y-3" data-test="review-list">
    <p v-if="loading" class="text-xs opacity-80">Loading…</p>
    <template v-else>
      <div v-if="changes.length" class="space-y-2">
        <div class="flex items-center justify-between gap-2">
          <h4 class="font-medium">Changes waiting for a Primary</h4>
          <Button
            v-if="canReview && others.length > 1"
            size="sm"
            variant="outline-confirmative"
            @click="
              decide(
                others.map((c) => c.op),
                'accept'
              )
            "
            >Accept all</Button
          >
        </div>
        <ul class="divide-y border rounded">
          <li
            v-for="c in changes"
            :key="c.op"
            class="p-2 space-y-1"
            :data-test="`change-${c.op}`"
          >
            <div class="flex flex-wrap items-center gap-2 text-xs">
              <strong>{{ c.label }}</strong>
              <span class="opacity-70"
                >{{ c.mine ? 'your change' : `by ${c.by}` }} ·
                {{ new Date(c.at).toLocaleString() }}</span
              >
            </div>
            <p v-if="c.delete" class="text-xs text-destructive">
              Delete this item
            </p>
            <dl v-else class="grid grid-cols-[auto_1fr] gap-x-3 text-xs">
              <template v-for="(v, field) in c.fields" :key="field">
                <dt class="font-medium">{{ field }}</dt>
                <dd>
                  <span class="line-through opacity-60">{{
                    v.old ?? '—'
                  }}</span>
                  → <span>{{ v.new ?? '—' }}</span>
                </dd>
              </template>
            </dl>
            <div v-if="canReview && !c.mine" class="flex gap-2 justify-end">
              <Button
                size="sm"
                variant="ghost"
                @click="decide([c.op], 'reject')"
                >Reject</Button
              >
              <Button
                size="sm"
                variant="confirmative"
                @click="decide([c.op], 'accept')"
                >Accept</Button
              >
            </div>
          </li>
        </ul>
      </div>

      <div v-if="orphans.length" class="space-y-2">
        <h4 class="font-medium">
          Held back because something they belong to was deleted
        </h4>
        <ul class="divide-y border rounded">
          <li
            v-for="o in orphans"
            :key="o.gid"
            class="p-2 flex flex-wrap items-center gap-2 text-xs"
          >
            <span class="grow">
              <strong>{{ o.label }}</strong>
              <span v-if="o.parent" class="opacity-70">
                — belongs to deleted {{ o.parent }}</span
              >
            </span>
            <template v-if="canReview">
              <Button size="sm" variant="ghost" @click="orphan(o, 'discard')"
                >Discard</Button
              >
              <Button size="sm" variant="outline" @click="orphan(o, 'restore')"
                >Bring back {{ o.parent ? 'the parent' : '' }}</Button
              >
            </template>
          </li>
        </ul>
      </div>

      <p v-if="!changes.length && !orphans.length" class="text-xs opacity-80">
        Nothing to review.
      </p>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import Button from '../../Components/interactive/Button.vue';
import { useCollab } from '../../domain/collab/useCollab.js';

const props = defineProps({ projectId: { type: Number, required: true } });
const emit = defineEmits(['changed']);
const collab = useCollab();

const loading = ref(true);
const data = ref({ changes: [], orphans: [], role: null });
const changes = computed(() => data.value.changes ?? []);
const orphans = computed(() => data.value.orphans ?? []);
const others = computed(() => changes.value.filter((c) => !c.mine));
const canReview = computed(() => data.value.role === 'primary');

const load = async () => {
  loading.value = true;
  data.value = (await collab.reviews(props.projectId)) ?? data.value;
  loading.value = false;
};

const decide = async (ops, decision) => {
  const next = await collab.decide(props.projectId, ops, decision);
  if (next) data.value = next;
  emit('changed');
};

const orphan = async (o, action) => {
  const next = await collab.orphan(props.projectId, o.tbl, o.gid, action);
  if (next) data.value = next;
  emit('changed');
};

onMounted(load);
defineExpose({ load });
</script>
