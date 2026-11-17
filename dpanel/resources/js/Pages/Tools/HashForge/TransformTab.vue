<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAppearance } from './useAppearance.js';
import { TRANSFORMS, TRANSFORM_MAP, defaultOptions, runChain } from './transforms.js';

const { t } = useI18n();
const { accent, cardClass, valueFont } = useAppearance();

// A pipeline of steps the user builds and reorders; the output of each step
// feeds the next, so transforms combine freely (Base64 → Hex → Caesar …).
const input = ref('');
let uid = 0;
const steps = ref([]);

const addStep = (id) => {
    steps.value.push({ key: ++uid, id, dir: 'forward', opts: defaultOptions(id), show: false });
};
const removeStep = (i) => steps.value.splice(i, 1);
const move = (i, by) => {
    const j = i + by;
    if (j < 0 || j >= steps.value.length) return;
    const list = steps.value;
    [list[i], list[j]] = [list[j], list[i]];
};
const clearAll = () => { steps.value = []; };

const transformOf = (id) => TRANSFORM_MAP[id];
const invertible = (id) => !!TRANSFORM_MAP[id]?.inverse;

const result = computed(() => runChain(input.value, steps.value));
const copied = ref(false);
const copy = async () => {
    try {
        await navigator.clipboard.writeText(result.value.output);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch { /* clipboard blocked on plain http; value stays selectable */ }
};

const label = (id) => t(`tools.hashForge.tf.${id}`);
const optLabel = (id, key) => t(`tools.hashForge.tfOpt.${id}.${key}`);
const choiceLabel = (choice) => t(`tools.hashForge.tfChoice.${choice}`);
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <!-- Build the pipeline -->
        <div :class="['space-y-4', cardClass]">
            <div>
                <label for="hf-tf-in" class="mb-1 block text-sm font-medium">{{ t('tools.hashForge.input') }}</label>
                <textarea id="hf-tf-in" v-model="input" rows="4" spellcheck="false" autocomplete="off"
                    :placeholder="t('tools.hashForge.tfInputPlaceholder')"
                    :class="['w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900', valueFont]"></textarea>
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-sm font-medium">{{ t('tools.hashForge.tfAdd') }}</span>
                    <button v-if="steps.length" type="button" class="text-xs text-slate-500 hover:underline" @click="clearAll">{{ t('tools.hashForge.tfClear') }}</button>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="tr in TRANSFORMS" :key="tr.id" type="button"
                        class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700"
                        @click="addStep(tr.id)">
                        <i class="bi" :class="tr.icon"></i>{{ label(tr.id) }}
                    </button>
                </div>
            </div>

            <!-- Steps -->
            <div v-if="!steps.length" class="rounded-lg border border-dashed border-slate-300 py-8 text-center text-sm text-slate-400 dark:border-slate-600">
                <i class="bi bi-diagram-3 text-2xl"></i>
                <p class="mt-1">{{ t('tools.hashForge.tfEmpty') }}</p>
            </div>
            <ol v-else class="space-y-2">
                <li v-for="(step, i) in steps" :key="step.key"
                    class="rounded-lg border border-slate-200 bg-slate-50/60 p-2.5 dark:border-slate-700 dark:bg-slate-900/40">
                    <div class="flex items-center gap-2">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white" :class="accent.dot">{{ i + 1 }}</span>
                        <i class="bi text-slate-500" :class="transformOf(step.id)?.icon"></i>
                        <span class="flex-1 text-sm font-medium">{{ label(step.id) }}</span>

                        <div v-if="invertible(step.id)" class="inline-flex overflow-hidden rounded-md border border-slate-200 text-[11px] dark:border-slate-600">
                            <button type="button" class="px-2 py-0.5 font-medium" :class="step.dir === 'forward' ? accent.solid : 'text-slate-500'" @click="step.dir = 'forward'">{{ t('tools.hashForge.encode') }}</button>
                            <button type="button" class="px-2 py-0.5 font-medium" :class="step.dir === 'inverse' ? accent.solid : 'text-slate-500'" @click="step.dir = 'inverse'">{{ t('tools.hashForge.decode') }}</button>
                        </div>

                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-200 disabled:opacity-30 dark:hover:bg-slate-700" :disabled="i === 0" :title="t('tools.hashForge.tfMoveUp')" @click="move(i, -1)"><i class="bi bi-chevron-up"></i></button>
                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-200 disabled:opacity-30 dark:hover:bg-slate-700" :disabled="i === steps.length - 1" :title="t('tools.hashForge.tfMoveDown')" @click="move(i, 1)"><i class="bi bi-chevron-down"></i></button>
                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-500/20" :title="t('tools.hashForge.tfRemove')" @click="removeStep(i)"><i class="bi bi-x-lg"></i></button>
                    </div>

                    <!-- Per-step options, from the transform's own schema -->
                    <div v-if="transformOf(step.id)?.options.length" class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 pl-7 text-xs">
                        <template v-for="opt in transformOf(step.id).options" :key="opt.key">
                            <label v-if="opt.type === 'toggle'" class="inline-flex items-center gap-1.5">
                                <input v-model="step.opts[opt.key]" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" :class="accent.ring" />
                                {{ optLabel(step.id, opt.key) }}
                            </label>
                            <label v-else-if="opt.type === 'select'" class="inline-flex items-center gap-1.5">
                                {{ optLabel(step.id, opt.key) }}
                                <select v-model="step.opts[opt.key]" class="rounded-md border-slate-300 py-0.5 pr-7 text-xs dark:border-slate-600 dark:bg-slate-900">
                                    <option v-for="c in opt.choices" :key="c" :value="c">{{ choiceLabel(c) }}</option>
                                </select>
                            </label>
                            <label v-else-if="opt.type === 'number'" class="inline-flex items-center gap-1.5">
                                {{ optLabel(step.id, opt.key) }}
                                <input v-model.number="step.opts[opt.key]" type="number" :min="opt.min" :max="opt.max" class="w-16 rounded-md border-slate-300 py-0.5 text-xs dark:border-slate-600 dark:bg-slate-900" />
                            </label>
                        </template>
                    </div>

                    <p v-if="!result.trail[i]?.ok && input" class="mt-1.5 pl-7 text-xs text-red-500">
                        <i class="bi bi-exclamation-triangle mr-1"></i>{{ t('tools.hashForge.tfStepFailed') }}
                    </p>
                </li>
            </ol>
        </div>

        <!-- Output -->
        <section :class="['space-y-3', cardClass]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ t('tools.hashForge.tfOutput') }}</h2>
                <button v-if="result.output" type="button" class="text-xs hover:underline" :class="accent.text" @click="copy">
                    <i class="bi" :class="copied ? 'bi-check2' : 'bi-clipboard'"></i>
                    {{ copied ? t('tools.hashForge.copied') : t('tools.hashForge.copy') }}
                </button>
            </div>

            <p class="text-xs text-slate-400"><i class="bi bi-laptop mr-1"></i>{{ t('tools.hashForge.localOnly') }}</p>

            <div v-if="!steps.length || !input" class="py-10 text-center text-sm text-slate-400">
                <i class="bi bi-magic text-3xl"></i>
                <p class="mt-2">{{ t('tools.hashForge.tfOutputEmpty') }}</p>
            </div>
            <template v-else>
                <code :class="['block min-h-[3rem] break-all whitespace-pre-wrap rounded-lg bg-slate-50 px-3 py-2 text-sm select-all dark:bg-slate-900', valueFont]">{{ result.output }}</code>

                <!-- Each step's intermediate output, so a long chain is legible -->
                <details class="text-xs">
                    <summary class="cursor-pointer text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">{{ t('tools.hashForge.tfShowSteps') }}</summary>
                    <ol class="mt-2 space-y-1.5">
                        <li v-for="(stepResult, i) in result.trail" :key="i" class="rounded-md bg-slate-50 px-2 py-1 dark:bg-slate-900/60">
                            <span class="text-[11px] font-semibold text-slate-400">{{ i + 1 }}. {{ label(steps[i].id) }}</span>
                            <code v-if="stepResult.ok" :class="['mt-0.5 block break-all whitespace-pre-wrap text-[11px]', valueFont]">{{ stepResult.value }}</code>
                            <span v-else class="text-[11px] text-red-500">{{ t('tools.hashForge.tfStepFailed') }}</span>
                        </li>
                    </ol>
                </details>
            </template>
        </section>
    </div>
</template>
