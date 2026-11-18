<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAi } from '../../../domain/ai/useAi.js';
import Button from '../../../Components/interactive/Button.vue';
import { flashMessage } from '../../../Components/notification/flashMessage.js';

/*------------------------------------------------------------------------------
 | AiConsent.vue
 | Where would text go, may it go there, and what has been sent so far?
 | The project owner opts the project in or out of the optional embedding
 | service, maintains the list of terms to pseudonymise and can delete the
 | project's cached embeddings.
 *----------------------------------------------------------------------------*/

const {
  status,
  loadStatus,
  setConsent,
  setRedactionTerms,
  purgeCache,
  globallyEnabled,
} = useAi();
const busy = ref(false);
const acknowledge = ref(false);
const editingTerms = ref(false);
const termsText = ref('');

onMounted(() =>
  loadStatus().catch((e) => flashMessage(e.message, { type: 'error' }))
);

const run = async (fn, message) => {
  busy.value = true;
  try {
    await fn();
    if (message) flashMessage(message, { type: 'success' });
  } catch (e) {
    flashMessage(e.message, { type: 'error' });
  } finally {
    busy.value = false;
  }
};

const enable = () =>
  run(
    () => setConsent(true, acknowledge.value),
    'Embeddings enabled for this project'
  );
const disable = () =>
  run(
    () => setConsent(false),
    'Embeddings disabled; cached embeddings deleted'
  );
const purge = () =>
  run(purgeCache, 'Cached embeddings of this project deleted');
const startTerms = () => {
  termsText.value = (status.value?.redactionTerms ?? []).join('\n');
  editingTerms.value = true;
};
const saveTerms = () =>
  run(async () => {
    const terms = termsText.value
      .split('\n')
      .map((t) => t.trim())
      .filter((t) => t.length >= 2);
    await setRedactionTerms(terms);
    editingTerms.value = false;
  }, 'Pseudonymization list saved');

const where = computed(() => {
  const e = status.value?.embedding;
  if (!e) return '';
  const host = e.endpointHost ? ` at ${e.endpointHost}` : '';
  return `${e.provider} (${e.model})${host} – ${e.cloud ? 'a third-party service outside your infrastructure' : 'runs on infrastructure you control'}`;
});
// why a service counts as third party: decided from its address
const whyCloud = computed(() => {
  const e = status.value?.embedding;
  if (!e?.cloud) return '';
  return e.endpointPrivate
    ? 'The administrator marked this service as third party.'
    : 'Its address is not on this computer or a private network, so it is treated as a third-party service.';
});
const stateText = computed(
  () =>
    ({
      granted: 'Enabled',
      none: 'Not enabled',
      config_changed:
        'The embedding service, its address, the pseudonymization setting or the consent terms changed since it was enabled – the owner has to confirm again.',
      cloud_unacknowledged:
        'Waiting for the owner to confirm the use of a third-party service.',
      blocked_local_only:
        'The configured service is a third-party cloud service, but this installation only allows local services (AI_LOCAL_ONLY).',
    })[status.value?.consentState] ?? ''
);
const number = (n) => new Intl.NumberFormat().format(n ?? 0);
</script>

<template>
  <div
    v-if="globallyEnabled && status"
    class="text-sm space-y-2 border rounded-md p-3"
  >
    <div class="font-semibold">Embeddings for this project</div>
    <p class="text-xs text-foreground/80">
      Autocoding turns sentences or paragraphs into embedding vectors using
      <b>{{ where }}</b
      >. Nothing else is sent; all statistics run in your browser.
    </p>
    <p
      v-if="
        status.embedding?.cloud && status.consentState !== 'blocked_local_only'
      "
      class="text-xs text-destructive"
    >
      Excerpts will leave your infrastructure. Check that this is covered by
      your participants' consent and your data protection agreements.
      {{ whyCloud }}
    </p>
    <p v-if="status.redaction?.active" class="text-xs text-foreground/70">
      Before sending, e-mail addresses, URLs, phone numbers, IBANs, card
      numbers, IP addresses and
      {{ status.redactionTermsCount }} project term(s) are replaced by
      placeholders.
    </p>
    <p
      class="text-xs"
      :class="
        status.projectEnabled ? 'text-confirmative' : 'text-foreground/70'
      "
    >
      {{ stateText }}
    </p>

    <template v-if="status.canChangeConsent">
      <label
        v-if="
          !status.projectEnabled &&
          status.cloud &&
          status.consentState !== 'blocked_local_only'
        "
        class="flex items-start gap-2 text-xs"
      >
        <input v-model="acknowledge" type="checkbox" class="rounded mt-0.5" />
        <span
          >I confirm that sending excerpts to this third-party service is
          covered by consent and agreements.</span
        >
      </label>
      <div class="flex flex-wrap gap-2">
        <Button
          v-if="!status.projectEnabled"
          size="sm"
          :disabled="
            busy ||
            status.consentState === 'blocked_local_only' ||
            (status.cloud && !acknowledge)
          "
          @click="enable"
          >Enable for project</Button
        >
        <Button
          v-else
          size="sm"
          variant="outline"
          :disabled="busy"
          @click="disable"
          >Disable for project</Button
        >
        <Button size="sm" variant="ghost" :disabled="busy" @click="startTerms"
          >Pseudonymization list</Button
        >
        <Button
          size="sm"
          variant="ghost"
          :disabled="busy"
          title="Delete this project's cached embeddings"
          @click="purge"
          >Delete cache</Button
        >
      </div>
      <div v-if="editingTerms" class="space-y-1">
        <p class="text-xs text-foreground/70">
          One term per line (participant names, places, organizations …). Stored
          encrypted. Applied whenever text goes to a third-party service, or
          always if the administrator set AI_REDACTION=always.
        </p>
        <textarea
          v-model="termsText"
          rows="5"
          class="w-full text-xs rounded border-input bg-surface"
        ></textarea>
        <div class="flex justify-end gap-2">
          <Button size="sm" variant="ghost" @click="editingTerms = false"
            >Cancel</Button
          >
          <Button size="sm" :disabled="busy" @click="saveTerms"
            >Save list</Button
          >
        </div>
      </div>
    </template>
    <p v-else-if="!status.projectEnabled" class="text-xs text-foreground/60">
      Ask the project owner to enable it.
    </p>

    <p class="text-[10px] text-foreground/50">
      Last 30 days: {{ number(status.usage30d.requests) }} requests,
      {{ number(status.usage30d.characters) }} characters ({{
        number(status.usage30d.cloudCharacters)
      }}
      to third parties), {{ number(status.usage30d.redactions) }} replacements.
    </p>
  </div>
</template>
