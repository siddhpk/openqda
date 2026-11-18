import { ROOT_ID } from './tree.js';

/**
 * FreeMind (.mm) import and export. The format is also read by Freeplane.
 * Hierarchy → nested <node>, code colour → BACKGROUND_COLOR, memos → NOTE
 * richcontent, relationships → <arrowlink>, code kind → <attribute>/<icon>.
 * @module
 */

export const escapeXml = (value) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
    .replace(/\n/g, '&#10;');

const escapeText = (value) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

/**
 * Converts "#rgb", "#rrggbb", "rgb(…)" or "rgba(…)" to "#rrggbb".
 */
export const toHex = (color) => {
  if (!color) return null;
  const c = String(color).trim();
  if (/^#[0-9a-f]{6}$/i.test(c)) return c.toLowerCase();
  if (/^#[0-9a-f]{8}$/i.test(c)) return c.slice(0, 7).toLowerCase();
  if (/^#[0-9a-f]{3}$/i.test(c))
    return `#${[...c.slice(1)].map((x) => x + x).join('')}`.toLowerCase();
  const m = c.match(/rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i);
  if (m) {
    return `#${[m[1], m[2], m[3]]
      .map((v) =>
        Math.max(0, Math.min(255, Math.round(Number(v))))
          .toString(16)
          .padStart(2, '0')
      )
      .join('')}`;
  }
  return null;
};

const ICONS = { theme: 'idea', category: 'folder', core: 'bookmark' };

const mmId = (id) => `ID_${String(id).replace(/[^A-Za-z0-9_]/g, '_')}`;

const relationText = (r, labels) =>
  (r.type === 'custom' ? (r.label ?? '') : (labels?.[r.type] ?? r.type)) +
  (r.valence === 1 ? ' (+)' : r.valence === -1 ? ' (−)' : '');

/**
 * @param options {object}
 * @param options.root {object} tree from tree.js (root node = map centre)
 * @param options.relations {{from: string, to: string, type: string, label?: string, valence?: number}[]}
 * @param options.notesByCode {Object<string, string[]>} memo texts per code id
 * @param options.relationLabels {Object<string, string>} type → label
 * @param options.sides {Object<string, number>=} top-level node id → 1 (right) | -1 (left)
 * @return {string} .mm XML
 */
export const toFreeMind = ({
  root,
  relations = [],
  notesByCode = {},
  relationLabels = {},
  sides = {},
}) => {
  const ids = new Set();
  const collect = (n) => {
    ids.add(n.id);
    (n.children ?? []).forEach(collect);
  };
  collect(root);
  const outgoing = new Map();
  relations.forEach((r) => {
    if (!ids.has(r.from) || !ids.has(r.to)) return;
    if (!outgoing.has(r.from)) outgoing.set(r.from, []);
    outgoing.get(r.from).push(r);
  });

  const lines = [
    '<map version="1.0.1">',
    '<!-- Exported from OpenQDA. Open with FreeMind or Freeplane. -->',
  ];
  const write = (node, depth) => {
    const pad = '  '.repeat(depth);
    const attrs = [`ID="${mmId(node.id)}"`, `TEXT="${escapeXml(node.name)}"`];
    const hex = node.id !== ROOT_ID ? toHex(node.color) : null;
    if (hex) attrs.push(`BACKGROUND_COLOR="${hex}"`);
    if (depth === 1)
      attrs.push(`POSITION="${(sides[node.id] ?? 1) < 0 ? 'left' : 'right'}"`);
    if (node.collapsed) attrs.push('FOLDED="true"');
    lines.push(`${pad}<node ${attrs.join(' ')}>`);
    if (node.kind && node.kind !== 'code' && node.kind !== 'root') {
      lines.push(
        `${pad}  <attribute NAME="kind" VALUE="${escapeXml(node.kind)}"/>`
      );
      if (ICONS[node.kind])
        lines.push(`${pad}  <icon BUILTIN="${ICONS[node.kind]}"/>`);
    }
    if (node.meta?.core) lines.push(`${pad}  <icon BUILTIN="${ICONS.core}"/>`);
    if (node.meta?.definition)
      lines.push(
        `${pad}  <attribute NAME="definition" VALUE="${escapeXml(node.meta.definition)}"/>`
      );
    if (node.count)
      lines.push(`${pad}  <attribute NAME="segments" VALUE="${node.count}"/>`);
    const notes = notesByCode[node.id] ?? [];
    if (notes.length) {
      const body = notes
        .map((t) => `<p>${escapeText(t).replace(/\n/g, '<br/>')}</p>`)
        .join('');
      lines.push(
        `${pad}  <richcontent TYPE="NOTE"><html><head></head><body>${body}</body></html></richcontent>`
      );
    }
    (outgoing.get(node.id) ?? []).forEach((r) => {
      const arrow = r.directed === false ? 'None' : 'Default';
      lines.push(
        `${pad}  <arrowlink DESTINATION="${mmId(r.to)}" STARTARROW="None" ENDARROW="${arrow}" MIDDLE_LABEL="${escapeXml(relationText(r, relationLabels))}"/>`
      );
    });
    (node.children ?? []).forEach((child) => write(child, depth + 1));
    lines.push(`${pad}</node>`);
  };
  write(root, 0);
  lines.push('</map>');
  return lines.join('\n');
};

const directChildren = (el, name) =>
  [...el.children].filter((c) => c.nodeName === name);

const nodeText = (el) => {
  const text = el.getAttribute('TEXT');
  if (text) return text;
  const rich = directChildren(el, 'richcontent').find(
    (r) => (r.getAttribute('TYPE') ?? '').toUpperCase() === 'NODE'
  );
  return rich ? rich.textContent.replace(/\s+/g, ' ').trim() : '';
};

const nodeNote = (el) => {
  const rich = directChildren(el, 'richcontent').find(
    (r) => (r.getAttribute('TYPE') ?? '').toUpperCase() === 'NOTE'
  );
  if (!rich) return '';
  // keep line and paragraph breaks
  [...rich.getElementsByTagName('br')].forEach((br) => br.replaceWith('\n'));
  const parts = [...rich.querySelectorAll('p, li, div')]
    .map((p) => p.textContent.trim())
    .filter(Boolean);
  return (parts.length ? parts.join('\n') : rich.textContent).trim();
};

/**
 * Parses a FreeMind/Freeplane document.
 * @param xml {string}
 * @param ParserClass {typeof DOMParser=} injectable for tests
 * @return {{title: string, nodes: {name: string, color?: string, note?: string, children: object[]}[]}}
 */
export const parseFreeMind = (xml, ParserClass = globalThis.DOMParser) => {
  const doc = new ParserClass().parseFromString(xml, 'text/xml');
  if (doc.getElementsByTagName('parsererror').length) {
    throw new Error('This file is not a valid FreeMind map.');
  }
  const map = doc.documentElement;
  if (!map || map.nodeName !== 'map')
    throw new Error('This file is not a FreeMind map.');
  const rootEl = directChildren(map, 'node')[0];
  if (!rootEl) throw new Error('The map is empty.');

  const convert = (el) => {
    const out = {
      name: nodeText(el).slice(0, 255) || 'Untitled',
      children: [],
    };
    const color =
      toHex(el.getAttribute('BACKGROUND_COLOR')) ??
      toHex(el.getAttribute('COLOR'));
    if (color) out.color = color;
    const note = nodeNote(el);
    if (note) out.note = note.slice(0, 10000);
    out.children = directChildren(el, 'node').map(convert);
    return out;
  };

  const title = nodeText(rootEl) || 'Mind map';
  const children = directChildren(rootEl, 'node').map(convert);
  return { title, nodes: children.length ? children : [convert(rootEl)] };
};

export const countNodes = (nodes) =>
  nodes.reduce((s, n) => s + 1 + countNodes(n.children ?? []), 0);
