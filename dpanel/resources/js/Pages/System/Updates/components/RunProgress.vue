<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { runLabels } from './runLabels';

// One run's steps and log, kept up to date by the page (backlog U6).
const props = defineProps({
    run: { type: Object, required: true },
    log: { type: String, default: '' },
    unreachable: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
const labels = runLabels(t);

const order = computed(() => (props.run.action === 'rollback' ? ['backup', 'restore', 'done'] : ['backup', 'install', 'verify', 'done']));
const reached = (step) => {
    const at = order.value.indexOf(props.run.step);
    return props.run.step === 'undo' || (at >= 0 && order.value.indexOf(step) <= at);
};

const box = ref(null);
watch(() => props.log, async () => {
    await nextTick();
    if (box.value) box.value.scrollTop = box.value.scrollHeight;
});
const reload = () => window.location.reload();
const finishedUpdate = computed(() => props.run.status === 'succeeded' || props.run.status === 'rolled_back');
</script>

<template>
    <section class="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold">{{ labels.action(run) }} {{ run.target_ref }}</h2>
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="labels.tone(run)">{{ labels.status(run) }}</span>
            <span class="text-xs text-slate-500">{{ run.run_id }}</span>
            <button v-if="!labels.active(run)" type="button" class="ml-auto text-xs font-semibold text-slate-500" @click="emit('close')">{{ t('common.close') }}</button>
        </div>

        <ol class="flex flex-wrap gap-2 text-xs">
            <li v-for="step in order" :key="step" class="rounded-lg px-2 py-1" :class="reached(step) ? 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300' : 'bg-slate-50 text-slate-400 dark:bg-slate-800'">
                {{ labels.step({ step }) }}
            </li>
            <li v-if="run.step === 'undo'" class="rounded-lg bg-amber-50 px-2 py-1 text-amber-700 dark:bg-amber-950 dark:text-amber-300">{{ labels.step(run) }}</li>
        </ol>

        <p v-if="unreachable && labels.active(run)" class="text-xs text-amber-600 dark:text-amber-400">{{ t('panelUpdate.reconnecting') }}</p>
        <p v-if="run.message" class="text-sm" :class="run.status === 'succeeded' ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-700 dark:text-slate-200'">{{ run.message }}</p>
        <p v-if="finishedUpdate" class="text-xs text-slate-500">
            {{ t('panelUpdate.reloadHint') }}
            <button type="button" class="font-semibold text-blue-600" @click="reload">{{ t('panelUpdate.reload') }}</button>
        </p>

        <pre ref="box" class="max-h-96 overflow-auto rounded-xl bg-slate-950 p-3 text-xs leading-5 text-slate-100">{{ log || t('panelUpdate.noLog') }}</pre>
    </section>
</template>
