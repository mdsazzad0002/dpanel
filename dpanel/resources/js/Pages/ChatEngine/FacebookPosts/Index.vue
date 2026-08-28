<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    pages: { type: Array, default: () => [] },
    selectedPageId: { type: String, default: '' },
    threads: { type: Array, default: () => [] },
});

const form = useForm({
    chat_channel_id: props.selectedPageId || props.pages[0]?.id || '',
    message: '',
    link: '',
    first_comment: '',
});

const remaining = computed(() => 63206 - form.message.length);
const commentCount = computed(() => props.threads.reduce((total, thread) => total + (thread.comments?.length || 0), 0));
const composerOpen = ref(false);
const publish = () => form.post(panelRoute('chat-engine.facebook-posts.store'), {
    preserveScroll: true,
    onSuccess: () => {
        composerOpen.value = false;
        form.reset('message', 'link', 'first_comment');
    },
});

const historyPageId = ref(props.selectedPageId || props.pages[0]?.id || '');
const choosePage = (pageId) => {
    if (pageId === historyPageId.value) return;

    historyPageId.value = pageId;
    router.get(
        panelRoute('chat-engine.facebook-posts.index'),
        { page: historyPageId.value },
        { preserveState: false, preserveScroll: true, replace: true },
    );
};

const commentingPost = ref('');
const commentForm = useForm({ chat_channel_id: props.selectedPageId || '', post_id: '', message: '' });
const openComment = (thread) => {
    commentingPost.value = thread.external_post_id;
    commentForm.chat_channel_id = props.selectedPageId;
    commentForm.post_id = thread.external_post_id;
    commentForm.message = '';
    commentForm.clearErrors();
};
const sendComment = () => commentForm.post(panelRoute('chat-engine.facebook-posts.comments.store'), {
    preserveScroll: true,
    onSuccess: () => {
        commentingPost.value = '';
        commentForm.reset('post_id', 'message');
    },
});

</script>

