/**
 * Download helpers for the mind map (no external dependency).
 * @module
 */

export const downloadBlob = (blob, filename) => {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};

export const safeFilename = (name, ext) =>
  `${
    String(name || 'mindmap')
      .replace(/[^\p{L}\p{N}_-]+/gu, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 60) || 'mindmap'
  }.${ext}`;

/**
 * Serialises the rendered map (whole content, not just the visible part).
 * @param svg {SVGSVGElement}
 */
export const svgToString = (svg, padding = 24) => {
  const viewport = svg.querySelector('g');
  const bbox = viewport.getBBox();
  const width = Math.ceil(bbox.width + padding * 2);
  const height = Math.ceil(bbox.height + padding * 2);
  const clone = svg.cloneNode(true);
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  clone.setAttribute('width', width);
  clone.setAttribute('height', height);
  clone.setAttribute('viewBox', `0 0 ${width} ${height}`);
  clone.removeAttribute('class');
  const g = clone.querySelector('g');
  g.setAttribute(
    'transform',
    `translate(${padding - bbox.x},${padding - bbox.y})`
  );
  const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
  bg.setAttribute('width', '100%');
  bg.setAttribute('height', '100%');
  bg.setAttribute('fill', '#ffffff');
  clone.insertBefore(bg, clone.firstChild);
  return {
    markup: new XMLSerializer().serializeToString(clone),
    width,
    height,
  };
};

export const downloadSvg = (svg, name) => {
  const { markup } = svgToString(svg);
  downloadBlob(
    new Blob([markup], { type: 'image/svg+xml;charset=utf-8' }),
    safeFilename(name, 'svg')
  );
};

export const downloadPng = (svg, name, scale = 2) =>
  new Promise((resolve, reject) => {
    const { markup, width, height } = svgToString(svg);
    const img = new Image();
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = width * scale;
      canvas.height = height * scale;
      const ctx = canvas.getContext('2d');
      ctx.scale(scale, scale);
      ctx.drawImage(img, 0, 0);
      canvas.toBlob((blob) => {
        if (!blob) return reject(new Error('Could not render PNG.'));
        downloadBlob(blob, safeFilename(name, 'png'));
        resolve();
      }, 'image/png');
    };
    img.onerror = () => reject(new Error('Could not render PNG.'));
    img.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(markup)}`;
  });
