<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    memories: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const search = useForm({ q: props.filters.q || '' });
const form = useForm({ title: '', command: '', context: '', success_output_sample: '', error_signature: '', category: '', tags: [] });

const runSearch = () => router.get(route('ssh-memories.index'), search.data(), { preserveState: true, preserveScroll: true });
const submit = () => form.post(route('ssh-memories.store'), { onSuccess: () => form.reset() });
const mark = (memory, result) => router.post(route('ssh-memories.mark-useful', memory.id), { result });
const remove = (memory) => router.delete(route('ssh-memories.destroy', memory.id));
</script>

<template>
    <Head title="SSH Memories" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-lg font-semibold">SSH Memories</h1></template>

        <div class="grid gap-4 xl:grid-cols-[1.1fr_2fr]">
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Save Useful Command</h2>
                <form class="mt-3 space-y-2" @submit.prevent="submit">
                    <input v-model="form.title" type="text" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="Title" />
                    <textarea v-model="form.command" rows="3" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono" placeholder="Command" />
                    <textarea v-model="form.context" rows="2" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="Context" />
                    <input v-model="form.error_signature" type="text" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="Error signature" />
                    <input v-model="form.category" type="text" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="Category" />
                    <button class="rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white">Save Memory</button>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <form class="mb-3 flex gap-2" @submit.prevent="runSearch">
                    <input v-model="search.q" type="text" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="Search by tag/error/command" />
                    <button class="rounded border border-slate-300 px-3 py-2 text-sm">Search</button>
                </form>

                <div class="space-y-2 text-xs">
                    <article v-for="memory in memories.data" :key="memory.id" class="rounded border border-slate-200 p-3">
                        <p class="font-semibold">{{ memory.title }}</p>
                        <p class="mt-1 font-mono">{{ memory.command }}</p>
                        <p class="text-slate-500">signature: {{ memory.error_signature || '-' }} | success: {{ memory.success_count }} | fail: {{ memory.fail_count }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button class="rounded border border-slate-300 px-2 py-1" @click="mark(memory, 'worked')">Mark worked</button>
                            <button class="rounded border border-slate-300 px-2 py-1" @click="mark(memory, 'failed')">Mark failed</button>
                            <button class="rounded border border-red-300 px-2 py-1 text-red-700" @click="remove(memory)">Delete</button>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
