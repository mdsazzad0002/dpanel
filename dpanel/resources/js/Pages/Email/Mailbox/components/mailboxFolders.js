import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

// `label` is a getter so it follows the current language.
const folderKinds = [
    { key: 'inbox', match: (name) => name === 'inbox', get label() { return t('email.mailbox.folders.inbox'); }, icon: 'bi-inbox' },
    { key: 'sent', match: (name) => name.includes('sent'), get label() { return t('email.mailbox.folders.sent'); }, icon: 'bi-send' },
    { key: 'drafts', match: (name) => name.includes('draft'), get label() { return t('email.mailbox.folders.drafts'); }, icon: 'bi-file-earmark' },
    { key: 'spam', match: (name) => name.includes('spam') || name.includes('junk'), get label() { return t('email.mailbox.folders.spam'); }, icon: 'bi-exclamation-octagon' },
    { key: 'trash', match: (name) => name.includes('trash') || name.includes('bin') || name.includes('deleted'), get label() { return t('email.mailbox.folders.trash'); }, icon: 'bi-trash3' },
    { key: 'outbox', match: (name) => name === 'outbox', get label() { return t('email.mailbox.folders.outbox'); }, icon: 'bi-box-arrow-up-right' },
    { key: 'all', match: (name) => name === 'all mail', get label() { return t('email.mailbox.folders.all'); }, icon: 'bi-envelope' },
];

const findKind = (name) => {
    const normalized = String(name || '').trim().toLowerCase();

    return folderKinds.find((kind) => kind.match(normalized)) || null;
};

export const folderLabel = (name) => findKind(name)?.label || name;

// Folders whose messages were written by this mailbox, so the other party is the recipient.
export const isOutgoingFolder = (name) => ['sent', 'drafts', 'outbox'].includes(findKind(name)?.key);

export const folderIcon = (name) => findKind(name)?.icon || 'bi-folder2';

export const formatBytes = (bytes) => {
    const value = Number(bytes) || 0;
    if (value < 1024 * 1024) return `${Math.max(0, Math.round(value / 1024))} KB`;
    if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`;

    return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`;
};

export const initialOf = (email) => String(email || 'M').trim().slice(0, 1).toUpperCase() || 'M';
