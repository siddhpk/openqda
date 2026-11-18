export default {
  key: 'mindmap',
  name: 'MindMapView',
  title: 'Mind Map',
  summary: 'FreeMind-style map of codes, relationships and memos',
  type: 'visualization',
  load: () => import('./MindMapView.vue'),
  hasOptions: false,
};
