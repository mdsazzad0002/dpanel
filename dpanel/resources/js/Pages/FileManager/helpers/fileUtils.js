export function normalizePathValue(value) {
    return String(value || '').trim();
}

export function normalizedBasePath(basePath = '') {
    return String(basePath || '').trim().replace(/\\/g, '/').replace(/\/+$/, '');
}

export function resolveDisplayPath(basePath = '', relativePath = '') {
    const base = normalizedBasePath(basePath);
    const current = normalizePathValue(relativePath).replace(/^\/+/, '');

    if (!base) {
        return current;
    }

    return current ? `${base}/${current}` : base;
}

export function formatBytes(bytes) {
    if (bytes === 0 || bytes == null) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}
