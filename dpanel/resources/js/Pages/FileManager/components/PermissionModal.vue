<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'permissions'" class="space-y-3">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Presets</p>
                <span v-if="fm.permissionPreview" class="rounded-full px-2 py-0.5 text-[11px]" :class="fm.permissionPreview.dangerous ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'">
                    {{ fm.permissionPreview.dangerous ? 'High risk' : 'Safe' }}
                </span>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    v-for="preset in [
                        { value: '644', note: 'Owner RW, others R' },
                        { value: '755', note: 'Owner All, others RX' },
                        { value: '775', note: 'Group writable' },
                        { value: '777', note: 'World writable' },
                        { value: '600', note: 'Owner only' },
                        { value: '640', note: 'Owner RW, group R' },
                        { value: '700', note: 'Owner only (exec)' },
                        { value: '0755', note: 'Leading zero' },
                    ]"
                    :key="preset.value"
                    type="button"
                    class="rounded-md border px-3 py-2 text-left text-xs transition hover:shadow-sm"
                    :class="fm.permissionDigits === preset.value
                        ? 'border-blue-400 bg-blue-50 text-blue-700 dark:border-blue-600 dark:bg-blue-900/20 dark:text-blue-300'
                        : preset.value === '777'
                            ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'
                            : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                    @click="fm.setPermissionPreset(preset.value)"
                >
                    <span class="block font-mono text-sm font-semibold">{{ preset.value }}</span>
                    <span class="block truncate text-[11px] opacity-80">{{ preset.note }}</span>
                </button>
            </div>
        </div>

        <div class="space-y-2">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Custom Permission</label>
            <input
                v-model="fm.permissionForm.permissions"
                type="text"
                inputmode="numeric"
                maxlength="4"
                placeholder="644"
                class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm tracking-wider dark:border-slate-700 dark:bg-slate-800"
                @input="fm.sanitizePermissionInput"
            />
            <p class="text-[11px] text-slate-500 dark:text-slate-400">Use 3 or 4 digits like `644`, `755`, or `0777`.</p>
        </div>

        <div class="rounded-lg border p-3 text-sm" :class="fm.permissionPreviewClass">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <p class="text-xs uppercase tracking-wide opacity-80">Live Preview</p>
                    <p class="font-mono text-lg font-semibold">{{ fm.permissionPreview?.display || '---' }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide opacity-80">Symbolic</p>
                    <p class="font-mono text-base font-semibold">{{ fm.permissionPreview?.symbolic || '---------'.slice(0, 9) }}</p>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                <div v-for="cell in fm.permissionMatrix" :key="cell.key" class="rounded-md border border-white/20 bg-white/40 px-2 py-2 dark:bg-black/10">
                    <p class="mb-1 font-semibold uppercase tracking-wide">{{ cell.label }}</p>
                    <div class="flex items-center justify-center gap-1 font-mono text-[11px]">
                        <span :class="cell.read ? 'text-emerald-700 dark:text-emerald-300' : 'opacity-40'">R</span>
                        <span :class="cell.write ? 'text-red-700 dark:text-red-300' : 'opacity-40'">W</span>
                        <span :class="cell.execute ? 'text-blue-700 dark:text-blue-300' : 'opacity-40'">X</span>
                    </div>
                </div>
            </div>

            <p v-if="fm.permissionPreview?.dangerous" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-300">
                Warning: this mode is world-writable or otherwise high-risk. Use carefully.
            </p>
        </div>

        <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
            <input v-model="fm.permissionForm.recursive" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800" />
            Apply recursively to subdirectories and files
        </label>
        <button type="button" :disabled="fm.permissionForm.processing || !fm.permissionCanSave" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitPermissions">
            {{ fm.permissionForm.processing ? 'Saving...' : (fm.permissionForm.recursive ? 'Save Recursively' : 'Save Permission') }}
        </button>
    </div>
</template>
