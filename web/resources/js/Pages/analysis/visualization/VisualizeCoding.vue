<script setup lang="ts">
import { provide } from 'vue';
import { createVisualizationAPI } from './createVisualizationAPI';
import { useAnalysis } from '../useAnalysis';
import { useVisualizerPlugins } from './useVisualizerPlugins';
import { useUsers } from '../../../domain/teams/useUsers';
import Headline3 from '../../../Components/layout/Headline3.vue';
import Button from '../../../Components/interactive/Button.vue';
import SideOverlay from '../../../Components/layout/SideOverlay.vue';
import ActivityIndicator from '../../../Components/ActivityIndicator.vue';
import NoteList from '../../coding/tree/NoteList.vue';
import { Collapse } from 'vue-collapsed';
import { usePage } from '@inertiajs/vue3';
import {
  codeTypes,
  relationLabel,
  relationType,
} from '../../../domain/qda/vocabulary.js';
const {
  sources,
  codes,
  checkedSources,
  checkedCodes,
  hasSelections,
  notes,
  checkSource,
} = useAnalysis();
const { getMemberBy } = useUsers();
const { visualizerComponent, showMenu, setShowMenu } = useVisualizerPlugins();
const { api } = createVisualizationAPI({
  sources,
  codes,
  checkedCodes,
  checkedSources,
  notes,
});

// additional functionality attached
api.getMemberBy = getMemberBy;
api.setShowMenu = setShowMenu;
// relationships between codes (mind map, focus view, network) and the
// project's own vocabulary for naming them
api.relations = usePage().props.relations ?? [];
api.relationLabel = relationLabel;
api.typeLabel = (type) => relationType(type).label;
api.codeTypes = () => codeTypes();

provide('api', api);
provide('components', {
  Headline3,
  Button,
  ActivityIndicator,
  Collapse,
  NoteList,
});
</script>

<template>
  <component
    :is="visualizerComponent"
    :codes="codes"
    :api="api"
    :sources="sources"
    :notes="notes"
    :hasSelections="hasSelections"
    :checkedCodes="checkedCodes"
    :menu="SideOverlay"
    :showMenu="showMenu"
    :checkedSources="checkedSources"
    @remove="(id) => checkSource(id)"
  >
  </component>
</template>

<style scoped></style>
