<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    code: { type: String, required: true },
});

const copied = ref(false);

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.code);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <div class="relative">
        <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 pr-20 text-xs leading-relaxed text-slate-100"><code>{{ code }}</code></pre>
        <button
            type="button"
            class="absolute right-3 top-3 rounded bg-slate-700 px-2 py-1 text-xs text-white transition hover:bg-slate-600"
            @click="copy"
        >
            {{ copied ? t('php.xsendfile.copied') : t('php.xsendfile.copy') }}
        </button>
    </div>
</template>
