<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    digests: { type: Array, default: () => [] },
    passwords: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ text: 1048576, password: 4096, bruteCap: 500000 }) },
    wordlistSets: { type: Array, default: () => [] },
    charsets: { type: Array, default: () => [] },
});

const { panelRoute, requestJson } = usePanelApi();

const tab = ref('generate');
const tabs = computed(() => [
    { id: 'generate', label: t('tools.hashForge.tabGenerate'), icon: 'bi-hash' },
    { id: 'verify', label: t('tools.hashForge.tabVerify'), icon: 'bi-patch-check' },
    { id: 'crack', label: t('tools.hashForge.tabCrack'), icon: 'bi-unlock' },
    { id: 'encode', label: t('tools.hashForge.tabEncode'), icon: 'bi-arrow-left-right' },
]);

// ---- Generate ---------------------------------------------------------------
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

// Reassign rather than mutate in place so the chip state always re-renders.
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

// Digests are cheap and deterministic, so they follow the text as you type.
// Salted password hashes change on every run, so those wait for the button.
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
    } catch {
        // Clipboard can be blocked on plain http; the value stays selectable.
    }
};
const copyAll = () => copy(results.value.map((r) => `${r.label}: ${r.value}`).join('\n'), '__all');

// ---- Verify -----------------------------------------------------------------
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

// ---- Identify & crack -------------------------------------------------------
const crackHash = ref('');
const crackKey = ref('');
const selectedSets = ref(props.wordlistSets.some((s) => s.id === 'common') ? ['common'] : []);
const crackAlgos = ref([]); // empty = auto-detect by length
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

// Size of the brute-force character pool: selected classes plus any custom characters.
const brutePoolSize = computed(() => {
    const chars = new Set(bruteCustom.value);
    const widths = { digits: 10, lower: 26, upper: 26, symbols: 14 };
    for (const id of bruteCharset.value) {
        for (let i = 0; i < (widths[id] || 0); i++) chars.add(id + i); // placeholder tokens, just for the count
    }
    return chars.size;
});
// Estimated brute-force combinations, so the user sees when a range is too big before running.
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
        const data = await requestJson(panelRoute('tools.hash-forge.crack'), {
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
        crackResult.value = data;
    } catch (e) {
        crackError.value = e.message;
    } finally {
        cracking.value = false;
    }
};
// Password / username strength auditor (offline on the server, no wordlist leaves the panel).
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
        } catch { /* leave the previous verdict in place */ }
    }, 300);
});
const strengthColor = (score) => ['bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-lime-500', 'bg-emerald-500'][score] || 'bg-slate-300';

// ---- Encode / decode (browser only) -----------------------------------------
const encodeInput = ref('');
const encodeMode = ref('encode');
const bytesToBinary = (bytes) => Array.from(bytes, (b) => String.fromCharCode(b)).join('');
const binaryToBytes = (bin) => Uint8Array.from(bin, (c) => c.charCodeAt(0));
const utf8 = (s) => new TextEncoder().encode(s);
const fromUtf8 = (bytes) => new TextDecoder('utf-8', { fatal: true }).decode(bytes);

