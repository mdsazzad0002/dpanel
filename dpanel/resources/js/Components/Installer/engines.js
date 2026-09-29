// Shared by the installer pickers: labels and badge colours per database engine.
export const ENGINE_LABELS = {
    mariadb: 'MariaDB',
    postgresql: 'PostgreSQL',
};

export const engineLabel = (engine) => ENGINE_LABELS[engine] || ENGINE_LABELS.mariadb;

export const engineBadgeClass = (engine) => (engine === 'postgresql'
    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
    : 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300');

// Selected/idle card styles, in each installer's accent colour.
const ACCENTS = {
    red: 'border-red-400 bg-red-50/60 dark:border-red-500 dark:bg-red-500/10',
    orange: 'border-orange-400 bg-orange-50/60 dark:border-orange-500 dark:bg-orange-500/10',
    blue: 'border-blue-400 bg-blue-50/60 dark:border-blue-500 dark:bg-blue-500/10',
};

export const optionCardClass = (active, accent = 'red') => (active
    ? ACCENTS[accent] || ACCENTS.red
    : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600');
