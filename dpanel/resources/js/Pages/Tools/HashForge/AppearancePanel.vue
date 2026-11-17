<script setup>
import { useI18n } from 'vue-i18n';
import { useAppearance, ACCENT_OPTIONS, DENSITY_OPTIONS, RADIUS_OPTIONS, CARD_OPTIONS } from './useAppearance.js';

const { t } = useI18n();
const { appearance, reset } = useAppearance();

// A swatch per accent so the colour choice shows the colour itself.
const SWATCH = {
    teal: 'bg-teal-500', blue: 'bg-blue-500', violet: 'bg-violet-500',
    emerald: 'bg-emerald-500', rose: 'bg-rose-500', amber: 'bg-amber-500', slate: 'bg-slate-500',
};
const segBtn = (active) => [
    'rounded-md px-2 py-1 text-[11px] font-medium capitalize transition',
    active ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200',
];
</script>

<template>
    <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-3 text-xs dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-center justify-between">
            <span class="font-semibold text-slate-700 dark:text-slate-200"><i class="bi bi-sliders mr-1"></i>{{ t('tools.hashForge.appearance') }}</span>
            <button type="button" class="text-[11px] text-slate-400 hover:text-slate-600 hover:underline dark:hover:text-slate-200" @click="reset">{{ t('tools.hashForge.apReset') }}</button>
        </div>

        <div>
            <span class="mb-1 block text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.apAccent') }}</span>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="a in ACCENT_OPTIONS" :key="a" type="button" :title="a"
                    class="h-6 w-6 rounded-full ring-offset-1 transition dark:ring-offset-slate-800"
                    :class="[SWATCH[a], appearance.accent === a ? 'ring-2 ring-slate-400 dark:ring-slate-300' : 'ring-0 hover:scale-110']"
                    @click="appearance.accent = a"></button>
            </div>
        </div>

        <div>
            <span class="mb-1 block text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.apDensity') }}</span>
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-900">
                <button v-for="d in DENSITY_OPTIONS" :key="d" type="button" :class="segBtn(appearance.density === d)" @click="appearance.density = d">{{ t(`tools.hashForge.apD.${d}`) }}</button>
            </div>
        </div>

        <div>
            <span class="mb-1 block text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.apCard') }}</span>
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-900">
                <button v-for="c in CARD_OPTIONS" :key="c" type="button" :class="segBtn(appearance.card === c)" @click="appearance.card = c">{{ t(`tools.hashForge.apC.${c}`) }}</button>
            </div>
        </div>

        <div>
            <span class="mb-1 block text-slate-500 dark:text-slate-400">{{ t('tools.hashForge.apRadius') }}</span>
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-900">
                <button v-for="r in RADIUS_OPTIONS" :key="r" type="button" :class="segBtn(appearance.radius === r)" @click="appearance.radius = r">{{ t(`tools.hashForge.apR.${r}`) }}</button>
            </div>
        </div>

        <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
            <input v-model="appearance.mono" type="checkbox" class="rounded border-slate-300 dark:border-slate-600" />
            {{ t('tools.hashForge.apMono') }}
        </label>
    </div>
</template>
