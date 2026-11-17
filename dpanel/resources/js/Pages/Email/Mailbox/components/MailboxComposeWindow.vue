<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import RichTextEditor from './RichTextEditor.vue';
import RecipientInput from './RecipientInput.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    isDark: {
        type: Boolean,
        default: false,
    },
    sending: {
        type: Boolean,
        default: false,
    },
    fromEmail: {
        type: String,
        default: '',
    },
    sandbox: {
        type: Boolean,
        default: false,
    },
});

const to = defineModel('to', { type: String, default: '' });
const cc = defineModel('cc', { type: String, default: '' });
const bcc = defineModel('bcc', { type: String, default: '' });
const subject = defineModel('subject', { type: String, default: '' });
const body = defineModel('body', { type: String, default: '' });
const html = defineModel('html', { type: String, default: '' });

const emit = defineEmits(['update:open', 'send', 'discard']);

const minimized = ref(false);
const maximized = ref(false);
const ccRequested = ref(false);
const bccRequested = ref(false);
const toInput = ref(null);
const bodyInput = ref(null);
const showToolbar = ref(true);

const showCc = computed(() => ccRequested.value || cc.value.trim() !== '');
const showBcc = computed(() => bccRequested.value || bcc.value.trim() !== '');
const hasDraft = computed(() => [to.value, cc.value, bcc.value, subject.value, body.value].some((value) => String(value || '').trim() !== ''));
const title = computed(() => subject.value.trim() || t('email.mailbox.composer.newMessage'));
const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform || navigator.userAgent || '');

const ui = computed(() => (props.isDark
    ? {
        window: 'border-slate-700 bg-slate-900 shadow-black/50',
        header: 'bg-slate-800 text-slate-100',
        headerButton: 'text-slate-300 hover:bg-slate-700 hover:text-white',
        field: 'border-slate-800',
        label: 'text-slate-500',
        input: 'text-slate-100 placeholder:text-slate-500',
        link: 'text-slate-400 hover:text-slate-100',
        footer: 'border-slate-800',
        iconButton: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
        hint: 'text-slate-500',
    }
    : {
        window: 'border-slate-200 bg-white shadow-slate-900/20',
        header: 'bg-slate-100 text-slate-900',
        headerButton: 'text-slate-500 hover:bg-slate-200 hover:text-slate-900',
        field: 'border-slate-100',
        label: 'text-slate-500',
        input: 'text-slate-900 placeholder:text-slate-400',
        link: 'text-slate-500 hover:text-slate-900',
        footer: 'border-slate-100',
        iconButton: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900',
        hint: 'text-slate-400',
    }));

const focusFirstField = async () => {
    await nextTick();
    if (to.value.trim() === '') toInput.value?.focus?.();
    else bodyInput.value?.focus?.(true);
};

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        // Plain-text drafts (a popped-out reply) become editor content.
        if (html.value === '' && body.value.trim() !== '') {
            html.value = body.value.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]).replace(/\n/g, '<br>');
        }
        minimized.value = false;
        ccRequested.value = false;
        bccRequested.value = false;
        focusFirstField();
    }
    else {
        maximized.value = false;
    }
});

const openCompose = () => emit('update:open', true);
const close = () => emit('update:open', false);
const restore = () => {
    minimized.value = false;
    focusFirstField();
};
const toggleMinimize = () => {
    if (minimized.value) {
        restore();
        return;
    }
    minimized.value = true;
    maximized.value = false;
};
const toggleMaximize = () => {
    maximized.value = !maximized.value;
    minimized.value = false;
};

const send = () => {
    if (!props.sending) emit('send');
};

const discard = () => {
    if (hasDraft.value && !confirm(t('email.mailbox.composer.discardDraft'))) return;
    emit('discard');
};

const handleWindowKeydown = (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        send();
    }
    else if (event.key === 'Escape') {
        event.stopPropagation();
        if (maximized.value) maximized.value = false;
        else minimized.value = true;
    }
};

// "c" opens compose from anywhere, like Gmail.
const handleDocumentKeydown = (event) => {
    if (props.open || event.ctrlKey || event.metaKey || event.altKey || event.key !== 'c') return;
    const tag = String(event.target?.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.target?.isContentEditable) return;
    event.preventDefault();
    openCompose();
};