const codecs = [
    {
        id: 'base64', label: 'Base64',
        encode: (s) => btoa(bytesToBinary(utf8(s))),
        decode: (s) => fromUtf8(binaryToBytes(atob(s.replace(/\s+/g, '')))),
    },
    {
        id: 'base64url', label: 'Base64URL',
        encode: (s) => btoa(bytesToBinary(utf8(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
        decode: (s) => {
            let b = s.trim().replace(/-/g, '+').replace(/_/g, '/');
            while (b.length % 4) b += '=';
            return fromUtf8(binaryToBytes(atob(b)));
        },
    },
    {
        id: 'hex', label: 'Hex',
        encode: (s) => Array.from(utf8(s), (b) => b.toString(16).padStart(2, '0')).join(''),
        decode: (s) => {
            const clean = s.replace(/\s+|0x/gi, '');
            if (!/^([0-9a-f]{2})*$/i.test(clean)) throw new Error('hex');
            return fromUtf8(Uint8Array.from(clean.match(/../g) || [], (h) => parseInt(h, 16)));
        },
    },
    { id: 'url', label: 'URL', encode: (s) => encodeURIComponent(s), decode: (s) => decodeURIComponent(s.replace(/\+/g, ' ')) },
    {
        id: 'html', label: 'HTML entities',
        encode: (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
        decode: (s) => new DOMParser().parseFromString(`<!doctype html><body>${s}`, 'text/html').body.textContent || '',
    },
    {
        id: 'binary', label: 'Binary',
        encode: (s) => Array.from(utf8(s), (b) => b.toString(2).padStart(8, '0')).join(' '),
        decode: (s) => {
            const parts = s.trim().split(/\s+/).filter(Boolean);
            if (parts.some((p) => !/^[01]{1,8}$/.test(p))) throw new Error('binary');
            return fromUtf8(Uint8Array.from(parts, (p) => parseInt(p, 2)));
        },
    },
    { id: 'rot13', label: 'ROT13', encode: rot13, decode: rot13 },
];

function rot13(s) {
    return s.replace(/[a-z]/gi, (ch) => {
        const base = ch <= 'Z' ? 65 : 97;
        return String.fromCharCode(((ch.charCodeAt(0) - base + 13) % 26) + base);
    });
}

const encoded = computed(() => codecs.map((codec) => {
    if (!encodeInput.value) return { ...codec, value: '', failed: false };
    try {
        return { ...codec, value: codec[encodeMode.value](encodeInput.value), failed: false };
    } catch {
        return { ...codec, value: '', failed: true };
    }
}));
</script>

<template>
    <Head :title="t('tools.hashForge.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-100 text-lg text-teal-700 dark:bg-teal-500/15 dark:text-teal-300">
                    <i class="bi bi-hash"></i>
                </span>
                <div>
                    <h1 class="text-lg font-semibold">{{ t('tools.hashForge.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.subtitle') }}</p>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div class="inline-flex flex-wrap rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm dark:border-slate-700 dark:bg-slate-900" role="tablist">
                <button
                    v-for="item in tabs"
                    :key="item.id"
                    type="button"
                    role="tab"
                    :aria-selected="tab === item.id"
                    class="rounded-md px-3 py-1.5 font-medium transition"
                    :class="tab === item.id ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                    @click="tab = item.id"
                >
                    <i class="bi mr-1" :class="item.icon"></i>{{ item.label }}
                </button>
            </div>

            <!-- Generate -->
            <div v-if="tab === 'generate'" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="generate">
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label for="hf-text" class="text-sm font-medium">{{ t('tools.hashForge.input') }}</label>
                            <span class="text-xs text-slate-400">{{ t('tools.hashForge.bytes', { n: byteLength }) }}</span>
                        </div>
                        <textarea
                            id="hf-text"
                            v-model="text"
                            rows="5"
                            spellcheck="false"
                            autocomplete="off"
                            :placeholder="t('tools.hashForge.inputPlaceholder')"
                            class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"
                        ></textarea>
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
                                    <button
                                        v-for="d in g.items"
                                        :key="d.id"
                                        type="button"
                                        :aria-pressed="selectedDigests.includes(d.id)"
                                        class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                        :class="selectedDigests.includes(d.id) ? 'border-teal-500 bg-teal-50 text-teal-700 dark:border-teal-400 dark:bg-teal-500/15 dark:text-teal-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                                        @click="toggle(selectedDigests, d.id)"
                                    >
                                        {{ d.label }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <span class="mb-2 block text-sm font-medium">{{ t('tools.hashForge.passwordHashes') }}</span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="p in passwords"
                                :key="p.id"
                                type="button"
                                :aria-pressed="selectedPasswords.includes(p.id)"
                                class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                :class="selectedPasswords.includes(p.id) ? 'border-violet-500 bg-violet-50 text-violet-700 dark:border-violet-400 dark:bg-violet-500/15 dark:text-violet-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                                @click="toggle(selectedPasswords, p.id)"
                            >
                                <i class="bi bi-shield-lock mr-0.5"></i>{{ p.label }}
                            </button>
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
                            <input v-model="uppercase" type="checkbox" class="rounded border-slate-300 text-teal-600 dark:border-slate-600" />
                            {{ t('tools.hashForge.uppercase') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="showHmac" type="checkbox" class="rounded border-slate-300 text-teal-600 dark:border-slate-600" />
                            {{ t('tools.hashForge.useHmac') }}
                        </label>
                    </div>
                    <input
                        v-if="showHmac"
                        v-model="hmacKey"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="t('tools.hashForge.hmacKey')"
                        class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"
                    />

                    <button type="submit" :disabled="!canGenerate || generating" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">
                        <i class="bi" :class="generating ? 'bi-arrow-repeat animate-spin' : 'bi-lightning-charge'"></i>
                        {{ t('tools.hashForge.generate') }}
                    </button>
                    <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                </form>

                <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">{{ t('tools.hashForge.results') }}</h2>
                        <button v-if="results.length" type="button" class="text-xs text-teal-700 hover:underline dark:text-teal-300" @click="copyAll">
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
                            <code class="block break-all rounded-md bg-slate-50 px-2 py-1.5 font-mono text-xs text-slate-800 select-all dark:bg-slate-900 dark:text-slate-100">{{ r.value }}</code>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Verify -->
            <form v-else-if="tab === 'verify'" class="max-w-3xl space-y-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="verify">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.verifyIntro') }}</p>
                <div>
                    <label for="hf-vtext" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.verifyText') }}</label>
                    <textarea id="hf-vtext" v-model="verifyText" rows="3" spellcheck="false" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                </div>
                <div>
                    <label for="hf-vhash" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.verifyHash') }}</label>
                    <input id="hf-vhash" v-model="verifyHash" type="text" spellcheck="false" autocomplete="off" placeholder="$2y$10$…  /  5f4dcc3b5aa765d61d8327deb882cf99" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label for="hf-vkey" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.hmacKey') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                    <input id="hf-vkey" v-model="verifyKey" type="text" spellcheck="false" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <button type="submit" :disabled="!verifyHash.trim() || verifying" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">
                    <i class="bi" :class="verifying ? 'bi-arrow-repeat animate-spin' : 'bi-patch-check'"></i>
                    {{ t('tools.hashForge.verify') }}
                </button>
                <p v-if="verifyError" class="text-sm text-red-600 dark:text-red-400">{{ verifyError }}</p>

                <div
                    v-if="verdict"
                    class="rounded-lg border px-4 py-3 text-sm"
                    :class="verdict.match ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-red-300 bg-red-50 text-red-800 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200'"
                >
                    <div class="flex items-center gap-2 font-semibold">
                        <i class="bi text-lg" :class="verdict.match ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>
                        {{ verdict.match ? t('tools.hashForge.match', { algo: verdict.algorithm }) : t('tools.hashForge.noMatch') }}
                    </div>
                    <p v-if="verdict.unknown" class="mt-1">{{ t('tools.hashForge.unknownFormat') }}</p>
                    <p v-else-if="!verdict.match && verdict.candidates.length" class="mt-1">{{ t('tools.hashForge.triedCandidates', { list: verdict.candidates.join(', ') }) }}</p>
                </div>
            </form>

            <!-- Identify & crack -->
            <div v-else-if="tab === 'crack'" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="space-y-4">
                    <form class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="crack">
                        <div>
                            <label for="hf-crack" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.crackHash') }}</label>
                            <input id="hf-crack" v-model="crackHash" type="text" spellcheck="false" autocomplete="off" placeholder="5f4dcc3b5aa765d61d8327deb882cf99" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" @input="identity = null; crackResult = null" />
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
                                <button
                                    v-for="s in wordlistSets"
                                    :key="s.id"
                                    type="button"
                                    :aria-pressed="selectedSets.includes(s.id)"
                                    class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                    :class="selectedSets.includes(s.id) ? 'border-teal-500 bg-teal-50 text-teal-700 dark:border-teal-400 dark:bg-teal-500/15 dark:text-teal-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                                    @click="toggle(selectedSets, s.id)"
                                >
                                    {{ t('tools.hashForge.set.' + s.id) }}
                                    <span class="ml-0.5 font-normal text-slate-400">{{ s.count.toLocaleString() }}</span>
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="mb-1.5 flex items-center justify-between">
                                <span class="text-sm font-medium">{{ t('tools.hashForge.limitAlgos') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></span>
                                <button v-if="crackAlgos.length" type="button" class="text-xs text-teal-700 hover:underline dark:text-teal-300" @click="crackAlgos = []">{{ t('tools.hashForge.preset.none') }}</button>
                            </div>
                            <p class="mb-1.5 text-xs text-slate-400">{{ t('tools.hashForge.limitAlgosHint') }}</p>
                            <div class="space-y-1.5">
                                <div v-for="g in groups" :key="g.group">
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="d in g.items"
                                            :key="d.id"
                                            type="button"
                                            :aria-pressed="crackAlgos.includes(d.id)"
                                            class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                            :class="crackAlgos.includes(d.id) ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-400 dark:bg-sky-500/15 dark:text-sky-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                                            @click="toggle(crackAlgos, d.id)"
                                        >
                                            {{ d.label }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label for="hf-words" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.customWordlist') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                            <textarea id="hf-words" v-model="customWords" rows="3" spellcheck="false" autocomplete="off" :placeholder="t('tools.hashForge.wordlistPlaceholder')" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                        </div>

                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <label class="inline-flex items-center gap-2 text-sm font-medium">
                                <input v-model="brute" type="checkbox" class="rounded border-slate-300 text-teal-600 dark:border-slate-600" />
                                {{ t('tools.hashForge.bruteForce') }}
                            </label>
                            <div v-if="brute" class="mt-3 space-y-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="c in charsets"
                                        :key="c"
                                        type="button"
                                        :aria-pressed="bruteCharset.includes(c)"
                                        class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                        :class="bruteCharset.includes(c) ? 'border-violet-500 bg-violet-50 text-violet-700 dark:border-violet-400 dark:bg-violet-500/15 dark:text-violet-200' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-600 dark:text-slate-300'"
                                        @click="toggle(bruteCharset, c)"
                                    >
                                        {{ t('tools.hashForge.charset.' + c) }}
                                    </button>
                                </div>
                                <div>
                                    <label for="hf-bcustom" class="mb-1 block text-sm text-slate-600 dark:text-slate-300">{{ t('tools.hashForge.customChars') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                                    <input id="hf-bcustom" v-model="bruteCustom" type="text" spellcheck="false" autocomplete="off" :placeholder="t('tools.hashForge.customCharsPlaceholder')" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
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
                            <input id="hf-crackkey" v-model="crackKey" type="text" spellcheck="false" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                        </div>
                        <button type="submit" :disabled="!crackHash.trim() || !hasSource || bruteTooBig || cracking" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50">
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
                </div>

                <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div>
                        <h2 class="text-sm font-semibold">{{ t('tools.hashForge.auditTitle') }}</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.auditIntro') }}</p>
                    </div>
                    <div>
                        <label for="hf-apw" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.password') }}</label>
                        <input id="hf-apw" v-model="auditPassword" type="text" spellcheck="false" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label for="hf-ausr" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.username') }} <span class="font-normal text-slate-400">{{ t('tools.hashForge.optional') }}</span></label>
                        <input id="hf-ausr" v-model="auditUsername" type="text" spellcheck="false" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div v-if="audit">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-semibold">{{ t('tools.hashForge.strength.' + audit.label) }}</span>
                            <span class="text-xs text-slate-400">{{ t('tools.hashForge.entropy', { n: audit.entropyBits }) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div class="h-full transition-all" :class="strengthColor(audit.score)" :style="{ width: ((audit.score + 1) / 5 * 100) + '%' }"></div>
                        </div>
                        <ul v-if="audit.reasons.length" class="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-300">
                            <li v-for="r in audit.reasons" :key="r" class="flex items-center gap-1.5">
                                <i class="bi bi-dot text-amber-500"></i>{{ t('tools.hashForge.reason.' + r) }}
                            </li>
                        </ul>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-slate-400">{{ t('tools.hashForge.auditEmpty') }}</p>
                </section>
            </div>

            <!-- Encode / decode -->
            <div v-else class="space-y-4">
                <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label for="hf-enc" class="text-sm font-medium">{{ t('tools.hashForge.input') }}</label>
                        <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs dark:border-slate-700 dark:bg-slate-900">
                            <button v-for="m in ['encode', 'decode']" :key="m" type="button" class="rounded-md px-3 py-1 font-medium" :class="encodeMode === m ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500'" @click="encodeMode = m">
                                {{ t(`tools.hashForge.${m}`) }}
                            </button>
                        </div>
                    </div>
                    <textarea id="hf-enc" v-model="encodeInput" rows="4" spellcheck="false" autocomplete="off" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    <p class="text-xs text-slate-400"><i class="bi bi-laptop mr-1"></i>{{ t('tools.hashForge.localOnly') }}</p>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div v-for="c in encoded" :key="c.id" class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-800">
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-xs font-semibold">{{ c.label }}</span>
                            <button v-if="c.value" type="button" class="rounded px-1.5 py-0.5 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" @click="copy(c.value, 'enc-' + c.id)">
                                <i class="bi" :class="copied === 'enc-' + c.id ? 'bi-check2 text-emerald-600' : 'bi-clipboard'"></i>
                            </button>
                        </div>
                        <p v-if="c.failed" class="text-xs text-slate-400">{{ t('tools.hashForge.notValid', { format: c.label }) }}</p>
                        <code v-else class="block min-h-[2rem] break-all whitespace-pre-wrap rounded-md bg-slate-50 px-2 py-1.5 font-mono text-xs dark:bg-slate-900">{{ c.value }}</code>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
