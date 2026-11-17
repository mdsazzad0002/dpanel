<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { useAppearance } from './useAppearance.js';

const { t } = useI18n();
const { panelRoute, requestJson } = usePanelApi();
const { accent, cardClass, valueFont } = useAppearance();

const props = defineProps({
    digests: { type: Array, default: () => [] },
    passwords: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ text: 1048576 }) },
});

const text = ref('');
const COMMON = ['md5', 'sha1', 'sha256', 'sha512'];
const selectedDigests = ref(COMMON.filter((id) => props.digests.some((d) => d.id === id)));
const selectedPasswords = ref(props.passwords.some((p) => p.id === 'bcrypt') ? ['bcrypt'] : []);
const bcryptCost = ref(10);
const hmacKey = ref('');
const showHmac = ref(false);
const uppercase = ref(false);
const generating = ref(false);
const error = ref('');
const results = ref([]);
const bcryptTruncated = ref(false);
const copied = ref('');

const groups = computed(() => {
    const order = ['classic', 'sha2', 'sha3', 'other', 'checksum'];
    return order
        .map((group) => ({ group, label: t(`tools.hashForge.group.${group}`), items: props.digests.filter((d) => d.group === group) }))
        .filter((g) => g.items.length);
});

const toggle = (list, id) => {
    list.value = list.value.includes(id) ? list.value.filter((x) => x !== id) : [...list.value, id];
};
const preset = (name) => {
    if (name === 'common') selectedDigests.value = COMMON.filter((id) => props.digests.some((d) => d.id === id));
    if (name === 'all') selectedDigests.value = props.digests.map((d) => d.id);
    if (name === 'none') selectedDigests.value = [];
};

const byteLength = computed(() => new TextEncoder().encode(text.value).length);
const canGenerate = computed(() => (selectedDigests.value.length || selectedPasswords.value.length) && byteLength.value <= props.limits.text);

const generate = async () => {
    if (!canGenerate.value) return;
    generating.value = true;
    error.value = '';
    try {
        const data = await requestJson(panelRoute('tools.hash-forge.generate'), {
            body: {
                text: text.value,
                digests: selectedDigests.value,
                passwords: selectedPasswords.value,
                bcrypt_cost: bcryptCost.value,
                hmac_key: showHmac.value ? hmacKey.value : '',
                uppercase: uppercase.value,
            },
        });
        results.value = data.results || [];
        bcryptTruncated.value = !!data.bcrypt_truncated;
    } catch (e) {
        error.value = e.message;
    } finally {
        generating.value = false;
    }
};

// Digests follow the text as you type; salted password hashes wait for the button.
let timer = null;
watch([text, selectedDigests, uppercase, hmacKey, showHmac], () => {
    if (selectedPasswords.value.length) return;
    clearTimeout(timer);
    timer = setTimeout(generate, 350);
}, { deep: true });

