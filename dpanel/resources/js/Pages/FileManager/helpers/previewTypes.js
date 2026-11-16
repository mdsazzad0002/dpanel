// File types the browser can render natively in the preview drawer.
// SVG stays out on purpose: it opens in the code editor like other text.
const PREVIEW_EXTENSIONS = {
    image: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'avif'],
    video: ['mp4', 'webm', 'ogv', 'mov', 'm4v'],
    audio: ['mp3', 'wav', 'ogg', 'oga', 'opus', 'flac', 'aac', 'm4a'],
    pdf: ['pdf'],
};

export function previewKind(filename) {
    const name = String(filename || '').toLowerCase();
    if (!name.includes('.')) return null;
    const ext = name.split('.').pop();

    for (const [kind, extensions] of Object.entries(PREVIEW_EXTENSIONS)) {
        if (extensions.includes(ext)) return kind;
    }

    return null;
}
