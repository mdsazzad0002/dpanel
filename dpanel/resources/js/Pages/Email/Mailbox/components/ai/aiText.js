// AI answers are plain text; the editor holds HTML.
export const escapeHtml = (value) => String(value || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

export const textToHtml = (text) => escapeHtml(text).replace(/\n/g, '<br>');
