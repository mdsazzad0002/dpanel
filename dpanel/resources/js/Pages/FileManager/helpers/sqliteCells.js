// Display helpers for cells as the SQLite viewer receives them from drust:
// null, numbers, strings, {$blob, hex} and cut text {$text, length}.

import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

export function cellText(value) {
    if (value === null) return 'NULL';
    if (typeof value === 'object') {
        if ('$blob' in value) return t('fileManager.cells.blob', { bytes: value.$blob });
        if ('$text' in value) return `${value.$text}…`;
    }
    return String(value);
}

export function cellTitle(value) {
    if (value && typeof value === 'object') {
        if ('$blob' in value) return value.hex ? `0x${value.hex}${value.$blob > value.hex.length / 2 ? '…' : ''}` : t('fileManager.cells.emptyBlob');
        if ('$text' in value) return t('fileManager.cells.textCut', { shown: value.$text.length, total: value.length });
    }
    return typeof value === 'string' && value.length > 80 ? value : '';
}

export function cellClass(value) {
    if (value === null) return 'italic text-slate-400';
    if (typeof value === 'object') return 'italic text-violet-600 dark:text-violet-400';
    if (typeof value === 'number') return 'text-right font-mono text-sky-700 dark:text-sky-400';
    return '';
}
