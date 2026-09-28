const folderKinds = [
    { key: 'inbox', match: (name) => name === 'inbox', label: 'Inbox', icon: 'bi-inbox' },
    { key: 'sent', match: (name) => name.includes('sent'), label: 'Sent', icon: 'bi-send' },
    { key: 'drafts', match: (name) => name.includes('draft'), label: 'Drafts', icon: 'bi-file-earmark' },
    { key: 'spam', match: (name) => name.includes('spam') || name.includes('junk'), label: 'Spam', icon: 'bi-exclamation-octagon' },
    { key: 'trash', match: (name) => name.includes('trash') || name.includes('bin') || name.includes('deleted'), label: 'Trash', icon: 'bi-trash3' },
    { key: 'outbox', match: (name) => name === 'outbox', label: 'Outbox', icon: 'bi-box-arrow-up-right' },
    { key: 'all', match: (name) => name === 'all mail', label: 'All mail', icon: 'bi-envelope' },
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
