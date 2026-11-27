/**
 * A small window next to OpenQDA for the steps that happen on Tailscale's
 * website (sign-in, sharing this computer, accepting a share). Tailscale's
 * pages cannot be shown inside OpenQDA (they refuse to be framed), so the
 * guided dialog opens them in a popup and moves on by itself when the step
 * is done.
 *
 * The window is opened synchronously in the click handler (so the browser
 * does not block it) and pointed at its address once that is known.
 * @module
 */

const FEATURES = 'popup=yes,width=560,height=760,left=80,top=60';

export const openPopup = (name = 'openqda-tailscale') => {
  let win = window.open('', name, FEATURES);
  return {
    /** go to a page (re-opens the window if the person closed it) */
    go(url) {
      if (!win || win.closed) win = window.open(url, name, FEATURES);
      else win.location.href = url;
      win?.focus?.();
    },
    close() {
      if (win && !win.closed) win.close();
    },
    get closed() {
      return !win || win.closed;
    },
  };
};

/** A Tailscale share link copied to the clipboard, if the browser lets us read it. */
export const shareLinkFromClipboard = async () => {
  try {
    const text = (await navigator.clipboard?.readText?.()) ?? '';
    return isShareLink(text.trim()) ? text.trim() : null;
  } catch {
    return null;
  }
};

export const isShareLink = (text) =>
  /^https:\/\/login\.tailscale\.com\/\S{4,}$/.test(text ?? '');