<template>
    <Head title="Facebook Posts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm"><i class="bi bi-facebook"></i></span>
                        <div>
                            <h1 class="text-lg font-semibold">Facebook Publisher</h1>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Posts, comments and webhook activity in one place.</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <ChatEngineDocsButton title="Facebook Publisher — Guide" :sections="['facebook-posts', 'facebook-pages']" />
                    <button v-if="pages.length" type="button" @click="composerOpen = true" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:shadow-xl"><i class="bi bi-plus-lg"></i> Create Post</button>
                </div>
            </div>
        </template>

        <div class="mx-auto  space-y-5">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
                <a v-if="page.props.flash?.facebook_post_url" :href="page.props.flash.facebook_post_url" target="_blank" rel="noopener" class="ml-1 font-medium underline">View post</a>
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!pages.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                Connect and activate a Facebook Page first.
            </div>

            <div v-if="pages.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Connected pages</p>
                    <p class="mt-1 text-2xl font-bold text-slate-800 dark:text-white">{{ pages.length }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Posts shown</p>
                    <p class="mt-1 text-2xl font-bold text-violet-600">{{ threads.length }}</p>
                </div>
                <div class="col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:col-span-1 dark:border-slate-700 dark:bg-slate-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Comments shown</p>
                    <p class="mt-1 text-2xl font-bold text-blue-600">{{ commentCount }}</p>
                </div>
            </div>

            <section v-if="pages.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="grid lg:grid-cols-[240px_minmax(0,1fr)]">
                    <aside class="border-b border-slate-200 bg-slate-50/70 p-3 lg:border-b-0 lg:border-r dark:border-slate-700 dark:bg-slate-900/30">
                        <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Facebook Pages</p>
                        <div class="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible" role="tablist" aria-label="Facebook Pages">
                            <button
                                v-for="item in pages"
                                :key="item.id"
                                type="button"
                                role="tab"
                                :aria-selected="historyPageId === item.id"
                                @click="choosePage(item.id)"
                                class="group flex min-w-max items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition lg:min-w-0 lg:w-full"
                                :class="historyPageId === item.id
                                    ? 'bg-blue-600 font-semibold text-white shadow-md shadow-blue-600/20'
                                    : 'text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm dark:text-slate-300 dark:hover:bg-slate-800'"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="historyPageId === item.id ? 'bg-white/20' : 'bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300'">
                                    <i class="bi bi-facebook"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate">{{ item.name }}</span>
                                    <span
                                        class="mt-0.5 block truncate text-xs font-normal"
                                        :class="historyPageId === item.id ? 'text-blue-100' : 'text-slate-400 dark:text-slate-500'"
                                    >
                                        Page ID: {{ item.external_account_id }}
                                    </span>
                                </span>
                            </button>
                        </div>
                    </aside>

                    <div class="min-w-0 p-4 sm:p-5">
                        <div class="mb-5">
                            <h2 class="flex items-center gap-2 font-semibold"><i class="bi bi-chat-square-text text-blue-500"></i> Posts &amp; comments</h2>
                            <p class="text-xs text-slate-500">Comments stay connected below their parent post for 30 days.</p>
                        </div>

                <div v-if="!threads.length" class="rounded-2xl border border-dashed border-slate-300 bg-white/50 p-12 text-center dark:border-slate-700 dark:bg-slate-800/50">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-blue-50 text-2xl text-blue-500 dark:bg-blue-950/40"><i class="bi bi-file-earmark-post"></i></span>
                    <p class="mt-3 font-medium text-slate-700 dark:text-slate-200">No posts yet</p>
                    <p class="mt-1 text-sm text-slate-500">Create a post or wait for Facebook webhook activity.</p>
                </div>

                <article v-for="thread in threads" :key="thread.id" class="mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition last:mb-0 hover:shadow-md dark:border-slate-700 dark:bg-slate-800">
                    <div class="p-5">
                        <div class="mb-2 flex items-center justify-between gap-3 text-xs text-slate-400">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-2.5 py-1 font-semibold text-violet-700 dark:bg-violet-950/50 dark:text-violet-300"><i class="bi bi-file-post"></i> Post · {{ thread.source }}</span>
                            <span>{{ thread.occurred_at ? new Date(thread.occurred_at).toLocaleString() : '' }}</span>
                        </div>
                        <p class="whitespace-pre-wrap text-[15px] leading-6 text-slate-700 dark:text-slate-200">{{ thread.message || '(Parent Facebook post)' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-4 border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-700">
                        <a v-if="thread.permalink_url" :href="thread.permalink_url" target="_blank" rel="noopener" class="ml-4 text-blue-600 hover:underline">View on Facebook</a>
                        <button type="button" @click="openComment(thread)" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700"><i class="bi bi-chat-left-text"></i> Add comment</button>
                        <span class="inline-flex items-center gap-1"><i class="bi bi-chat-dots"></i> {{ thread.comments.length }} comment(s)</span>
                    </div>

                    <div v-if="thread.comments.length || commentingPost === thread.external_post_id" class="border-t border-slate-200 bg-slate-50/80 px-5 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                        <div v-for="comment in thread.comments" :key="comment.id" class="mb-2 ml-3 rounded-2xl rounded-tl-sm border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="font-medium text-slate-600 dark:text-slate-300">{{ comment.actor_name || 'Facebook user' }}</span>
                                <span class="text-slate-400">{{ comment.occurred_at ? new Date(comment.occurred_at).toLocaleString() : '' }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">{{ comment.message || '(No text)' }}</p>
                        </div>

                        <form v-if="commentingPost === thread.external_post_id" @submit.prevent="sendComment" class="mt-4 flex items-end gap-2 rounded-2xl border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-900 dark:bg-blue-950/20">
                            <textarea v-model="commentForm.message" rows="2" maxlength="8000" placeholder="Comment manually as the selected Page…" class="min-w-0 flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                            <button type="submit" :disabled="commentForm.processing || !commentForm.message.trim()" class="inline-flex h-10 items-center gap-1 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"><i class="bi bi-send"></i> Send</button>
                        </form>
                        <p v-if="commentingPost === thread.external_post_id && commentForm.errors.message" class="mt-1 text-xs text-red-600">{{ commentForm.errors.message }}</p>
                    </div>
                </article>
                    </div>
                </div>
            </section>
        </div>

        <Offcanvas :show="composerOpen" title="Create Facebook Post" subtitle="Publish a post and optional initial comment" width="lg" @close="composerOpen = false">
            <form @submit.prevent="publish" class="space-y-5">
                <div>
                    <label class="mb-1 block text-sm font-medium">Facebook Page</label>
                    <select v-model="form.chat_channel_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="item in pages" :key="item.id" :value="item.id">{{ item.name }} ({{ item.external_account_id }})</option>
                    </select>
                    <p v-if="form.errors.chat_channel_id" class="mt-1 text-xs text-red-600">{{ form.errors.chat_channel_id }}</p>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="block text-sm font-medium">Post content</label>
                        <span class="text-xs text-slate-400">{{ remaining.toLocaleString() }} remaining</span>
                    </div>
                    <textarea v-model="form.message" rows="8" maxlength="63206" placeholder="What do you want to share?" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    <p v-if="form.errors.message" class="mt-1 text-xs text-red-600">{{ form.errors.message }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Link <span class="font-normal text-slate-400">(optional)</span></label>
                    <input v-model="form.link" type="url" placeholder="https://example.com/article" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <p v-if="form.errors.link" class="mt-1 text-xs text-red-600">{{ form.errors.link }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Initial comment <span class="font-normal text-slate-400">(optional)</span></label>
                    <textarea v-model="form.first_comment" rows="4" maxlength="8000" placeholder="Publish this as a Page comment immediately after the post…" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    <p class="mt-1 text-xs text-slate-400">The post is created first, then this comment is attached automatically.</p>
                    <p v-if="form.errors.first_comment" class="mt-1 text-xs text-red-600">{{ form.errors.first_comment }}</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <button type="button" @click="composerOpen = false" class="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">Cancel</button>
                    <button type="submit" :disabled="form.processing || !form.message.trim()" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white disabled:opacity-50">{{ form.processing ? 'Publishing…' : 'Publish now' }}</button>
                </div>
            </form>
        </Offcanvas>
    </AuthenticatedLayout>
</template>
