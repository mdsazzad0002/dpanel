<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CodeBlock from './XSendfile/CodeBlock.vue';
import SendfileExamples from './XSendfile/SendfileExamples.vue';

const { t } = useI18n();

const benefits = computed(() => [
    { icon: 'bi-play-circle', text: t('php.xsendfile.what.p1') },
    { icon: 'bi-cpu', text: t('php.xsendfile.what.p2') },
    { icon: 'bi-shield-lock', text: t('php.xsendfile.what.p3') },
    { icon: 'bi-arrow-repeat', text: t('php.xsendfile.what.p4') },
]);

const steps = computed(() => ['s1', 's2', 's3'].map((key) => t(`php.xsendfile.how.${key}`)));
const rules = computed(() => ['r1', 'r2', 'r3', 'r4', 'r5'].map((key) => t(`php.xsendfile.rules.${key}`, { user: 'USER' })));

const checkCommand = 'curl -s -o /dev/null -D - -H "Range: bytes=0-99" https://example.com/media/12/video';
</script>

<template>
    <Head :title="t('php.xsendfile.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-lg font-semibold">{{ t('php.xsendfile.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('php.xsendfile.subtitle') }}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <i class="bi bi-check-circle"></i>
                    {{ t('php.xsendfile.builtIn') }}
                </span>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6">
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('php.xsendfile.what.title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ t('php.xsendfile.what.body') }}</p>
                <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="item in benefits"
                        :key="item.icon"
                        class="flex gap-3 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60"
                    >
                        <i :class="['bi mt-0.5 text-indigo-600 dark:text-indigo-400', item.icon]"></i>
                        <span class="text-slate-700 dark:text-slate-200">{{ item.text }}</span>
                    </li>
                </ul>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ t('php.xsendfile.how.title') }}</h2>
                    <ol class="mt-4 space-y-3">
                        <li v-for="(step, index) in steps" :key="index" class="flex gap-3 text-sm">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">{{ index + 1 }}</span>
                            <span class="pt-0.5 text-slate-700 dark:text-slate-200">{{ step }}</span>
                        </li>
                    </ol>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ t('php.xsendfile.rules.title') }}</h2>
                    <ul class="mt-4 space-y-2.5">
                        <li v-for="(rule, index) in rules" :key="index" class="flex gap-2.5 text-sm">
                            <i class="bi bi-dot mt-0.5 text-slate-400"></i>
                            <span class="text-slate-700 dark:text-slate-200">{{ rule }}</span>
                        </li>
                    </ul>
                </section>
            </div>

            <SendfileExamples />

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('php.xsendfile.check.title') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ t('php.xsendfile.check.body') }}</p>
                <div class="mt-3">
                    <CodeBlock :code="checkCommand" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('php.xsendfile.check.look') }}</p>
            </section>

            <section class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/40">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-blue-900 dark:text-blue-200">
                    <i class="bi bi-info-circle"></i>
                    {{ t('php.xsendfile.apache.title') }}
                </h2>
                <p class="mt-2 text-sm text-blue-900/80 dark:text-blue-200/80">{{ t('php.xsendfile.apache.body') }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
