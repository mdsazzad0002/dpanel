<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    tools: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['update:groups']);

const PRESETS = [
    {
        label: 'Monitoring',
        tools: ['get_panel_overview', 'run_health_check', 'list_servers', 'list_websites', 'get_website', 'check_website', 'list_ssl_certificates', 'list_dns_records', 'get_mail_status', 'list_backups', 'list_security_findings', 'get_service_status'],
    },
    {
        label: 'Logs & errors',
        tools: ['get_panel_overview', 'list_websites', 'get_website', 'check_website', 'find_website_errors', 'get_service_status', 'read_service_logs', 'read_log_file', 'list_recent_commands', 'list_recent_activity'],
    },
    {
        label: 'Code editing',
        tools: ['list_websites', 'get_website', 'find_website_errors', 'list_directory', 'read_file', 'search_files', 'write_file', 'edit_file', 'file_operation', 'list_git_conflicts', 'resolve_git_conflicts'],
    },
    { label: 'All read-only tools', readOnly: true },
];

const readTools = computed(() => props.tools.filter((t) => t.read_only));
const writeTools = computed(() => props.tools.filter((t) => !t.read_only));
const knownNames = computed(() => new Set(props.tools.map((t) => t.name)));

const emptyForm = () => ({ id: null, name: '', description: '', tools: [] });
const form = ref(null);
const saving = ref(false);
const error = ref('');

const openNew = () => { form.value = emptyForm(); error.value = ''; };
const openEdit = (g) => { form.value = { id: g.id, name: g.name, description: g.description || '', tools: [...g.tools] }; error.value = ''; };
const close = () => { form.value = null; };

const applyPreset = (preset) => {
    const names = preset.readOnly ? readTools.value.map((t) => t.name) : preset.tools;
    form.value.tools = names.filter((n) => knownNames.value.has(n));
    if (!form.value.name) form.value.name = preset.label;
};

const toggleAll = (list, on) => {
    const names = list.map((t) => t.name);
    form.value.tools = on
        ? [...new Set([...form.value.tools, ...names])]
        : form.value.tools.filter((n) => !names.includes(n));
};

const save = async () => {
    if (saving.value) return;
    saving.value = true;
    error.value = '';

    const payload = { name: form.value.name, description: form.value.description || null, tools: form.value.tools };

    try {
        const { data } = form.value.id
            ? await axios.put(props.panelRoute('mcp.groups.update', { mcpGroup: form.value.id }), payload)
            : await axios.post(props.panelRoute('mcp.groups.store'), payload);

        const others = props.groups.filter((g) => g.id !== data.group.id);
        emit('update:groups', [...others, data.group].sort((a, b) => a.name.localeCompare(b.name)));
        close();
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        error.value = Object.values(errors)[0]?.[0] || e.response?.data?.message || 'Could not save the group.';
    } finally {
        saving.value = false;
    }
};

const remove = async (g) => {
    if (!confirm(`Delete access group "${g.name}"?`)) return;
    try {
        await axios.delete(props.panelRoute('mcp.groups.destroy', { mcpGroup: g.id }));
        emit('update:groups', props.groups.filter((x) => x.id !== g.id));
    } catch (e) {
        alert(e.response?.data?.message || 'Could not delete the group.');
    }
};

const hasWriteTools = (g) => g.tools.some((n) => writeTools.value.some((t) => t.name === n));
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold">Access groups</h2>
                <p class="text-xs text-slate-500">Limit a token to a set of tools. A token without a group can use every tool.</p>
            </div>
            <button v-if="!form" @click="openNew" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                <i class="bi bi-plus-lg"></i> New group
            </button>
        </div>

        <div v-if="form" class="mt-3 space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-700">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium">Group name</label>
                    <input v-model="form.name" type="text" placeholder="e.g. Monitoring"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Description (optional)</label>
                    <input v-model="form.description" type="text" placeholder="What this group is for"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1 text-xs">
                <span class="text-slate-500">Start from:</span>
                <button v-for="p in PRESETS" :key="p.label" type="button" @click="applyPreset(p)"
                    class="rounded border border-slate-300 px-2 py-0.5 hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">{{ p.label }}</button>
            </div>

            <div v-for="section in [{ key: 'read', title: 'Read-only tools', list: readTools }, { key: 'write', title: 'Tools that can change things', list: writeTools }]" :key="section.key">
                <div class="mb-1 flex items-center gap-2 text-xs">
                    <span class="font-medium" :class="section.key === 'write' ? 'text-amber-700 dark:text-amber-400' : ''">{{ section.title }}</span>
                    <button type="button" @click="toggleAll(section.list, true)" class="text-blue-600 hover:underline">all</button>
                    <button type="button" @click="toggleAll(section.list, false)" class="text-blue-600 hover:underline">none</button>
                </div>
                <div class="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="tool in section.list" :key="tool.name" class="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-xs hover:bg-slate-50 dark:hover:bg-slate-700/40" :title="tool.title">
                        <input v-model="form.tools" type="checkbox" :value="tool.name" />
                        <code>{{ tool.name }}</code>
                    </label>
                </div>
            </div>

            <p class="text-xs text-slate-400">
                Tools that can change things still need a <b>Full</b> token to make changes; with a read-only token they stay limited to safe actions.
            </p>

            <div v-if="error" class="text-xs text-red-600">{{ error }}</div>

            <div class="flex justify-end gap-2">
                <button type="button" @click="close" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Cancel</button>
                <button type="button" @click="save" :disabled="saving || !form.name.trim() || !form.tools.length"
                    class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ form.id ? 'Save group' : 'Create group' }} ({{ form.tools.length }} tools)
                </button>
            </div>
        </div>

        <div v-if="groups.length" class="mt-3 divide-y divide-slate-200 dark:divide-slate-700">
            <div v-for="g in groups" :key="g.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <div class="min-w-0">
                    <span class="font-medium">{{ g.name }}</span>
                    <span class="ml-2 text-xs text-slate-500">{{ g.tools.length }} tools · {{ g.tokens_count }} token(s)</span>
                    <span v-if="hasWriteTools(g)" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700">can change</span>
                    <p v-if="g.description" class="text-xs text-slate-500">{{ g.description }}</p>
                </div>
                <div class="space-x-1 whitespace-nowrap">
                    <button @click="openEdit(g)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Edit</button>
                    <button @click="remove(g)" :disabled="g.tokens_count > 0" :title="g.tokens_count > 0 ? 'In use by tokens' : ''"
                        class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-40 dark:border-red-700">Delete</button>
                </div>
            </div>
        </div>
        <p v-else-if="!form" class="mt-3 text-xs text-slate-400">No groups yet. Every token currently has access to all tools.</p>
    </div>
</template>
