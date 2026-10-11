<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { displayName, emailOf, sameAddress, splitAddresses } from './mailAddresses';

// Gmail's "to me ▾": who the message went to, and a popover with the header
// details (from, to, date, mailed-by, signed-by, encryption). The details
// come from the message source, which the parent loads on demand.
const props = defineProps({
    message: { type: Object, required: true },
    selfEmail: { type: String, default: '' },
    analysis: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    messageKey: { type: String, default: '' },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['load', 'show-original']);

const root = ref(null);
const open = ref(false);
const showAllHeaders = ref(false);

watch(() => props.messageKey, () => {
    open.value = false;
    showAllHeaders.value = false;
});

const ui = computed(() => (props.isDark
    ? { trigger: 'text-slate-400 hover:text-slate-200', pop: 'border-slate-700 bg-slate-900 shadow-black/50', label: 'text-slate-500', value: 'text-slate-200', strong: 'text-slate-100', divider: 'border-slate-800', link: 'text-blue-400' }
    : { trigger: 'text-slate-500 hover:text-slate-800', pop: 'border-slate-200 bg-white shadow-slate-900/15', label: 'text-slate-500', value: 'text-slate-700', strong: 'text-slate-900', divider: 'border-slate-100', link: 'text-blue-600' }));

const recipients = computed(() => splitAddresses(props.message.to));
const headerValue = (name) => props.analysis?.headers?.find((h) => h.name.toLowerCase() === name)?.value || '';
const cc = computed(() => headerValue('cc'));

// "to me", "to me, Alice +2", "to Bob" — Gmail's short recipient line.
const shortLabel = computed(() => {
    const names = recipients.value.map((item) => (props.selfEmail && sameAddress(item, props.selfEmail) ? 'me' : displayName(item) || emailOf(item)));
    if (!names.length) return 'to (no recipient)';
    const ordered = names.includes('me') ? ['me', ...names.filter((n) => n !== 'me')] : names;

    return `to ${ordered.slice(0, 2).join(', ')}${ordered.length > 2 ? ` +${ordered.length - 2}` : ''}`;
});

const domainOf = (value) => emailOf(value).split('@')[1] || '';
const mailedBy = computed(() => domainOf(props.analysis?.summary?.return_path || '') || props.analysis?.auth?.spf?.domain || '');
const signedBy = computed(() => [...new Set((props.analysis?.auth?.dkim || []).filter((sig) => sig.result === 'pass' && sig.domain).map((sig) => sig.domain))].join(', '));
const security = computed(() => {
    if (!props.analysis || props.analysis.tls === null || props.analysis.tls === undefined) return null;

    return props.analysis.tls
        ? { icon: 'bi-lock-fill', text: 'Standard encryption (TLS)', tone: '' }
        : { icon: 'bi-unlock', text: 'Not encrypted on every hop', tone: 'text-amber-600' };
});

const formattedDate = computed(() => {
    const parsed = new Date(props.message.date);

    return Number.isNaN(parsed.getTime())
        ? props.message.date
        : parsed.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
});

const toggle = () => {
    open.value = !open.value;
    if (open.value && !props.analysis) emit('load');
};

const onDocumentClick = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
};
const onKeydown = (event) => {
    if (event.key === 'Escape') open.value = false;
};
watch(open, (isOpen) => {
    const method = isOpen ? 'addEventListener' : 'removeEventListener';
    document[method]('mousedown', onDocumentClick);
    document[method]('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" :class="[ui.trigger, 'inline-flex max-w-full items-center gap-1 rounded text-xs transition']" :aria-expanded="open" aria-haspopup="dialog" @click="toggle">
            <span class="truncate">{{ shortLabel }}</span>
            <i :class="['bi text-[10px] transition-transform', open ? 'bi-caret-up-fill' : 'bi-caret-down-fill']"></i>
        </button>

        <transition enter-active-class="transition duration-150 ease-out" enter-from-class="-translate-y-1 opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div
                v-if="open"
                :class="[ui.pop, 'absolute left-0 top-full z-40 mt-1.5 w-[34rem] max-w-[calc(100vw-2rem)] rounded-xl border px-5 py-4 text-[13px] shadow-2xl']"
                role="dialog"
                aria-label="Message details"
            >
                <dl class="grid grid-cols-[6.5rem_1fr] gap-x-4 gap-y-2">
                    <dt :class="[ui.label, 'text-right']">from:</dt>
                    <dd class="min-w-0 break-words">
                        <span :class="[ui.strong, 'font-semibold']">{{ displayName(message.from) || emailOf(message.from) }}</span>
                        <span v-if="displayName(message.from)" :class="[ui.label, 'block']">&lt;{{ emailOf(message.from) }}&gt;</span>
                    </dd>

                    <template v-if="analysis?.summary?.reply_to && !sameAddress(analysis.summary.reply_to, message.from)">
                        <dt :class="[ui.label, 'text-right']">reply-to:</dt>
                        <dd :class="[ui.value, 'min-w-0 break-words']">{{ analysis.summary.reply_to }}</dd>
                    </template>

                    <dt :class="[ui.label, 'text-right']">to:</dt>
                    <dd :class="[ui.value, 'min-w-0 break-words']">{{ message.to || '(no recipient)' }}</dd>

                    <template v-if="cc">
                        <dt :class="[ui.label, 'text-right']">cc:</dt>
                        <dd :class="[ui.value, 'min-w-0 break-words']">{{ cc }}</dd>
                    </template>

                    <dt :class="[ui.label, 'text-right']">date:</dt>
                    <dd :class="[ui.value, 'min-w-0']">{{ formattedDate }}</dd>

                    <dt :class="[ui.label, 'text-right']">subject:</dt>
                    <dd :class="[ui.value, 'min-w-0 break-words']">{{ message.subject || '(no subject)' }}</dd>

                    <template v-if="analysis">
                        <template v-if="mailedBy">
                            <dt :class="[ui.label, 'text-right']">mailed-by:</dt>
                            <dd :class="[ui.value, 'min-w-0 break-words']">{{ mailedBy }}</dd>
                        </template>
                        <template v-if="signedBy">
                            <dt :class="[ui.label, 'text-right']">signed-by:</dt>
                            <dd :class="[ui.value, 'min-w-0 break-words']">{{ signedBy }}</dd>
                        </template>
                        <template v-if="security">
                            <dt :class="[ui.label, 'text-right']">security:</dt>
                            <dd :class="[ui.value, security.tone, 'min-w-0']"><i :class="['bi mr-1 text-xs', security.icon]"></i>{{ security.text }}</dd>
                        </template>
                    </template>
                </dl>

                <p v-if="loading && !analysis" :class="[ui.label, 'mt-3 text-xs']">
                    <i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i> Checking mailed-by, signature and encryption…
                </p>
                <p v-else-if="error && !analysis" class="mt-3 text-xs text-rose-600">
                    {{ error }}
                    <button type="button" class="ml-2 underline" @click="emit('load')">Retry</button>
                </p>

                <div v-if="analysis" :class="[ui.divider, 'mt-3 flex flex-wrap items-center gap-4 border-t pt-3 text-xs']">
                    <button type="button" :class="[ui.link, 'font-medium hover:underline']" :aria-expanded="showAllHeaders" @click="showAllHeaders = !showAllHeaders">
                        {{ showAllHeaders ? 'Hide' : 'Show' }} all headers ({{ analysis.headers.length }})
                    </button>
                    <button type="button" :class="[ui.link, 'font-medium hover:underline']" @click="open = false; emit('show-original')">Show original</button>
                </div>
                <div v-if="showAllHeaders && analysis" class="mt-2 max-h-72 overflow-y-auto">
                    <table class="w-full table-fixed text-xs">
                        <tbody>
                            <tr v-for="(header, i) in analysis.headers" :key="i" :class="[ui.divider, 'border-t align-top']">
                                <td :class="[ui.label, 'w-36 break-words py-1 pr-3 font-mono font-medium']">{{ header.name }}</td>
                                <td :class="[ui.value, 'break-words py-1 font-mono']">{{ header.value }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </transition>
    </div>
</template>
