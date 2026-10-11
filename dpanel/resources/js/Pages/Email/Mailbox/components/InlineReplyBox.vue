<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import RichTextEditor from './RichTextEditor.vue';
import RecipientInput from './RecipientInput.vue';
import MailAvatar from './MailAvatar.vue';
import { avatarColorOf, emailOf, initialsOf, nameOrEmail, sameAddress, splitAddresses, withoutAddress } from './mailAddresses';

// Gmail-style reply that opens under the message instead of a new window.
// Reply, reply all and forward share it; the arrow menu switches between them.
const props = defineProps({
    message: { type: Object, required: true },
    mode: { type: String, default: 'reply' },
    folder: { type: String, default: 'INBOX' },
    selfEmail: { type: String, default: '' },
    avatarEndpoint: { type: String, default: '' },
    sendEndpoint: { type: String, default: '' },
    sandbox: { type: Boolean, default: false },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['sent', 'discard', 'pop-out', 'notify', 'update:mode']);

const to = ref('');
const cc = ref('');
const bcc = ref('');
const subject = ref('');
const html = ref('');
const text = ref('');
const editingRecipients = ref(false);
const showCc = ref(false);
const showBcc = ref(false);
const showQuote = ref(false);
const showToolbar = ref(true);
const modeMenu = ref(false);
const sending = ref(false);
const editor = ref(null);
const toInput = ref(null);

const ui = computed(() => (props.isDark
    ? { card: 'border-slate-700 bg-slate-900 shadow-black/40', muted: 'text-slate-400', strong: 'text-slate-100', icon: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100', divider: 'border-slate-800', menu: 'border-slate-700 bg-slate-900 text-slate-200 shadow-black/50', menuItem: 'hover:bg-slate-800', input: 'text-slate-100 placeholder:text-slate-500', dots: 'bg-slate-800 text-slate-300 hover:bg-slate-700', quote: 'border-slate-700 text-slate-400' }
    : { card: 'border-slate-200 bg-white shadow-slate-300/50', muted: 'text-slate-500', strong: 'text-slate-900', icon: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900', divider: 'border-slate-100', menu: 'border-slate-200 bg-white text-slate-800 shadow-slate-900/15', menuItem: 'hover:bg-slate-100', input: 'text-slate-900 placeholder:text-slate-400', dots: 'bg-slate-200/80 text-slate-600 hover:bg-slate-300', quote: 'border-slate-300 text-slate-600' }));

const modes = [
    { id: 'reply', label: 'Reply', icon: 'bi-reply' },
    { id: 'reply-all', label: 'Reply all', icon: 'bi-reply-all' },
    { id: 'forward', label: 'Forward', icon: 'bi-forward' },
];
const currentMode = computed(() => modes.find((m) => m.id === props.mode) || modes[0]);
const fromSelf = computed(() => sameAddress(props.message.from, props.selfEmail));

const escapeHtml = (value) => String(value || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const originalText = computed(() => props.message.text || props.message.raw_body || '');
const originalDate = computed(() => {
    const parsed = new Date(props.message.date);

    return Number.isNaN(parsed.getTime()) ? props.message.date : parsed.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
});

// Forwarded content goes in the body; a reply's quote stays folded under "•••" like Gmail.
const forwardHeader = () => [
    '---------- Forwarded message ---------',
    `From: ${props.message.from || ''}`,
    `Date: ${originalDate.value}`,
    `Subject: ${props.message.subject || ''}`,
    `To: ${props.message.to || ''}`,
];
const quoteText = computed(() => `On ${originalDate.value}, ${props.message.from || ''} wrote:\n${originalText.value.split('\n').map((line) => `> ${line}`).join('\n')}`);
const quoteHtml = computed(() => `<div><br>On ${escapeHtml(originalDate.value)}, ${escapeHtml(props.message.from)} wrote:<br></div>`
    + `<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">${escapeHtml(originalText.value).replace(/\n/g, '<br>')}</blockquote>`);

const applyMode = (mode) => {
    const original = props.message;
    const base = (original.subject || '').replace(/^(re|fwd?):\s*/i, '');
    if (mode === 'forward') {
        to.value = '';
        cc.value = '';
        subject.value = `Fwd: ${base}`;
        html.value = `<div><br></div><div>${forwardHeader().map(escapeHtml).join('<br>')}<br><br>${escapeHtml(originalText.value).replace(/\n/g, '<br>')}</div>`;
        editingRecipients.value = true;
    }
    else {
        // Replying to your own sent mail goes back to its recipients.
        to.value = fromSelf.value ? (withoutAddress(original.to, props.selfEmail) || original.to || '') : (original.from || '');
        const others = [...splitAddresses(original.to), ...splitAddresses(original.cc)]
            .filter((item) => !sameAddress(item, props.selfEmail) && !sameAddress(item, to.value))
            .join(', ');
        cc.value = mode === 'reply-all' && !fromSelf.value ? others : '';
        subject.value = `Re: ${base}`;
        if (/^-+ Forwarded message/m.test(text.value)) html.value = '';
        editingRecipients.value = false;
    }
    showCc.value = cc.value !== '';
    showQuote.value = false;
};

const focusBody = async () => {
    await nextTick();
    if (props.mode === 'forward') toInput.value?.focus();
    else editor.value?.focus(true);
};

watch(() => props.mode, (mode) => {
    applyMode(mode);
    focusBody();
});
watch(() => `${props.message.folder}:${props.message.uid}`, () => {
    html.value = '';
    applyMode(props.mode);
});
onMounted(() => {
    applyMode(props.mode);
    focusBody();
});

const recipientSummary = computed(() => {
    const names = [...splitAddresses(to.value), ...splitAddresses(cc.value), ...splitAddresses(bcc.value)].map(nameOrEmail);

    return names.length ? names.join(', ') : 'Recipients';
});

const hasDraft = computed(() => text.value.trim() !== '' && props.mode !== 'forward');

const pickMode = (mode) => {
    modeMenu.value = false;
    emit('update:mode', mode);
};

const send = async () => {
    if (sending.value) return;
    if (!to.value.trim() && !cc.value.trim() && !bcc.value.trim()) {
        editingRecipients.value = true;
        emit('notify', 'Add at least one recipient.');
        return;
    }
    if (text.value.trim() === '' && props.mode !== 'forward') {
        emit('notify', 'Write a message first.');
        editor.value?.focus();
        return;
    }

    const quoted = props.mode !== 'forward';
    sending.value = true;
    try {
        const response = await window.axios.post(props.sendEndpoint, {
            to: to.value,
            cc: cc.value,
            bcc: bcc.value,
            subject: subject.value,
            body: quoted ? `${text.value.trimEnd()}\n\n${quoteText.value}` : text.value,
            html: quoted ? `${html.value}${quoteHtml.value}` : html.value,
            folder: props.folder,
            reply_folder: quoted ? (props.message.folder || props.folder) : null,
            reply_uid: quoted ? props.message.uid : null,
        }, { headers: { Accept: 'application/json' } });
        emit('sent', response?.data?.message || 'Message sent.');
    }
    catch (error) {
        emit('notify', error?.response?.data?.message || error?.message || 'Message could not be sent.');
    }
    finally {
        sending.value = false;
    }
};

const discard = () => {
    if (hasDraft.value && !confirm('Discard this reply?')) return;
    emit('discard');
};

const popOut = () => emit('pop-out', {
    to: to.value,
    cc: cc.value,
    bcc: bcc.value,
    subject: subject.value,
    body: props.mode === 'forward' ? text.value : `${text.value.trimEnd()}\n\n${quoteText.value}`,
});
</script>

<template>
    <div class="flex items-start gap-3">
        <MailAvatar
            :email="selfEmail"
            :initials="initialsOf(selfEmail)"
            :color-class="avatarColorOf(selfEmail)"
            :endpoint="avatarEndpoint"
            class="mt-1 hidden h-10 w-10 shrink-0 text-sm sm:flex"
        />

        <section :class="[ui.card, 'min-w-0 flex-1 rounded-2xl border shadow-lg ring-1 ring-blue-500/10']" aria-label="Reply" @keydown.esc.stop="modeMenu = false">
            <!-- Mode + recipients -->
            <div class="flex items-center gap-1 px-3 pt-3">
                <div class="relative">
                    <button type="button" :class="[ui.icon, 'flex h-9 items-center gap-0.5 rounded-full px-2 transition']" :title="currentMode.label" aria-haspopup="menu" :aria-expanded="modeMenu" @click="modeMenu = !modeMenu">
                        <i :class="['bi text-lg', currentMode.icon]"></i><i class="bi bi-caret-down-fill text-[9px]"></i>
                    </button>
                    <div v-if="modeMenu" :class="[ui.menu, 'absolute left-0 top-10 z-30 w-44 rounded-xl border py-1 shadow-xl']" role="menu">
                        <button v-for="item in modes" :key="item.id" type="button" role="menuitem" :class="[ui.menuItem, 'flex w-full items-center gap-3 px-3 py-2 text-left text-sm']" @click="pickMode(item.id)">
                            <i :class="['bi', item.icon]"></i>{{ item.label }}
                            <i v-if="item.id === mode" class="bi bi-check2 ml-auto text-blue-500"></i>
                        </button>
                        <button type="button" role="menuitem" :class="[ui.menuItem, 'flex w-full items-center gap-3 px-3 py-2 text-left text-sm']" @click="modeMenu = false; editingRecipients = true">
                            <i class="bi bi-pencil"></i>Edit subject
                        </button>
                    </div>
                </div>

                <button v-if="!editingRecipients" type="button" :class="[ui.strong, 'min-w-0 flex-1 truncate rounded-md px-2 py-1.5 text-left text-sm hover:bg-black/5 dark:hover:bg-white/5']" title="Edit recipients" @click="editingRecipients = true">
                    {{ recipientSummary }}
                </button>
                <span v-else class="flex-1"></span>

                <span v-if="sandbox" class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800" title="Sandbox mode: only recipients on this server receive this message">Sandbox</span>
                <button type="button" :class="[ui.icon, 'flex h-9 w-9 items-center justify-center rounded-full transition']" title="Pop out reply" @click="popOut">
                    <i class="bi bi-box-arrow-up-right"></i>
                </button>
            </div>

            <!-- Editable recipients and subject -->
            <div v-if="editingRecipients" class="space-y-0.5 px-4 pt-1 text-sm">
                <div :class="[ui.divider, 'flex items-center gap-3 border-b']">
                    <label :class="[ui.muted, 'w-12 shrink-0']" for="reply-to">To</label>
                    <RecipientInput id="reply-to" ref="toInput" v-model="to" :is-dark="isDark" :input-class="[ui.input, 'h-10 w-full border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']" />
                    <button v-if="!showCc" type="button" :class="[ui.muted, 'text-xs font-medium hover:underline']" @click="showCc = true">Cc</button>
                    <button v-if="!showBcc" type="button" :class="[ui.muted, 'text-xs font-medium hover:underline']" @click="showBcc = true">Bcc</button>
                </div>
                <div v-if="showCc" :class="[ui.divider, 'flex items-center gap-3 border-b']">
                    <label :class="[ui.muted, 'w-12 shrink-0']" for="reply-cc">Cc</label>
                    <RecipientInput id="reply-cc" v-model="cc" :is-dark="isDark" :input-class="[ui.input, 'h-10 w-full border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']" />
                </div>
                <div v-if="showBcc" :class="[ui.divider, 'flex items-center gap-3 border-b']">
                    <label :class="[ui.muted, 'w-12 shrink-0']" for="reply-bcc">Bcc</label>
                    <RecipientInput id="reply-bcc" v-model="bcc" :is-dark="isDark" :input-class="[ui.input, 'h-10 w-full border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']" />
                </div>
                <div :class="[ui.divider, 'flex items-center gap-3 border-b']">
                    <label :class="[ui.muted, 'w-12 shrink-0']" for="reply-subject">Subject</label>
                    <input id="reply-subject" v-model="subject" type="text" :class="[ui.input, 'h-10 min-w-0 flex-1 border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']">
                </div>
            </div>

            <!-- Body, folded quote, formatting bar -->
            <RichTextEditor
                ref="editor"
                v-model:html="html"
                v-model:text="text"
                :is-dark="isDark"
                :show-toolbar="showToolbar"
                min-height="11rem"
                placeholder=""
                @submit="send"
            >
                <div v-if="mode !== 'forward'" class="px-4 pb-3">
                    <button type="button" :class="[ui.dots, 'inline-flex h-4 items-center rounded-full px-1.5 text-xs leading-none transition']" :title="showQuote ? 'Hide trimmed content' : 'Show trimmed content'" @click="showQuote = !showQuote">
                        <i class="bi bi-three-dots"></i>
                    </button>
                    <div v-if="showQuote" :class="[ui.quote, 'mt-2 max-h-64 overflow-y-auto whitespace-pre-wrap break-words border-l-2 pl-3 text-sm']">On {{ originalDate }}, {{ message.from }} wrote:
{{ originalText }}</div>
                </div>
            </RichTextEditor>

            <footer class="flex items-center gap-1.5 px-3 pb-3">
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-full bg-blue-600 px-6 text-sm font-semibold text-white shadow-sm shadow-blue-600/30 transition hover:bg-blue-700 hover:shadow-md disabled:cursor-wait disabled:opacity-70"
                    :disabled="sending"
                    @click="send"
                >
                    <i v-if="sending" class="bi bi-arrow-repeat animate-spin"></i>
                    {{ sending ? 'Sending…' : 'Send' }}
                </button>
                <button type="button" :class="[showToolbar ? 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200' : ui.icon, 'flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold transition']" title="Formatting options" :aria-pressed="showToolbar" @click="showToolbar = !showToolbar">
                    Aa
                </button>
                <span :class="[ui.muted, 'ml-1 hidden text-xs sm:inline']">Ctrl + Enter to send</span>
                <button type="button" :class="[ui.icon, 'ml-auto flex h-10 w-10 items-center justify-center rounded-full transition hover:!text-rose-600']" title="Discard" aria-label="Discard reply" @click="discard">
                    <i class="bi bi-trash3"></i>
                </button>
            </footer>
        </section>
    </div>
</template>
