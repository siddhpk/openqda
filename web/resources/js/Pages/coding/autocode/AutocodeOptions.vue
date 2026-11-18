<script setup>
/*------------------------------------------------------------------------------
 | AutocodeOptions.vue – settings shared by the panel and the batch page.
 *----------------------------------------------------------------------------*/
import Button from '../../../Components/interactive/Button.vue';

defineProps({ options: { type: Object, required: true } });
const emit = defineEmits(['changed']);
// the parent owns the options object and applies the change
const set = (key, value) => emit('changed', key, value);
const inputClass = 'text-xs rounded border-input bg-surface';
</script>

<template>
  <div class="space-y-3 text-xs">
    <div class="grid grid-cols-2 gap-2">
      <span class="font-medium col-span-2">Unit of analysis</span>
      <Button
        size="sm"
        :variant="options.granularity === 'sentence' ? 'default' : 'outline'"
        @click="set('granularity', 'sentence')"
        >Sentence</Button
      >
      <Button
        size="sm"
        :variant="options.granularity === 'paragraph' ? 'default' : 'outline'"
        @click="set('granularity', 'paragraph')"
        >Paragraph</Button
      >
    </div>
    <label class="flex items-center justify-between gap-2">
      <span>Method</span>
      <select
        :value="options.method"
        :class="inputClass"
        @change="set('method', $event.target.value)"
      >
        <option value="auto">Auto – the better one on held-out data</option>
        <option value="similarity">
          Similarity to code examples (few examples, instant)
        </option>
        <option value="logistic">
          Logistic regression, one model per code (≈ 15+ examples per code)
        </option>
      </select>
    </label>
    <label class="flex items-center justify-between gap-2">
      <span
        title="Whole sources: tests on sources the model has not seen – what to expect for new interviews. Units: tests on held-out units of the same sources – what to expect for the rest of already-coded sources."
        >Evaluate on</span
      >
      <select
        :value="options.validation"
        :class="inputClass"
        @change="set('validation', $event.target.value)"
      >
        <option value="sources">held-out sources (new material)</option>
        <option value="units">held-out units of the same sources</option>
      </select>
    </label>
    <label class="flex items-center gap-2">
      <input
        type="checkbox"
        class="rounded"
        :checked="options.multiple"
        @change="set('multiple', $event.target.checked)"
      />
      <span>Allow several codes for the same unit (overlapping coding)</span>
    </label>
    <label class="flex items-center justify-between gap-2">
      <span>Minimum coded units per code</span>
      <input
        type="number"
        min="2"
        max="50"
        :value="options.minExamples"
        :class="inputClass + ' w-20'"
        @change="set('minExamples', Number($event.target.value))"
      />
    </label>
    <label class="flex items-center gap-2">
      <input
        type="checkbox"
        class="rounded"
        :checked="options.rollUp"
        @change="set('rollUp', $event.target.checked)"
      />
      <span>Merge codes with too few units into their parent code</span>
    </label>
    <label class="flex items-center gap-2">
      <input
        type="checkbox"
        class="rounded"
        :checked="options.includeCoded"
        @change="set('includeCoded', $event.target.checked)"
      />
      <span>Also suggest for units that are already coded</span>
    </label>
  </div>
</template>
