<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

// The running version, the newest release, and the "Update now" choice (backlog U6).
const props = defineProps({
    current: { type: Object, required: true },
    repo: { type: String, default: '' },
    check: { type: Object, default: null },
    checking: { type: Boolean, default: false },
    checkError: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['refresh', 'update']);
const { t } = useI18n();

const choice = ref('');
const custom = ref('');
watch(() => props.check?.latest, (latest) => {
    if (!choice.value && latest) choice.value = latest;
}, { immediate: true });

const target = computed(() => (choice.value === 'custom' ? custom.value.trim() : choice.value));
const shortCommit = computed(() => (props.current.commit || '').slice(0, 7));

const confirmUpdate = () => {
    if (!target.value) return;
    if (window.confirm(t('panelUpdate.confirmUpdate', { ref: target.value }))) emit('update', target.value);
};
</script>

<template>
    <section class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ t('panelUpdate.running') }}</p>
                <p class="text-2xl font-semibold">{{ current.version || t('panelUpdate.unknown') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    <span v-if="current.ref">{{ current.ref }}</span>
                    <span v-if="shortCommit"> · {{ shortCommit }}</span>
                    <span v-if="repo"> · {{ repo }}</span>
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ t('panelUpdate.latest') }}</p>
                <p v-if="checking" class="text-sm text-slate-500">{{ t('panelUpdate.checking') }}</p>
                <template v-else-if="check">
                    <p class="text-2xl font-semibold">{{ check.latest || t('panelUpdate.noReleases') }}</p>
                    <p class="text-xs" :class="check.update_available ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500'">
                        {{ check.update_available ? t('panelUpdate.available', { count: check.ahead_by ?? '?' }) : t('panelUpdate.upToDate') }}
                    </p>
                </template>
                <p v-if="checkError" class="text-xs text-red-600 dark:text-red-400">{{ checkError }}</p>
                <button type="button" :disabled="checking" class="mt-1 text-xs font-semibold text-blue-600 disabled:opacity-50" @click="emit('refresh')">{{ t('panelUpdate.checkAgain') }}</button>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <label class="text-xs text-slate-500">{{ t('panelUpdate.target') }}
                <select v-model="choice" class="block rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="tag in check?.tags || []" :key="tag" :value="tag">{{ tag }}</option>
                    <option value="main">{{ t('panelUpdate.mainBranch') }}</option>
                    <option value="custom">{{ t('panelUpdate.otherRef') }}</option>
                </select>
            </label>
            <label v-if="choice === 'custom'" class="text-xs text-slate-500">{{ t('panelUpdate.ref') }}
                <input v-model="custom" type="text" maxlength="100" placeholder="v1.2.3" class="block rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <button type="button" :disabled="busy || !target" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" @click="confirmUpdate">{{ t('panelUpdate.updateNow') }}</button>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('panelUpdate.updateHelp') }}</p>
    </section>
</template>
