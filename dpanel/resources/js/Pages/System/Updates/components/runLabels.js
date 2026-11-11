/** Labels and colours for an update run's status and step (backlog U6). */
export function runLabels(t) {
    const statuses = {
        queued: t('panelUpdate.status.queued'),
        running: t('panelUpdate.status.running'),
        succeeded: t('panelUpdate.status.succeeded'),
        rolled_back: t('panelUpdate.status.rolledBack'),
        failed: t('panelUpdate.status.failed'),
        interrupted: t('panelUpdate.status.interrupted'),
    };
    const steps = {
        queued: t('panelUpdate.step.queued'),
        backup: t('panelUpdate.step.backup'),
        install: t('panelUpdate.step.install'),
        verify: t('panelUpdate.step.verify'),
        restore: t('panelUpdate.step.restore'),
        undo: t('panelUpdate.step.undo'),
        done: t('panelUpdate.step.done'),
    };
    const tones = {
        queued: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
        running: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
        succeeded: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        rolled_back: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        failed: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
        interrupted: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    };
    return {
        status: (run) => statuses[run?.status] || run?.status || '',
        step: (run) => steps[run?.step] || run?.step || '',
        tone: (run) => tones[run?.status] || 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        action: (run) => (run?.action === 'rollback' ? t('panelUpdate.action.rollback') : t('panelUpdate.action.update')),
        active: (run) => ['queued', 'running'].includes(run?.status),
    };
}

export const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
