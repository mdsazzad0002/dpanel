// Address helpers shared by the reader, reply box, compose and contacts.

export const displayName = (value) => {
    if (!value) return '';
    const match = String(value).match(/^"?(.+?)"?\s*</);

    return match ? match[1].trim() : '';
};

export const emailOf = (value) => {
    const match = String(value || '').match(/<(.+?)>/);

    return (match ? match[1] : String(value || '')).trim();
};

export const nameOrEmail = (value) => displayName(value) || emailOf(value) || 'Unknown';

export const initialsOf = (value) => {
    const parts = nameOrEmail(value).split(/[\s@._-]+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();

    return (parts[0] || '?').slice(0, 2).toUpperCase();
};

const avatarColors = ['bg-blue-600', 'bg-emerald-600', 'bg-violet-600', 'bg-amber-600', 'bg-rose-600', 'bg-cyan-600', 'bg-indigo-600', 'bg-teal-600', 'bg-orange-600', 'bg-fuchsia-600'];
export const avatarColorOf = (value) => {
    const key = emailOf(value) || String(value || '');
    let hash = 0;
    for (let i = 0; i < key.length; i += 1) hash = (hash * 31 + key.charCodeAt(i)) >>> 0;

    return avatarColors[hash % avatarColors.length];
};

// Splits "A <a@x>, "Doe, J" <j@x>" on commas outside quotes.
export const splitAddresses = (list) => {
    const parts = [];
    let current = '';
    let quoted = false;
    for (const char of String(list || '')) {
        if (char === '"') quoted = !quoted;
        if ((char === ',' || char === ';') && !quoted) {
            if (current.trim()) parts.push(current.trim());
            current = '';
        }
        else current += char;
    }
    if (current.trim()) parts.push(current.trim());

    return parts;
};

export const sameAddress = (a, b) => emailOf(a).toLowerCase() !== '' && emailOf(a).toLowerCase() === emailOf(b).toLowerCase();

export const withoutAddress = (list, self) => splitAddresses(list).filter((item) => !sameAddress(item, self)).join(', ');

export const formatAddress = (name, email) => (name ? `${/[",;<>]/.test(name) ? `"${name.replace(/"/g, '')}"` : name} <${email}>` : email);
