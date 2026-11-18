import { describe, expect, it } from 'vitest';
import {
  ROOT_ID,
  fromFlat,
  fromNested,
  filterTree,
  isWithin,
  parentOf,
} from '../../../../plugins/visualization/src/mindmap/tree.js';
import {
  balanceSides,
  layoutMindMap,
  layoutNetwork,
  layoutFocus,
} from '../../../../plugins/visualization/src/mindmap/layout.js';
import {
  escapeXml,
  parseFreeMind,
  toFreeMind,
  toHex,
  countNodes,
} from '../../../../plugins/visualization/src/mindmap/freemind.js';

const nested = [
  {
    id: 'a',
    name: 'Migration',
    color: 'rgba(255, 0, 0, 0.5)',
    kind: 'theme',
    meta: { central_concept: 'Leaving as loss' },
    selectionsCount: 3,
    children: [
      { id: 'a1', name: 'Job loss', color: '#00ff00', children: [] },
      { id: 'a2', name: 'Family & "home"', color: '#0000ff', children: [] },
    ],
  },
  {
    id: 'b',
    name: 'Belonging',
    color: '#abc',
    kind: 'category',
    meta: { core: true },
    children: [],
  },
  { id: 'c', name: 'Weather', color: '#123456', children: [] },
];

describe('tree', () => {
  it('builds the same tree from nested and flat input', () => {
    const t1 = fromNested(nested, 'Project');
    const flat = [
      { id: 'a', name: 'Migration', parent: null },
      { id: 'a1', name: 'Job loss', parent: 'a' },
      { id: 'a2', name: 'Family', parent: { id: 'a' } },
      { id: 'b', name: 'Belonging' },
      { id: 'x', name: 'Orphan', parent: 'missing' },
    ];
    const t2 = fromFlat(flat, 'Project');
    expect(t1.id).toBe(ROOT_ID);
    expect(t1.children.map((c) => c.id)).toEqual(['a', 'b', 'c']);
    expect(t2.children.map((c) => c.id)).toEqual(['a', 'b', 'x']);
    expect(t2.children[0].children.map((c) => c.id)).toEqual(['a1', 'a2']);
    expect(parentOf(t1, 'a2').id).toBe('a');
    expect(isWithin(t1.children[0], 'a1')).toBe(true);
    expect(isWithin(t1.children[1], 'a1')).toBe(false);
  });

  it('filters to themes while keeping ancestors', () => {
    const t = filterTree(fromNested(nested), (n) => n.kind === 'theme');
    expect(t.children.map((c) => c.id)).toEqual(['a']);
  });
});

describe('layout', () => {
  it('balances branches left and right of the root', () => {
    const { right, left } = balanceSides(
      fromNested(nested).children,
      new Set()
    );
    expect(right.length + left.length).toBe(3);
    expect(left.length).toBeGreaterThan(0);
  });

  it('places right branches right and left branches left without overlap', () => {
    const { nodes, links } = layoutMindMap(fromNested(nested, 'Project'));
    expect(nodes).toHaveLength(6);
    expect(links).toHaveLength(5);
    const root = nodes.find((n) => n.id === ROOT_ID);
    nodes
      .filter((n) => n.id !== ROOT_ID)
      .forEach((n) => {
        expect(Math.sign(n.x)).toBe(n.side);
        expect(Math.abs(n.x) - n.width / 2).toBeGreaterThan(root.width / 2);
      });
    // siblings in the same column do not overlap vertically
    const a1 = nodes.find((n) => n.id === 'a1');
    const a2 = nodes.find((n) => n.id === 'a2');
    expect(Math.abs(a1.y - a2.y)).toBeGreaterThanOrEqual(a1.height);
    // children sit further out than their parent
    expect(Math.abs(a1.x)).toBeGreaterThan(
      Math.abs(nodes.find((n) => n.id === 'a').x)
    );
  });

  it('hides children of folded nodes', () => {
    const { nodes } = layoutMindMap(fromNested(nested), {
      collapsed: new Set(['a']),
    });
    expect(nodes.map((n) => n.id)).not.toContain('a1');
    expect(nodes.find((n) => n.id === 'a').collapsed).toBe(true);
  });

  it('arranges everything related to the focus code by relationship type', () => {
    const byId = new Map([
      ['f', { id: 'f', name: 'Focus' }],
      ['a', { id: 'a', name: 'Antecedent' }],
      ['b', { id: 'b', name: 'Outcome' }],
      ['c', { id: 'c', name: 'Associated' }],
      ['d', { id: 'd', name: 'Unrelated' }],
    ]);
    const rels = [
      { id: 1, from: 'a', to: 'f', type: 'enables', directed: true },
      { id: 2, from: 'f', to: 'b', type: 'leads_to', directed: true },
      { id: 3, from: 'c', to: 'f', type: 'associated_with', directed: false },
      { id: 4, from: 'a', to: 'd', type: 'leads_to', directed: true },
    ];
    const { nodes, relations, headers } = layoutFocus(
      byId.get('f'),
      byId,
      rels,
      {
        label: (type) => `label:${type}`,
      }
    );
    expect(nodes.map((n) => n.id).sort()).toEqual(['a', 'b', 'c', 'f']);
    expect(relations).toHaveLength(3);
    expect(nodes.find((n) => n.id === 'a').x).toBeLessThan(0);
    expect(nodes.find((n) => n.id === 'b').x).toBeGreaterThan(0);
    expect(nodes.find((n) => n.id === 'c').y).toBeLessThan(0);
    expect(headers.map((h) => h.title).sort()).toEqual([
      'label:associated_with',
      'label:enables',
      'label:leads_to',
    ]);
  });

  it('layers directed relationships of any type and skips undirected ones', () => {
    const byId = new Map(
      ['x', 'y', 'z', 'w'].map((id) => [id, { id, name: id }])
    );
    const rels = [
      { from: 'x', to: 'y', type: 'leads_to', directed: true },
      { from: 'y', to: 'z', type: 'enables', directed: true },
      { from: 'z', to: 'w', type: 'associated_with', directed: false },
    ];
    const { nodes } = layoutNetwork(byId, rels);
    const x = nodes.find((n) => n.id === 'x').x;
    const y = nodes.find((n) => n.id === 'y').x;
    const z = nodes.find((n) => n.id === 'z').x;
    expect(x).toBeLessThan(y);
    expect(y).toBeLessThan(z);
    expect(nodes.find((n) => n.id === 'w')).toBeUndefined();
    // limited to chosen types
    expect(
      layoutNetwork(byId, rels, { types: ['leads_to'] }).nodes
    ).toHaveLength(2);
  });
});

