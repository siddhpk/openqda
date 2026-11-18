/**
 * The analytic vocabulary of the current project: kinds of codes,
 * relationship types, memo types, statuses and custom code fields.
 *
 * OpenQDA does not prescribe a methodology. Each project starts from a
 * small neutral set and researchers rename, extend or archive entries on
 * the Research design page, so any analytic language can be used.
 *
 * The server is the source of truth (app/Services/Qda/Vocabulary.php); it
 * shares the vocabulary with every project page as `vocabulary` and this
 * store is updated on each Inertia navigation (see app.js).
 * @module
 */
import { reactive } from 'vue';

const ALL_TARGETS = [
  'project',
  'source',
  'selection',
  'code',
  'relation',
  'case',
  'concept',
  'proposition',
];

/** Same neutral defaults as the server; used until a project is loaded. */
export const DEFAULT_VOCABULARY = {
  code_types: [
    {
      key: 'code',
      label: 'Code',
      description: 'Labels a meaningful segment of the data.',
    },
    {
      key: 'category',
      label: 'Category',
      description: 'Groups related codes.',
    },
    {
      key: 'theme',
      label: 'Theme',
      description: 'A broader pattern across the data.',
    },
  ],
  relation_types: [
    {
      key: 'leads_to',
      label: 'leads to',
      short: 'leads to',
      directed: true,
      valence: true,
    },
    { key: 'precedes', label: 'precedes', short: 'precedes', directed: true },
    {
      key: 'condition_for',
      label: 'is a condition for',
      short: 'condition',
      directed: true,
    },
    {
      key: 'consequence',
      label: 'is a consequence of',
      short: 'consequence',
      directed: true,
    },
    { key: 'part_of', label: 'is part of', short: 'part of', directed: true },
    { key: 'is_a', label: 'is a kind of', short: 'is a', directed: true },
    {
      key: 'associated_with',
      label: 'is associated with',
      short: 'assoc.',
      directed: false,
    },
    {
      key: 'contradicts',
      label: 'is in tension with',
      short: 'tension',
      directed: false,
    },
    { key: 'custom', label: 'custom label…', short: '', directed: true },
  ],
  memo_types: [
    { key: 'analytic', label: 'Memo', targets: ALL_TARGETS, template: '' },
    {
      key: 'reflexive',
      label: 'Reflexive note',
      targets: ALL_TARGETS,
      template:
        'How my position, assumptions and reactions shape this analysis:\n',
    },
    {
      key: 'summary',
      label: 'Summary',
      targets: ['project', 'source', 'selection', 'case', 'concept'],
      template:
        'What this is about:\n\nWhat matters for the research questions:\n\nOpen questions:\n',
    },
    {
      key: 'definition',
      label: 'Definition',
      targets: ['code', 'relation', 'concept', 'proposition'],
      template:
        'What it captures:\n\nWhat it includes and excludes:\n\nTypical evidence:\n',
    },
    {
      key: 'decision',
      label: 'Decision note',
      targets: ALL_TARGETS,
      template: 'Decision:\n\nReasons:\n\nAlternatives considered:\n',
    },
  ],
  statuses: [
    { key: 'draft', label: 'Draft' },
    { key: 'developing', label: 'Developing' },
    { key: 'stable', label: 'Stable' },
    { key: 'retired', label: 'Retired' },
  ],
  code_fields: [
    {
      key: 'origin',
      label: 'Origin',
      type: 'choice',
      options: [
        { key: 'a_priori', label: 'Defined before coding' },
        { key: 'emergent', label: 'Developed from the data' },
        { key: 'in_vivo', label: "Participants' own words" },
      ],
    },
  ],
};

const SECTIONS = Object.keys(DEFAULT_VOCABULARY);

const normalize = (v) => {
  const out = {};
  for (const section of SECTIONS) {
    out[section] = (v?.[section] ?? DEFAULT_VOCABULARY[section]).map((e) => ({
      description: '',
      archived: false,
      ...e,
      ...(section === 'relation_types'
        ? {
            short: e.short ?? e.label,
            directed: e.directed ?? true,
            valence: !!e.valence,
          }
        : {}),
      ...(section === 'memo_types'
        ? {
            targets: e.targets?.length ? e.targets : ALL_TARGETS,
            template: e.template ?? '',
          }
        : {}),
      ...(section === 'code_fields'
        ? { type: e.type ?? 'text', options: e.options ?? [] }
        : {}),
    }));
  }
  return out;
};

const state = reactive({ data: normalize(DEFAULT_VOCABULARY) });

/** Replace the current vocabulary (called on navigation and after edits). */
export const setVocabulary = (vocabulary) => {
  if (vocabulary) state.data = normalize(vocabulary);
};

/** The reactive store: `vocabularyState.data.code_types` etc. */
export const vocabularyState = state;

const active = (section) => state.data[section].filter((e) => !e.archived);
const find = (section, key) => state.data[section].find((e) => e.key === key);

export const codeTypes = () => active('code_types');
export const relationTypes = () => active('relation_types');
export const memoTypes = () => active('memo_types');
export const statuses = () => active('statuses');
/** All custom fields, archived ones included (to show existing values). */
export const allCodeFields = () => state.data.code_fields;

export const kindOf = (code) => code?.kind ?? 'code';

export const kindLabel = (code) =>
  find('code_types', kindOf(code))?.label ?? 'Code';

export const statusLabel = (key) => find('statuses', key)?.label ?? key ?? '';

export const relationType = (key) =>
  find('relation_types', key) ?? {
    key,
    label: key ?? '',
    short: key ?? '',
    directed: true,
    valence: false,
  };

export const relationLabel = (relation) => {
  if (!relation) return '';
  if (relation.type === 'custom') return relation.label ?? '';
  const base =
    relationType(relation.type).short || relationType(relation.type).label;
  if (relation.valence === 1) return `${base} (+)`;
  if (relation.valence === -1) return `${base} (−)`;
  return base;
};

export const memoType = (key) =>
  find('memo_types', key ?? 'analytic') ??
  find('memo_types', 'analytic') ?? {
    key: key ?? 'analytic',
    label: 'Memo',
    template: '',
    targets: ALL_TARGETS,
  };

export const memoTypesFor = (target) =>
  memoTypes().filter((m) => m.targets.includes(target));

/**
 * Neutral progress indicators for the analysis overview: what exists,
 * without implying an order of steps.
 */
export const overviewIndicators = ({
  codes = [],
  relations = [],
  notes = [],
}) => {
  const typeCounts = state.data.code_types
    .map((t) => ({
      label: t.label,
      value: codes.filter((c) => kindOf(c) === t.key).length,
    }))
    .filter((x) => x.value > 0 || !x.archived);
  const relationCounts = state.data.relation_types
    .map((t) => ({
      label: t.key === 'custom' ? 'custom relationships' : t.label,
      value: relations.filter((r) => r.type === t.key).length,
    }))
    .filter((x) => x.value > 0);
  const memoCounts = state.data.memo_types
    .map((t) => ({
      label: t.label,
      value: notes.filter((n) => (n.memo_type ?? 'analytic') === t.key).length,
    }))
    .filter((x) => x.value > 0);
  return {
    codes: [
      ...typeCounts,
      {
        label: 'with a definition',
        value: codes.filter((c) => c.meta?.definition).length,
      },
      {
        label: 'with properties',
        value: codes.filter((c) => c.meta?.properties?.length).length,
      },
    ],
    relations: relationCounts.length
      ? relationCounts
      : [{ label: 'relationships', value: 0 }],
    memos: memoCounts.length ? memoCounts : [{ label: 'memos', value: 0 }],
  };
};
