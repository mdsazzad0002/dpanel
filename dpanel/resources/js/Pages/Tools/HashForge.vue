<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAppearance } from './HashForge/useAppearance.js';
import AppearancePanel from './HashForge/AppearancePanel.vue';
import GenerateTab from './HashForge/GenerateTab.vue';
import VerifyTab from './HashForge/VerifyTab.vue';
import CrackTab from './HashForge/CrackTab.vue';
import TransformTab from './HashForge/TransformTab.vue';

const { t } = useI18n();
const { accent } = useAppearance();

// Backend catalogue (algorithms, wordlists, charsets, limits), handed to the
// tabs that need it. The tabs themselves hold their own form state.
const props = defineProps({
    digests: { type: Array, default: () => [] },
    passwords: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ text: 1048576, password: 4096, bruteCap: 500000 }) },
    wordlistSets: { type: Array, default: () => [] },
    charsets: { type: Array, default: () => [] },
});

const tab = ref('generate');
const tabs = computed(() => [
    { id: 'generate', label: t('tools.hashForge.tabGenerate'), icon: 'bi-hash' },
    { id: 'transform', label: t('tools.hashForge.tabTransform'), icon: 'bi-magic' },
    { id: 'verify', label: t('tools.hashForge.tabVerify'), icon: 'bi-patch-check' },
    { id: 'crack', label: t('tools.hashForge.tabCrack'), icon: 'bi-unlock' },
]);

const showAppearance = ref(false);
</script>

<template>
    <Head :title="t('tools.hashForge.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl text-lg text-white" :class="accent.dot">
                    <i class="bi bi-hash"></i>
                </span>
                <div>
                    <h1 class="text-lg font-semibold">{{ t('tools.hashForge.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.subtitle') }}</p>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Top bar: tabs + appearance toggle -->
            <div class="flex flex-wrap items-center gap-2">
                <nav class="flex flex-1 flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-800" role="tablist">
                    <button v-for="item in tabs" :key="item.id" type="button" role="tab" :aria-selected="tab === item.id"
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition"
                        :class="tab === item.id ? accent.solid : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700'"
                        @click="tab = item.id">
                        <i class="bi" :class="item.icon"></i>{{ item.label }}
                    </button>
                </nav>

                <button type="button" class="flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700/50"
                    :class="showAppearance ? 'bg-slate-50 dark:bg-slate-700/50' : ''" @click="showAppearance = !showAppearance">
                    <i class="bi bi-sliders"></i><span class="hidden sm:inline">{{ t('tools.hashForge.appearance') }}</span>
                    <i class="bi" :class="showAppearance ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                </button>
            </div>

            <div v-show="showAppearance" class="sm:max-w-sm">
                <AppearancePanel />
            </div>

            <!-- Active tab -->
            <div class="min-w-0">
                <GenerateTab v-if="tab === 'generate'" :digests="digests" :passwords="passwords" :limits="limits" />
                <TransformTab v-else-if="tab === 'transform'" />
                <VerifyTab v-else-if="tab === 'verify'" />
                <CrackTab v-else :digests="digests" :wordlist-sets="wordlistSets" :charsets="charsets" :limits="limits" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
