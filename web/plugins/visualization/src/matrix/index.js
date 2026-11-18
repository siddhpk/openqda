export default {
  key: 'matrix',
  name: 'MatrixView',
  title: 'Code × Source Matrix',
  summary: 'Meta-matrix of codes by source or by source variable',
  type: 'visualization',
  load: () => import('./MatrixView.vue'),
  hasOptions: false,
};
