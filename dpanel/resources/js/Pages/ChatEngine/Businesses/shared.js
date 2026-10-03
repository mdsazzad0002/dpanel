import { usePage } from '@inertiajs/vue3';

// Same token-aware route() wrapper every Chat Engine page inlines, shared by
// the business workspace's Apps/Manage components.
export const usePanelRoute = () => {
    const panelToken = usePage().props.panel?.token;

    return (name, params = {}) => (
        panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
    );
};

export const APP_TYPES = {
    telegram: { label: 'Telegram', icon: 'bi-telegram', tint: 'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300' },
    facebook: { label: 'Facebook', icon: 'bi-facebook', tint: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300' },
    whatsapp: { label: 'WhatsApp', icon: 'bi-whatsapp', tint: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300' },
    instagram: { label: 'Instagram', icon: 'bi-instagram', tint: 'bg-pink-100 text-pink-600 dark:bg-pink-900/40 dark:text-pink-300' },
    slack: { label: 'Slack', icon: 'bi-slack', tint: 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300' },
    website: { label: 'Website', icon: 'bi-window', tint: 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300' },
};

export const appType = (type) => APP_TYPES[type] || { label: type, icon: 'bi-broadcast', tint: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' };

// "2026-10-04 13:22:10" → "3h ago" (falls back to the raw value).
export const timeAgo = (value) => {
    if (!value) return null;
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    const seconds = Math.round((Date.now() - date.getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const units = [[60, 'm'], [60, 'h'], [24, 'd'], [30, 'mo'], [12, 'y']];
    let n = seconds;
    let label = 's';
    for (const [size, unit] of units) {
        if (n < size) break;
        n = Math.floor(n / size);
        label = unit;
    }
    return `${n}${label} ago`;
};
