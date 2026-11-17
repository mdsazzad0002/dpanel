<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { useAppearance } from './useAppearance.js';

const { t } = useI18n();
const { panelRoute, requestJson } = usePanelApi();
const { accent, cardClass, valueFont } = useAppearance();

const verifyText = ref('');
const verifyHash = ref('');
const verifyKey = ref('');
const verifying = ref(false);
const verifyError = ref('');
const verdict = ref(null);

const verify = async () => {
    if (!verifyHash.value.trim()) return;
    verifying.value = true;
    verifyError.value = '';
    verdict.value = null;
    try {
        verdict.value = await requestJson(panelRoute('tools.hash-forge.verify'), {
            body: { text: verifyText.value, hash: verifyHash.value, hmac_key: verifyKey.value },
        });
    } catch (e) {
        verifyError.value = e.message;
    } finally {
        verifying.value = false;
    }
};
</script>

<template>
    <form :class="['max-w-3xl space-y-4', cardClass]" @submit.prevent="verify">
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.verifyIntro') }}</p>
        <div>
            <label for="hf-vtext" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.verifyText') }}</label>
            <textarea id="hf-vtext" v-model="verifyText" rows="3" spellcheck="false" autocomplete="off" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]"></textarea>
        </div>
        <div>
            <label for="hf-vhash" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.verifyHash') }}</label>
            <input id="hf-vhash" v-model="verifyHash" type="text" spellcheck="false" autocomplete="off" placeholder="$2y$10$…  /  5f4dcc3b5aa765d61d8327deb882cf99" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />
        </div>
        <div>
            <label for="hf-vkey" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.hmacKey') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
            <input id="hf-vkey" v-model="verifyKey" type="text" spellcheck="false" autocomplete="off" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />
        </div>
        <button type="submit" :disabled="!verifyHash.trim() || verifying" :class="['inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-50', accent.solid]">
            <i class="bi" :class="verifying ? 'bi-arrow-repeat animate-spin' : 'bi-patch-check'"></i>
            {{ t('tools.hashForge.verify') }}
        </button>
        <p v-if="verifyError" class="text-sm text-red-600 dark:text-red-400">{{ verifyError }}</p>

        <div v-if="verdict" class="rounded-lg border px-4 py-3 text-sm"
            :class="verdict.match ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-red-300 bg-red-50 text-red-800 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200'">
            <div class="flex items-center gap-2 font-semibold">
                <i class="bi text-lg" :class="verdict.match ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>
                {{ verdict.match ? t('tools.hashForge.match', { algo: verdict.algorithm }) : t('tools.hashForge.noMatch') }}
            </div>
            <p v-if="verdict.unknown" class="mt-1">{{ t('tools.hashForge.unknownFormat') }}</p>
            <p v-else-if="!verdict.match && verdict.candidates.length" class="mt-1">{{ t('tools.hashForge.triedCandidates', { list: verdict.candidates.join(', ') }) }}</p>
        </div>
    </form>
</template>