onMounted(() => document.addEventListener('keydown', handleDocumentKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', handleDocumentKeydown));
</script>

<template>
    <!-- Floating compose button -->
    <transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="scale-75 opacity-0"
        leave-active-class="transition duration-100 ease-in"
        leave-to-class="scale-75 opacity-0"
    >
        <button
            v-if="!open"
            type="button"
            class="fixed bottom-5 right-5 z-[52] flex h-14 items-center gap-2.5 rounded-2xl bg-blue-600 pl-4 pr-5 text-sm font-semibold text-white shadow-lg shadow-blue-600/30 transition hover:bg-blue-700 hover:shadow-xl hover:shadow-blue-600/30 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/40 max-sm:w-14 max-sm:justify-center max-sm:px-0"
            :title="t('email.mailbox.composer.composeKey')"
            :aria-label="t('email.mailbox.compose')"
            @click="openCompose"
        >
            <i class="bi bi-pencil-square text-lg"></i>
            <span class="max-sm:hidden">{{ hasDraft ? t('email.mailbox.composer.draft') : t('email.mailbox.compose') }}</span>
            <span v-if="hasDraft" class="absolute -right-1 -top-1 h-3.5 w-3.5 rounded-full bg-amber-400 ring-2 ring-white dark:ring-slate-950"></span>
        </button>
    </transition>

    <!-- Backdrop when maximized -->
    <transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-100" leave-to-class="opacity-0">
        <div v-if="open && maximized" class="fixed inset-0 z-[54] bg-slate-950/50" @click="maximized = false"></div>
    </transition>

    <!-- Compose window: grows out of the button's corner -->
    <transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-4 scale-90 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="translate-y-4 scale-90 opacity-0"
    >
        <section
            v-if="open"
            role="dialog"
            :aria-label="t('email.mailbox.composer.dialog')"
            :class="[
                ui.window,
                'fixed z-[55] flex origin-bottom-right flex-col overflow-hidden border shadow-2xl transition-[width,height,inset] duration-200',
                minimized
                    ? 'bottom-0 right-5 h-11 w-72 rounded-t-xl'
                    : maximized
                        ? 'inset-3 rounded-xl md:inset-x-[max(1.5rem,calc((100vw-72rem)/2))] md:inset-y-8'
                        : 'bottom-5 right-5 h-[min(760px,calc(100vh-2.5rem))] w-[min(720px,calc(100vw-2.5rem))] rounded-2xl max-sm:inset-0 max-sm:h-auto max-sm:w-auto max-sm:rounded-none',
            ]"
            @keydown="handleWindowKeydown"
        >
            <!-- Header -->
            <header
                :class="[ui.header, 'flex h-11 shrink-0 cursor-pointer select-none items-center gap-2 pl-4 pr-1.5']"
                @click="minimized ? restore() : null"
            >
                <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ title }}</p>
                <button type="button" :class="[ui.headerButton, 'flex h-8 w-8 items-center justify-center rounded-md transition']" :title="minimized ? t('email.mailbox.composer.restore') : t('email.mailbox.composer.minimize')" :aria-label="minimized ? t('email.mailbox.composer.restore') : t('email.mailbox.composer.minimize')" @click.stop="toggleMinimize">
                    <i :class="['bi text-sm', minimized ? 'bi-chevron-up' : 'bi-dash-lg']"></i>
                </button>
                <button type="button" :class="[ui.headerButton, 'flex h-8 w-8 items-center justify-center rounded-md transition max-sm:hidden']" :title="maximized ? t('email.mailbox.composer.exitFull') : t('email.mailbox.composer.full')" :aria-label="maximized ? t('email.mailbox.composer.exitFull') : t('email.mailbox.composer.full')" @click.stop="toggleMaximize">
                    <i :class="['bi text-sm', maximized ? 'bi-fullscreen-exit' : 'bi-arrows-angle-expand']"></i>
                </button>
                <button type="button" :class="[ui.headerButton, 'flex h-8 w-8 items-center justify-center rounded-md transition']" :title="t('email.mailbox.composer.closeKeep')" :aria-label="t('common.close')" @click.stop="close">
                    <i class="bi bi-x-lg text-sm"></i>
                </button>
            </header>

            <form v-show="!minimized" class="flex min-h-0 flex-1 flex-col" @submit.prevent="send">
                <!-- From -->
                <div v-if="fromEmail" :class="[ui.field, 'flex items-center gap-3 border-b px-4 py-2 text-sm']">
                    <span :class="[ui.label, 'w-14 shrink-0']">{{ t('email.mailbox.composer.from') }}</span>
                    <span :class="[ui.input, 'truncate']">{{ fromEmail }}</span>
                    <span
                        v-if="sandbox"
                        class="ml-auto shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800"
                        :title="t('email.mailbox.composer.sandboxTitle')"
                    >{{ t('email.mailbox.composer.sandbox') }}</span>
                </div>

                <!-- To -->
                <div :class="[ui.field, 'flex items-center gap-3 border-b px-4 text-sm']">
                    <label for="compose-to" :class="[ui.label, 'w-14 shrink-0']">{{ t('email.mailbox.composer.to') }}</label>
                    <RecipientInput
                        id="compose-to"
                        ref="toInput"
                        v-model="to"
                        :is-dark="isDark"
                        :input-class="[ui.input, 'h-11 w-full border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']"
                        :placeholder="t('email.mailbox.composer.nameOrEmail')"
                    />
                    <button v-if="!showCc" type="button" :class="[ui.link, 'text-xs font-medium transition']" @click="ccRequested = true">Cc</button>
                    <button v-if="!showBcc" type="button" :class="[ui.link, 'text-xs font-medium transition']" @click="bccRequested = true">Bcc</button>
                </div>

                <div v-if="showCc" :class="[ui.field, 'flex items-center gap-3 border-b px-4 text-sm']">
                    <label for="compose-cc" :class="[ui.label, 'w-14 shrink-0']">Cc</label>
                    <RecipientInput id="compose-cc" v-model="cc" :is-dark="isDark" :input-class="[ui.input, 'h-11 w-full border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']" :placeholder="t('email.mailbox.composer.separate')" />
                </div>

                <div v-if="showBcc" :class="[ui.field, 'flex items-center gap-3 border-b px-4 text-sm']">
                    <label for="compose-bcc" :class="[ui.label, 'w-14 shrink-0']">Bcc</label>
                    <RecipientInput id="compose-bcc" v-model="bcc" :is-dark="isDark" :input-class="[ui.input, 'h-11 w-full border-0 bg-transparent p-0 text-sm outline-none focus:ring-0']" :placeholder="t('email.mailbox.composer.hidden')" />
                </div>

                <!-- Subject -->
                <div :class="[ui.field, 'flex items-center gap-3 border-b px-4 text-sm']">
                    <label for="compose-subject" class="sr-only">{{ t('email.mailbox.composer.subject') }}</label>
                    <input
                        id="compose-subject"
                        v-model="subject"
                        type="text"
                        :class="[ui.input, 'h-10 min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-medium outline-none focus:ring-0']"
                        :placeholder="t('email.mailbox.composer.subject')"
                    >
                </div>

                <!-- Body -->
                <RichTextEditor
                    ref="bodyInput"
                    v-model:html="html"
                    v-model:text="body"
                    :is-dark="isDark"
                    :show-toolbar="showToolbar"
                    min-height="8rem"
                    @submit="send"
                />

                <!-- Footer -->
                <footer :class="[ui.footer, 'flex shrink-0 items-center gap-3 border-t px-3 py-2.5']">
                    <button
                        type="submit"
                        class="inline-flex h-10 items-center gap-2 rounded-full bg-blue-600 px-6 text-sm font-semibold text-white shadow-sm shadow-blue-600/30 transition hover:bg-blue-700 hover:shadow-md disabled:cursor-wait disabled:opacity-70"
                        :disabled="sending"
                    >
                        <i :class="['bi', sending ? 'bi-arrow-repeat animate-spin' : 'bi-send']"></i>
                        {{ sending ? t('email.mailbox.composer.sending') : t('email.mailbox.composer.send') }}
                    </button>
                    <button
                        type="button"
                        :class="[showToolbar ? 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200' : ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold transition']"
                        :title="t('email.mailbox.composer.formatting')"
                        :aria-pressed="showToolbar"
                        @click="showToolbar = !showToolbar"
                    >Aa</button>
                    <span :class="[ui.hint, 'hidden text-xs sm:inline']">{{ isMac ? '⌘' : 'Ctrl' }} + Enter</span>

                    <button
                        type="button"
                        :class="[ui.iconButton, 'ml-auto flex h-9 w-9 items-center justify-center rounded-md transition hover:!text-rose-600']"
                        :title="t('email.mailbox.composer.discardDraftLabel')"
                        :aria-label="t('email.mailbox.composer.discardDraftLabel')"
                        @click="discard"
                    >
                        <i class="bi bi-trash3"></i>
                    </button>
                </footer>
            </form>
        </section>
    </transition>
</template>
