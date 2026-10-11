<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MailAvatar from './MailAvatar.vue';
import ContactCard from './ContactCard.vue';
import { avatarColorOf, emailOf, initialsOf, nameOrEmail, sameAddress } from './mailAddresses';
import { mailDocument } from './mailFrame';

// Another message of the open conversation, Gmail style: a one-line summary
// that expands to the full message. The body is fetched on first expand.
const props = defineProps({
    item: { type: Object, required: true },
    messageEndpoint: { type: String, default: '' },
    avatarEndpoint: { type: String, default: '' },
    selfEmail: { type: String, default: '' },
    isDark: { type: Boolean, default: false },
    // The newest message starts open, like Gmail.
    initiallyOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['compose-to', 'reply']);

// Replies need the loaded body for the quote; folder comes from the thread row.
const replyTo = () => emit('reply', message.value ? { ...message.value, folder: props.item.folder } : props.item);

const open = ref(props.initiallyOpen);
const message = ref(null);
const loading = ref(false);
const error = ref('');
const frame = ref(null);
const frameHeight = ref(120);
let observer = null;

const ui = computed(() => (props.isDark
    ? { hover: 'hover:bg-slate-900/70', strong: 'text-slate-100', muted: 'text-slate-500', body: 'text-slate-300', divider: 'border-slate-800', icon: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }
    : { hover: 'hover:bg-slate-50', strong: 'text-slate-900', muted: 'text-slate-500', body: 'text-slate-800', divider: 'border-slate-100', icon: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900' }));

const fromSelf = computed(() => sameAddress(props.item.from, props.selfEmail));
const senderLabel = computed(() => (fromSelf.value ? 'me' : nameOrEmail(props.item.from)));
const shortDate = computed(() => {
    const parsed = new Date(props.item.date);
    if (Number.isNaN(parsed.getTime())) return props.item.date;
    const today = parsed.toDateString() === new Date().toDateString();

    return today
        ? parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
        : parsed.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
});
const srcdoc = computed(() => mailDocument(message.value?.html));
// Quoted history is already in the conversation above; keep the text view short like Gmail.
const plainText = computed(() => String(message.value?.text || '').split(/\n(?=On .+wrote:\s*\n)/)[0].trim());

const load = async () => {
    if (message.value || loading.value || !props.messageEndpoint) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(props.messageEndpoint, {
            params: { folder: props.item.folder, uid: props.item.uid },
            headers: { Accept: 'application/json' },
        });
        if (!data?.success) throw new Error(data?.message || 'Message could not be loaded.');
        message.value = data.messageData;
    }
    catch (e) {
        error.value = e?.message || 'Message could not be loaded.';
    }
    finally {
        loading.value = false;
    }
};

const toggle = () => {
    open.value = !open.value;
};
watch(open, (isOpen) => { if (isOpen) load(); }, { immediate: true });

const resize = () => {
    const doc = frame.value?.contentDocument;
    if (doc?.documentElement) frameHeight.value = Math.max(60, Math.ceil(doc.documentElement.offsetHeight));
};
const onFrameLoad = () => {
    resize();
    observer?.disconnect();
    const doc = frame.value?.contentDocument;
    if (doc?.documentElement && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(resize);
        observer.observe(doc.documentElement);
    }
};
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <article>
        <header
            :class="[open ? 'pb-2 pt-4' : ['py-3', ui.hover], 'flex cursor-pointer items-start gap-3 px-4 transition md:px-6']"
            role="button"
            tabindex="0"
            :aria-expanded="open"
            @click="toggle"
            @keydown.enter.prevent="toggle"
        >
            <ContactCard :address="item.from" :is-dark="isDark" :avatar-endpoint="avatarEndpoint" class="shrink-0" @compose="emit('compose-to', $event)">
                <MailAvatar :email="emailOf(item.from)" :initials="initialsOf(item.from)" :color-class="avatarColorOf(item.from)" :endpoint="avatarEndpoint" class="h-10 w-10 text-sm" />
            </ContactCard>
            <div class="min-w-0 flex-1 pt-0.5">
                <div class="flex items-baseline gap-3">
                    <p class="flex min-w-0 flex-1 items-baseline gap-1.5">
                        <span :class="[ui.strong, item.seen ? 'font-medium' : 'font-semibold', 'truncate text-[15px]']">{{ senderLabel }}</span>
                        <span v-if="open && !fromSelf" :class="[ui.muted, 'truncate text-xs']">&lt;{{ emailOf(item.from) }}&gt;</span>
                    </p>
                    <time :class="[ui.muted, 'shrink-0 text-xs']" :title="item.date">{{ shortDate }}</time>
                    <button v-if="open" type="button" :class="[ui.icon, '-my-1.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full']" title="Reply" @click.stop="replyTo">
                        <i class="bi bi-reply"></i>
                    </button>
                </div>
                <p v-if="!open" :class="[ui.muted, 'mt-0.5 truncate text-sm']">{{ item.snippet || '(no preview)' }}</p>
                <p v-else :class="[ui.muted, 'truncate text-xs']">to {{ item.to }}</p>
            </div>
        </header>

        <div v-if="open" class="pb-2">
            <p v-if="loading" :class="[ui.muted, 'py-4 pl-[68px] text-sm md:pl-[76px]']"><i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i> Loading…</p>
            <p v-else-if="error" class="py-3 pl-[68px] text-sm text-rose-600 md:pl-[76px]">{{ error }} <button type="button" class="ml-2 underline" @click="load">Retry</button></p>
            <div v-else-if="srcdoc" class="md:pl-[52px]">
                <iframe
                    ref="frame"
                    :srcdoc="srcdoc"
                    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    referrerpolicy="no-referrer"
                    title="Message body"
                    class="block w-full rounded-lg border-0 bg-white"
                    :style="{ height: `${frameHeight}px` }"
                    @load="onFrameLoad"
                ></iframe>
            </div>
            <div v-else-if="message" :class="[ui.body, 'whitespace-pre-wrap break-words py-3 pl-[68px] pr-4 text-[15px] leading-7 md:pl-[76px] md:pr-6']">{{ plainText || 'No body available.' }}</div>
        </div>
    </article>
</template>
