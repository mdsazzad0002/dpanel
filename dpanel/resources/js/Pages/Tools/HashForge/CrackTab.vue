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
    wordlistSets: { type: Array, default: () => [] },
    charsets: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ bruteCap: 500000 }) },
});

const groups = computed(() => {
    const order = ['classic', 'sha2', 'sha3', 'other', 'checksum'];
    return order
        .map((group) => ({ group, items: props.digests.filter((d) => d.group === group) }))
        .filter((g) => g.items.length);
});
const toggle = (list, id) => {
    list.value = list.value.includes(id) ? list.value.filter((x) => x !== id) : [...list.value, id];
};

const crackHash = ref('');
const crackKey = ref('');
const selectedSets = ref(props.wordlistSets.some((s) => s.id === 'common') ? ['common'] : []);
const crackAlgos = ref([]);
const customWords = ref('');
const brute = ref(false);
const bruteCharset = ref(['digits']);
const bruteCustom = ref('');
const bruteMin = ref(1);
const bruteMax = ref(4);
const identifying = ref(false);
const cracking = ref(false);
const crackError = ref('');
const identity = ref(null);
const crackResult = ref(null);

const brutePoolSize = computed(() => {
    const chars = new Set(bruteCustom.value);
    const widths = { digits: 10, lower: 26, upper: 26, symbols: 14 };
    for (const id of bruteCharset.value) {
        for (let i = 0; i < (widths[id] || 0); i++) chars.add(id + i);
    }
    return chars.size;
});
const bruteCombos = computed(() => {
    const pool = brutePoolSize.value;
    let total = 0;
    for (let len = Number(bruteMin.value); len <= Number(bruteMax.value); len++) {
        total += pool ** len;
        if (total > 1e12) return Infinity;
    }
    return total;
});
const bruteTooBig = computed(() => brute.value && bruteCombos.value > props.limits.bruteCap);
const hasSource = computed(() => selectedSets.value.length || customWords.value.trim() || (brute.value && brutePoolSize.value > 0));

const identify = async () => {
    identity.value = null;
    crackResult.value = null;
    if (!crackHash.value.trim()) return;
    identifying.value = true;
    crackError.value = '';
    try {
        identity.value = await requestJson(panelRoute('tools.hash-forge.identify'), { body: { hash: crackHash.value } });
    } catch (e) {
        crackError.value = e.message;
    } finally {
        identifying.value = false;
    }
};

const crack = async () => {
    if (!crackHash.value.trim() || !hasSource.value || bruteTooBig.value) return;
    cracking.value = true;
    crackError.value = '';
    crackResult.value = null;
    try {
        crackResult.value = await requestJson(panelRoute('tools.hash-forge.crack'), {
            body: {
                hash: crackHash.value,
                wordlist: customWords.value,
                hmac_key: crackKey.value,
                sets: selectedSets.value,
                algos: crackAlgos.value,
                brute: brute.value,
                brute_charset: bruteCharset.value,
                brute_custom: bruteCustom.value,
                brute_min: Number(bruteMin.value),
                brute_max: Number(bruteMax.value),
            },
        });
    } catch (e) {
        crackError.value = e.message;
    } finally {
        cracking.value = false;
    }
};

