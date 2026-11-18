export default {
  key: 'cooccurrence',
  name: 'CooccurrenceView',
  title: 'Code Co-occurrence',
  summary: 'How often segments of two codes overlap or lie close together',
  type: 'visualization',
  load: () => import('./CooccurrenceView.vue'),
  hasOptions: false,
};