describe('FreeMind', () => {
  it('converts colours to hex', () => {
    expect(toHex('rgba(255, 0, 0, 0.5)')).toBe('#ff0000');
    expect(toHex('#abc')).toBe('#aabbcc');
    expect(toHex('#AABBCCDD')).toBe('#aabbcc');
    expect(toHex('nonsense')).toBeNull();
    expect(escapeXml('a<b & "c"')).toBe('a&lt;b &amp; &quot;c&quot;');
  });

  it('exports hierarchy, colours, notes, kinds and relationships', () => {
    const root = fromNested(nested, 'Project');
    const xml = toFreeMind({
      root,
      relations: [
        { from: 'a1', to: 'b', type: 'leads_to', valence: -1 },
        { from: 'b', to: 'nowhere', type: 'leads_to' },
      ],
      notesByCode: { a: ['First memo\nsecond line'] },
      relationLabels: { leads_to: 'leads to' },
      sides: { c: -1 },
    });
    expect(xml).toMatch(/^<map version="1\.0\.1">/);
    expect(xml).toContain('TEXT="Family &amp; &quot;home&quot;"');
    expect(xml).toContain('BACKGROUND_COLOR="#ff0000"');
    expect(xml).toContain('<icon BUILTIN="idea"/>');
    expect(xml).toContain('<icon BUILTIN="bookmark"/>');
    expect(xml).toContain('<richcontent TYPE="NOTE">');
    expect(xml).toContain('DESTINATION="ID_b"');
    expect(xml).toContain('MIDDLE_LABEL="leads to (−)"');
    expect(xml).not.toContain('ID_nowhere');
    expect(xml).toMatch(/ID="ID_c"[^>]*POSITION="left"/);

    // round-trip through the parser
    const parsed = parseFreeMind(xml, DOMParser);
    expect(parsed.title).toBe('Project');
    expect(parsed.nodes.map((n) => n.name)).toEqual([
      'Migration',
      'Belonging',
      'Weather',
    ]);
    expect(parsed.nodes[0].children[1].name).toBe('Family & "home"');
    expect(parsed.nodes[0].color).toBe('#ff0000');
    expect(parsed.nodes[0].note).toBe('First memo\nsecond line');
    expect(countNodes(parsed.nodes)).toBe(5);
  });

  it('reads Freeplane rich-text nodes and rejects non-maps', () => {
    const xml = `<map version="freeplane 1.9.0"><node TEXT="Centre"><node ID="1" COLOR="#336699"><richcontent TYPE="NODE"><html><body><p>Rich <b>label</b></p></body></html></richcontent><node TEXT="leaf"/></node></node></map>`;
    const parsed = parseFreeMind(xml, DOMParser);
    expect(parsed.nodes[0].name).toBe('Rich label');
    expect(parsed.nodes[0].color).toBe('#336699');
    expect(parsed.nodes[0].children[0].name).toBe('leaf');
    expect(() => parseFreeMind('<html></html>', DOMParser)).toThrow();
  });
});