// Password / username strength auditor (offline on the server).
const auditPassword = ref('');
const auditUsername = ref('');
const audit = ref(null);
let auditTimer = null;
watch([auditPassword, auditUsername], () => {
    clearTimeout(auditTimer);
    if (!auditPassword.value) { audit.value = null; return; }
    auditTimer = setTimeout(async () => {
        try {
            audit.value = await requestJson(panelRoute('tools.hash-forge.audit'), { body: { password: auditPassword.value, username: auditUsername.value } });
        } catch { /* keep the previous verdict */ }
    }, 300);
});
const strengthColor = (score) => ['bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-lime-500', 'bg-emerald-500'][score] || 'bg-slate-300';
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <form :class="['space-y-3', cardClass]" @submit.prevent="crack">
            <div>
                <label for="hf-crack" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.crackHash') }}</label>
                <input id="hf-crack" v-model="crackHash" type="text" spellcheck="false" autocomplete="off" placeholder="5f4dcc3b5aa765d61d8327deb882cf99" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" @input="identity = null; crackResult = null" />
            </div>
            <div class="flex items-center gap-2">
                <button type="button" :disabled="!crackHash.trim() || identifying" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700" @click="identify">
                    <i class="bi" :class="identifying ? 'bi-arrow-repeat animate-spin' : 'bi-search'"></i>{{ t('tools.hashForge.identify') }}
                </button>
                <span v-if="identity" class="text-xs text-slate-500 dark:text-slate-400">
                    <template v-if="identity.format">{{ t('tools.hashForge.looksLike', { name: identity.format }) }}</template>
                    <template v-else-if="identity.candidates.length">{{ t('tools.hashForge.maybe', { list: identity.candidates.join(', ') }) }}</template>
                    <template v-else>{{ t('tools.hashForge.unknownFormat') }}</template>
                </span>
            </div>

            <div>
                <span class="mb-1.5 block text-sm font-medium">{{ t('tools.hashForge.wordlistSets') }}</span>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="s in wordlistSets" :key="s.id" type="button" :aria-pressed="selectedSets.includes(s.id)"
                        class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                        :class="selectedSets.includes(s.id) ? accent.chip : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                        @click="toggle(selectedSets, s.id)">
                        {{ t(`tools.hashForge.set.${s.id}`) }}
                        <span class="ml-0.5 font-normal text-slate-400">{{ s.count.toLocaleString() }}</span>
                    </button>
                </div>
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-sm font-medium">{{ t('tools.hashForge.limitAlgos') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></span>
                    <button v-if="crackAlgos.length" type="button" class="text-xs hover:underline" :class="accent.text" @click="crackAlgos = []">{{ t('tools.hashForge.preset.none') }}</button>
                </div>
                <p class="mb-1.5 text-xs text-slate-400">{{ t('tools.hashForge.limitAlgosHint') }}</p>
                <div class="space-y-1.5">
                    <div v-for="g in groups" :key="g.group" class="flex flex-wrap gap-1.5">
                        <button v-for="d in g.items" :key="d.id" type="button" :aria-pressed="crackAlgos.includes(d.id)"
                            class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                            :class="crackAlgos.includes(d.id) ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-400 dark:bg-sky-500/15 dark:text-sky-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                            @click="toggle(crackAlgos, d.id)">{{ d.label }}</button>
                    </div>
                </div>
            </div>

            <div>
                <label for="hf-words" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.customWordlist') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                <textarea id="hf-words" v-model="customWords" rows="3" spellcheck="false" autocomplete="off" :placeholder="t('tools.hashForge.wordlistPlaceholder')" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]"></textarea>
            </div>

            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <label class="inline-flex items-center gap-2 text-sm font-medium">
                    <input v-model="brute" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" :class="accent.ring" />
                    {{ t('tools.hashForge.bruteForce') }}
                </label>
                <div v-if="brute" class="mt-3 space-y-3">
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="c in charsets" :key="c" type="button" :aria-pressed="bruteCharset.includes(c)"
                            class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                            :class="bruteCharset.includes(c) ? 'border-violet-500 bg-violet-50 text-violet-700 dark:border-violet-400 dark:bg-violet-500/15 dark:text-violet-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                            @click="toggle(bruteCharset, c)">{{ t(`tools.hashForge.charset.${c}`) }}</button>
                    </div>
                    <div>
                        <label for="hf-bcustom" class="mb-1 block text-sm text-slate-600 dark:text-slate-300">{{ t('tools.hashForge.customChars') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                        <input id="hf-bcustom" v-model="bruteCustom" type="text" spellcheck="false" autocomplete="off" :placeholder="t('tools.hashForge.customCharsPlaceholder')" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />
                        <p class="mt-1 text-xs text-slate-400">{{ t('tools.hashForge.poolSize', { n: brutePoolSize }) }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <label for="hf-bmin" class="text-slate-600 dark:text-slate-300">{{ t('tools.hashForge.length') }}</label>
                        <input id="hf-bmin" v-model.number="bruteMin" type="number" min="1" max="8" class="w-16 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        <span class="text-slate-400">–</span>
                        <input v-model.number="bruteMax" type="number" min="1" max="8" class="w-16 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        <span class="text-xs" :class="bruteTooBig ? 'text-red-600 dark:text-red-400' : 'text-slate-400'">
                            {{ bruteCombos === Infinity ? '∞' : bruteCombos.toLocaleString() }} {{ t('tools.hashForge.combos') }}
                        </span>
                    </div>
                    <p v-if="bruteTooBig" class="text-xs text-red-600 dark:text-red-400">{{ t('tools.hashForge.bruteTooBig', { cap: limits.bruteCap.toLocaleString() }) }}</p>
                </div>
            </div>
            <div>
                <label for="hf-crackkey" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.hmacKey') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                <input id="hf-crackkey" v-model="crackKey" type="text" spellcheck="false" autocomplete="off" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />
            </div>
            <button type="submit" :disabled="!crackHash.trim() || !hasSource || bruteTooBig || cracking" :class="['inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-50', accent.solid]">
                <i class="bi" :class="cracking ? 'bi-arrow-repeat animate-spin' : 'bi-unlock'"></i>{{ t('tools.hashForge.crack') }}
            </button>
            <p class="text-xs text-slate-400"><i class="bi bi-shield-check mr-1"></i>{{ t('tools.hashForge.crackNote') }}</p>
            <p v-if="crackError" class="text-sm text-red-600 dark:text-red-400">{{ crackError }}</p>

            <div v-if="crackResult" class="rounded-lg border px-4 py-3 text-sm" :class="crackResult.found ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200' : 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'">
                <template v-if="crackResult.found">
                    <div class="flex items-center gap-2 font-semibold"><i class="bi bi-exclamation-octagon-fill"></i>{{ t('tools.hashForge.crackFound') }}</div>
                    <code class="mt-1 block break-all rounded bg-white/60 px-2 py-1 font-mono dark:bg-black/20">{{ crackResult.plaintext }}</code>
                    <p class="mt-1 text-xs">{{ t('tools.hashForge.crackTried', { n: crackResult.tried }) }}</p>
                </template>
                <template v-else-if="crackResult.unknown">{{ t('tools.hashForge.unknownFormat') }}</template>
                <template v-else>
                    <div class="flex items-center gap-2 font-medium"><i class="bi bi-check-circle"></i>{{ t('tools.hashForge.crackNotFound') }}</div>
                    <p class="mt-1 text-xs">{{ t('tools.hashForge.crackTried', { n: crackResult.tried.toLocaleString() }) }}</p>
                    <p v-if="crackResult.stopped === 'time'" class="mt-1 text-xs text-amber-700 dark:text-amber-300"><i class="bi bi-hourglass-split mr-1"></i>{{ t('tools.hashForge.crackStopped') }}</p>
                </template>
            </div>
        </form>

        <section :class="['space-y-3', cardClass]">
            <div>
                <h2 class="text-sm font-semibold">{{ t('tools.hashForge.auditTitle') }}</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.auditIntro') }}</p>
            </div>
            <div>
                <label for="hf-apw" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.password') }}</label>
                <input id="hf-apw" v-model="auditPassword" type="text" spellcheck="false" autocomplete="off" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />
            </div>
            <div>
                <label for="hf-ausr" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.username') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                <input id="hf-ausr" v-model="auditUsername" type="text" spellcheck="false" autocomplete="off" :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]" />
            </div>
            <div v-if="audit">
                <div class="mb-1 flex items-center justify-between text-sm">
                    <span class="font-semibold">{{ t(`tools.hashForge.strength.${audit.label}`) }}</span>
                    <span class="text-xs text-slate-400">{{ t('tools.hashForge.entropy', { n: audit.entropyBits }) }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                    <div class="h-full transition-all" :class="strengthColor(audit.score)" :style="{ width: ((audit.score + 1) / 5 * 100) + '%' }"></div>
                </div>
                <ul v-if="audit.reasons.length" class="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-300">
                    <li v-for="r in audit.reasons" :key="r" class="flex items-center gap-1.5">
                        <i class="bi bi-dot text-amber-500"></i>{{ t(`tools.hashForge.reason.${r}`) }}
                    </li>
                </ul>
            </div>
            <p v-else class="py-6 text-center text-sm text-slate-400">{{ t('tools.hashForge.auditEmpty') }}</p>
        </section>
    </div>
</template>