const copy = async (value, id) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = id;
        setTimeout(() => { if (copied.value === id) copied.value = ''; }, 1500);
    } catch { /* clipboard blocked on plain http */ }
};
const copyAll = () => copy(results.value.map((r) => `${r.label}: ${r.value}`).join('\n'), '__all');
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <form :class="['space-y-4', cardClass]" @submit.prevent="generate">
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <label for="hf-text" class="text-sm font-medium">{{ t('tools.hashForge.input') }}</label>
                    <span class="text-xs text-slate-400">{{ t('tools.hashForge.bytes', { n: byteLength }) }}</span>
                </div>
                <textarea id="hf-text" v-model="text" rows="5" spellcheck="false" autocomplete="off"
                    :placeholder="t('tools.hashForge.inputPlaceholder')"
                    :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]"></textarea>
            </div>

            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm font-medium">{{ t('tools.hashForge.digests') }}</span>
                    <div class="flex gap-1 text-xs">
                        <button v-for="p in ['common', 'all', 'none']" :key="p" type="button" class="rounded-md border border-slate-200 px-2 py-0.5 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700" @click="preset(p)">
                            {{ t(`tools.hashForge.preset.${p}`) }}
                        </button>
                    </div>
                </div>
                <div class="space-y-2">
                    <div v-for="g in groups" :key="g.group">
                        <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ g.label }}</div>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="d in g.items" :key="d.id" type="button" :aria-pressed="selectedDigests.includes(d.id)"
                                class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                :class="selectedDigests.includes(d.id) ? accent.chip : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                                @click="toggle(selectedDigests, d.id)">{{ d.label }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <span class="mb-2 block text-sm font-medium">{{ t('tools.hashForge.passwordHashes') }}</span>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="p in passwords" :key="p.id" type="button" :aria-pressed="selectedPasswords.includes(p.id)"
                        class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                        :class="selectedPasswords.includes(p.id) ? 'border-violet-500 bg-violet-50 text-violet-700 dark:border-violet-400 dark:bg-violet-500/15 dark:text-violet-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                        @click="toggle(selectedPasswords, p.id)"><i class="bi bi-shield-lock mr-0.5"></i>{{ p.label }}</button>
                </div>
                <div v-if="selectedPasswords.includes('bcrypt')" class="mt-3 flex items-center gap-3 text-sm">
                    <label for="hf-cost" class="shrink-0 text-slate-600 dark:text-slate-300">{{ t('tools.hashForge.bcryptCost') }}</label>
                    <input id="hf-cost" v-model.number="bcryptCost" type="range" min="4" max="14" class="flex-1 accent-violet-600" />
                    <span class="w-6 text-right font-mono">{{ bcryptCost }}</span>
                </div>
                <p v-if="selectedPasswords.length" class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.saltedNote') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input v-model="uppercase" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" :class="accent.ring" />
                    {{ t('tools.hashForge.uppercase') }}
                </label>
                <label class="inline-flex items-center gap-2">
                    <input v-model="showHmac" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" :class="accent.ring" />
                    {{ t('tools.hashForge.useHmac') }}
                </label>
            </div>
            <input v-if="showHmac" v-model="hmacKey" type="text" autocomplete="off" spellcheck="false"
                :placeholder="t('tools.hashForge.hmacKey')"
                :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />

            <button type="submit" :disabled="!canGenerate || generating" :class="['inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-50', accent.solid]">
                <i class="bi" :class="generating ? 'bi-arrow-repeat animate-spin' : 'bi-lightning-charge'"></i>
                {{ t('tools.hashForge.generate') }}
            </button>
            <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        </form>

        <section :class="cardClass">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ t('tools.hashForge.results') }}</h2>
                <button v-if="results.length" type="button" class="text-xs hover:underline" :class="accent.text" @click="copyAll">
                    <i class="bi" :class="copied === '__all' ? 'bi-check2' : 'bi-clipboard'"></i>
                    {{ copied === '__all' ? t('tools.hashForge.copied') : t('tools.hashForge.copyAll') }}
                </button>
            </div>
            <p v-if="bcryptTruncated" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                <i class="bi bi-exclamation-triangle mr-1"></i>{{ t('tools.hashForge.bcryptTruncated') }}
            </p>
            <div v-if="!results.length" class="py-10 text-center text-sm text-slate-400">
                <i class="bi bi-hash text-3xl"></i>
                <p class="mt-2">{{ t('tools.hashForge.empty') }}</p>
            </div>
            <ul v-else class="divide-y divide-slate-100 dark:divide-slate-700">
                <li v-for="r in results" :key="r.id" class="py-2.5">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold" :class="r.kind === 'password' ? 'text-violet-700 dark:text-violet-300' : 'text-slate-700 dark:text-slate-200'">
                            {{ r.label }}
                            <span v-if="r.bits" class="ml-1 font-normal text-slate-400">{{ r.bits }}-bit</span>
                        </span>
                        <button type="button" class="rounded px-1.5 py-0.5 text-xs text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-700 dark:hover:text-white" :title="t('tools.hashForge.copy')" @click="copy(r.value, r.id)">
                            <i class="bi" :class="copied === r.id ? 'bi-check2 text-emerald-600' : 'bi-clipboard'"></i>
                        </button>
                    </div>
                    <code :class="['block break-all rounded-md bg-slate-50 px-2 py-1.5 text-xs text-slate-800 select-all dark:bg-slate-900 dark:text-slate-100', valueFont]">{{ r.value }}</code>
                </li>
            </ul>
        </section>
    </div>
</template>
