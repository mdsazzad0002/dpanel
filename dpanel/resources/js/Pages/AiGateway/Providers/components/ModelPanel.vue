<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import ModelPickerModal from './ModelPickerModal.vue';
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Add or edit one model. `model` null = add (for `providerId`).
const props = defineProps({
    show: { type: Boolean, default: false },
    model: { type: Object, default: null },
    providerId: { type: [Number, String], default: null },
    providers: { type: Array, default: () => [] },
    remote: { type: Object, required: true }, // useRemoteModels()
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const CAPABILITIES = computed(() => [
    { value: 'chat', label: 'chat', hint: t('aiGateway.models.cap.chat') },
    { value: 'vision', label: 'vision', hint: t('aiGateway.models.cap.vision') },
    { value: 'tools', label: 'tools', hint: t('aiGateway.models.cap.tools') },
    { value: 'code', label: 'code', hint: t('aiGateway.models.cap.code') },
    { value: 'image_generation', label: 'image generation', hint: t('aiGateway.models.cap.image') },
    { value: 'transcription', label: 'transcription', hint: t('aiGateway.models.cap.transcription') },
]);

const blank = () => ({ provider_id: '', name: '', display_name: '', context_window: 0, max_output_tokens: 0, capabilities: [], is_active: true });
const form = useForm(blank());

watch(() => props.show, (open) => {
    if (!open) return;
    const m = props.model;
    form.defaults(m ? {
        provider_id: m.provider_id,
        name: m.name,
        display_name: m.display_name || '',
        context_window: m.context_window || 0,
        max_output_tokens: m.max_output_tokens || 0,
        capabilities: [...(m.capabilities || [])],
        is_active: !!m.is_active,
    } : { ...blank(), provider_id: props.providerId || '' });
    form.reset();
    form.clearErrors();
}, { immediate: true });

const info = computed(() => props.remote.infoFor(form.provider_id));
watch(() => [props.show, form.provider_id], ([open, id]) => { if (open && id) props.remote.fetchFor(id); }, { immediate: true });

// Typing a known model ID fills its metadata; hand-edited fields stay.
const onNameInput = () => {
    const known = info.value.models.find((s) => s.name === form.name);
    if (!known) return;
    form.display_name = known.display_name || form.name;
    if (!form.context_window) form.context_window = known.context_window || 0;
    if (!form.max_output_tokens) form.max_output_tokens = known.max_output_tokens || 0;
    if (!form.capabilities.length && known.capabilities?.length) form.capabilities = [...known.capabilities];
};

const pickerOpen = ref(false);
const pick = (m) => {
    form.name = m.name;
    form.display_name = m.display_name || m.name;
    form.context_window = m.context_window || 0;
    form.max_output_tokens = m.max_output_tokens || 0;
    if (Array.isArray(m.capabilities)) form.capabilities = [...m.capabilities];
    pickerOpen.value = false;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    if (props.model) form.patch(props.panelRoute('ai-gateway.models.update', { model: props.model.id }), opts);
    else form.post(props.panelRoute('ai-gateway.models.store'), opts);
};

const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900';
</script>

<template>
    <Offcanvas :show="show" width="lg" :close-on-escape="!pickerOpen"
        :title="model ? t('aiGateway.pm.editModel') : t('aiGateway.models.add')"
        :subtitle="model ? model.name : ''" @close="emit('close')">
        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.models.col.provider') }}</label>
                <select v-model.number="form.provider_id" :class="input">
                    <option value="">{{ t('aiGateway.models.select') }}</option>
                    <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <div v-if="form.errors.provider_id" class="mt-1 text-xs text-red-600">{{ form.errors.provider_id }}</div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.models.modelId') }}</label>
                <div class="flex gap-1">
                    <input v-model="form.name" type="text" list="model-panel-suggestions" @input="onNameInput" placeholder="gpt-4o" :class="input" />
                    <button type="button" @click="pickerOpen = true" :disabled="!form.provider_id" :title="t('aiGateway.models.browseHint')"
                        class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.models.browse') }}</button>
                </div>
                <datalist id="model-panel-suggestions">
                    <option v-for="s in info.models" :key="s.name" :value="s.name">{{ s.display_name || s.name }}</option>
                </datalist>
                <div v-if="info.loading" class="mt-1 text-xs text-slate-400">{{ t('aiGateway.models.loadingRemote') }}</div>
                <div v-else-if="info.source === 'seed' && form.provider_id" class="mt-1 text-xs text-amber-600" :title="info.message || ''">{{ t('aiGateway.models.localList') }}</div>
                <div v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.models.displayName') }}</label>
                <input v-model="form.display_name" type="text" :class="input" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.models.col.context') }}</label>
                    <input v-model.number="form.context_window" type="number" min="0" :class="input" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.models.col.maxOutput') }}</label>
                    <input v-model.number="form.max_output_tokens" type="number" min="0" :class="input" />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('aiGateway.models.col.capabilities') }}</label>
                <div class="grid grid-cols-2 gap-1 text-sm">
                    <label v-for="c in CAPABILITIES" :key="c.value" class="flex items-center gap-2" :title="c.hint">
                        <input v-model="form.capabilities" type="checkbox" :value="c.value" class="rounded border-slate-300" />
                        {{ c.label }}
                    </label>
                </div>
                <div v-if="form.errors.capabilities" class="mt-1 text-xs text-red-600">{{ form.errors.capabilities }}</div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                {{ t('aiGateway.models.active') }}
            </label>

            <div class="flex items-center gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ form.processing ? t('aiGateway.providers.form.saving') : (model ? t('common.save') : t('aiGateway.models.add')) }}
                </button>
                <button type="button" @click="emit('close')" class="text-sm text-slate-500 hover:underline">{{ t('common.cancel') }}</button>
            </div>
        </form>
    </Offcanvas>

    <ModelPickerModal :show="pickerOpen" :info="info" @close="pickerOpen = false" @pick="pick" />
</template>
