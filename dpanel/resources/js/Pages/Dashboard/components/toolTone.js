// Icon chip colours per sidebar menu colour, shared by the dashboard tool cards.
const tone = {
    blue: 'bg-sky-500/10 text-sky-600 dark:text-sky-300',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
    violet: 'bg-violet-500/10 text-violet-600 dark:text-violet-300',
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    cyan: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300',
    indigo: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300',
    rose: 'bg-rose-500/10 text-rose-600 dark:text-rose-300',
    red: 'bg-red-500/10 text-red-600 dark:text-red-300',
    teal: 'bg-teal-500/10 text-teal-600 dark:text-teal-300',
    orange: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    pink: 'bg-pink-500/10 text-pink-600 dark:text-pink-300',
};

export const toneOf = (color) => tone[color] ?? tone.emerald;
