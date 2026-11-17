<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Docker is an optional add-on: the main installer never installs it, so this
// explains what it costs and how to add it from the server's shell.
defineProps({
    // 'missing' when Docker is not installed, 'stopped' when its service is down.
    state: { type: String, default: 'missing' },
    refreshing: { type: Boolean, default: false },
});

defineEmits(['refresh']);

const docsUrl = 'https://github.com/mdsazzad0002/dpanel/blob/main/docs/docker.md';
const copied = ref('');

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = text;
        setTimeout(() => { if (copied.value === text) copied.value = ''; }, 2000);
    } catch {
        copied.value = '';
    }
};

const costs = [
    { get label() { return t('docker.setup.costs.ram'); }, value: '~90 MB', note: 'dockerd + containerd' },
    { get label() { return t('docker.setup.costs.cpu'); }, value: '~0%', get note() { return t('docker.setup.costs.cpuNote'); } },
    { get label() { return t('docker.setup.costs.disk'); }, value: '~350 MB', get note() { return t('docker.setup.costs.diskNote'); } },
    { get label() { return t('docker.setup.costs.logs'); }, get value() { return t('docker.setup.costs.logsValue'); }, get note() { return t('docker.setup.costs.logsNote'); } },
];

const checks = computed(() => [
    t('docker.setup.checks.ram'),
    t('docker.setup.checks.os'),
    t('docker.setup.checks.hub'),
]);
</script>

<template>
    <section v-if="state === 'stopped'" class="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-900 dark:bg-amber-950/40">
        <h2 class="text-base font-semibold text-amber-900 dark:text-amber-100">{{ t('docker.setup.stoppedTitle') }}</h2>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">{{ t('docker.setup.stoppedBody') }}</p>
        <div class="mt-3 space-y-2">
            <div v-for="cmd in ['sudo systemctl start docker', 'sudo dpanel docker status']" :key="cmd" class="flex items-center gap-2 rounded-md bg-slate-950 px-3 py-2 font-mono text-xs text-slate-100">
                <span class="flex-1 select-all">{{ cmd }}</span>
                <button type="button" class="rounded border border-slate-600 px-2 py-0.5 text-[11px] hover:bg-slate-800" @click="copy(cmd)">{{ copied === cmd ? t('docker.shared.copied') : t('docker.shared.copy') }}</button>
            </div>
        </div>
        <button type="button" :disabled="refreshing" class="mt-4 rounded-md border border-amber-300 px-3 py-2 text-xs hover:bg-amber-100 disabled:opacity-60 dark:border-amber-800 dark:hover:bg-amber-900" @click="$emit('refresh')">
            {{ refreshing ? t('docker.shared.checking') : t('docker.setup.checkAgain') }}
        </button>
    </section>

    <section v-else class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('docker.setup.badge') }}</span>
                <h2 class="mt-2 text-lg font-semibold">{{ t('docker.setup.missingTitle') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    {{ t('docker.setup.missingBody') }}
                </p>
            </div>
            <a :href="docsUrl" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ t('docker.setup.readGuide') }}</a>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div v-for="cost in costs" :key="cost.label" class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ cost.label }}</div>
                <div class="mt-1 text-lg font-semibold">{{ cost.value }}</div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400">{{ cost.note }}</div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold">{{ t('docker.setup.installTitle') }}</h3>
                <ol class="mt-2 space-y-3 text-sm">
                    <li>
                        <span class="text-slate-500 dark:text-slate-400">{{ t('docker.setup.step1') }}</span>
                        <div class="mt-1 flex items-center gap-2 rounded-md bg-slate-950 px-3 py-2 font-mono text-xs text-slate-100">
                            <span class="flex-1 select-all">sudo dpanel docker</span>
                            <button type="button" class="rounded border border-slate-600 px-2 py-0.5 text-[11px] hover:bg-slate-800" @click="copy('sudo dpanel docker')">{{ copied === 'sudo dpanel docker' ? t('docker.shared.copied') : t('docker.shared.copy') }}</button>
                        </div>
                    </li>
                    <li class="text-slate-500 dark:text-slate-400">{{ t('docker.setup.step2') }}</li>
                    <li class="text-slate-500 dark:text-slate-400"><i18n-t keypath="docker.setup.step3" scope="global"><template #button><strong class="text-slate-700 dark:text-slate-200">{{ t('docker.setup.checkAgain') }}</strong></template></i18n-t></li>
                </ol>
                <button type="button" :disabled="refreshing" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40" @click="$emit('refresh')">
                    {{ refreshing ? t('docker.shared.checking') : t('docker.setup.checkAgain') }}
                </button>
            </div>
            <div>
                <h3 class="text-sm font-semibold">{{ t('docker.setup.checksTitle') }}</h3>
                <ul class="mt-2 space-y-1.5 text-sm text-slate-500 dark:text-slate-400">
                    <li v-for="check in checks" :key="check" class="flex gap-2"><span class="text-emerald-600">✓</span>{{ check }}</li>
                </ul>
                <h3 class="mt-4 text-sm font-semibold">{{ t('docker.setup.safeTitle') }}</h3>
                <ul class="mt-2 space-y-1.5 text-sm text-slate-500 dark:text-slate-400">
                    <li class="flex gap-2"><span class="text-emerald-600">✓</span>{{ t('docker.setup.safe1') }}</li>
                    <li class="flex gap-2"><span class="text-emerald-600">✓</span>{{ t('docker.setup.safe2') }}</li>
                    <li class="flex gap-2"><span class="text-emerald-600">✓</span><i18n-t keypath="docker.setup.safe3" scope="global"><template #cmd><code class="text-xs">sudo dpanel docker remove</code></template></i18n-t></li>
                </ul>
            </div>
        </div>
    </section>
</template>
