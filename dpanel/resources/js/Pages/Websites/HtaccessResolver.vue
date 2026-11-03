<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Deferred, Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
    project: { type: Object, required: true },
    targets: { type: Array, required: true },
    templates: { type: Array, default: () => [] },
    php: { type: Object, default: null },
    redirectKinds: { type: Array, default: () => [] },
    nested: { type: Object, default: null },
    maxBytes: { type: Number, default: 131072 },
});

const { panelRoute, requestJson } = usePanelApi();

// One editor per file; "root" is the project folder, "public" the folder drust serves.
const editors = reactive(Object.fromEntries(props.targets.map((target) => [target.key, {
    ...target,
    content: target.content,
    saved: target.content,
    errors: [],
    findings: [],
    checking: false,
    seq: 0,
}])));
const activeKey = ref(props.targets.find((target) => target.served)?.key || props.targets[0]?.key);
const active = computed(() => editors[activeKey.value]);
const saveTargets = ref([activeKey.value]);
const redirectKinds = ref([...props.redirectKinds]);
const appliedKeys = ref(new Set());

const notice = ref(null);
const busy = ref('');
const show = (message, type = 'success') => { notice.value = { message, type }; };

const dirty = (editor) => editor.content !== editor.saved;
const tooLarge = computed(() => new Blob([active.value.content]).size > props.maxBytes);

// ---- validation -------------------------------------------------------------
let checkTimer = null;
// Only the newest check of each editor may update it.
const check = async (key) => {
    const editor = editors[key];
    const seq = ++editor.seq;
    editor.checking = true;
    try {
        const data = await requestJson(panelRoute('websites.htaccess.check', { id: props.website.id }), {
            body: { target: key, content: editor.content, app: props.project.app, exists: editor.exists },
        });
        if (seq !== editor.seq) return;
        editor.errors = data.errors || [];
        editor.diagnosis = data.diagnosis || editor.diagnosis;
        editor.findings = data.findings || [];
    } catch (error) {
        if (seq === editor.seq) editor.errors = [{ line: 0, message: error.message }];
    } finally {
        editor.checking = false;
    }
};
watch(() => active.value.content, () => {
    window.clearTimeout(checkTimer);
    checkTimer = window.setTimeout(() => check(activeKey.value), 500);
});
watch(activeKey, (key) => {
    check(key);
    if (!saveTargets.value.includes(key) && saveTargets.value.length === 1) saveTargets.value = [key];
});
onMounted(() => props.targets.forEach((target) => check(target.key)));

const errorLines = computed(() => new Set(active.value.errors.map((error) => error.line)));
const lineCount = computed(() => Math.max(1, active.value.content.split('\n').length));
const gutter = ref(null);
const syncScroll = (event) => { if (gutter.value) gutter.value.scrollTop = event.target.scrollTop; };

// ---- findings ---------------------------------------------------------------
const statusMeta = {
    risk: { label: 'Security risk', icon: 'bi-shield-exclamation', chip: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300', border: 'border-red-200 dark:border-red-900/60' },
    convertible: { label: 'Convertible', icon: 'bi-magic', chip: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300', border: 'border-indigo-200 dark:border-indigo-900/60' },
    manual: { label: 'Not applied', icon: 'bi-exclamation-circle', chip: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', border: 'border-amber-200 dark:border-amber-900/60' },
    native: { label: 'Handled by drust', icon: 'bi-check-circle', chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', border: 'border-slate-200 dark:border-slate-800' },
};
const statusOrder = ['risk', 'convertible', 'manual', 'native'];
const statusFilter = ref('all');
const counts = (findings) => Object.fromEntries(statusOrder.map((status) => [status, findings.filter((finding) => finding.status === status).length]));
const activeCounts = computed(() => counts(active.value.findings));
const visibleFindings = computed(() => [...active.value.findings]
    .filter((finding) => statusFilter.value === 'all' || finding.status === statusFilter.value)
    .sort((a, b) => statusOrder.indexOf(a.status) - statusOrder.indexOf(b.status) || a.line - b.line));

// Findings show only their title until clicked open.
const expanded = ref(new Set());
const findingKey = (finding) => `${activeKey.value}:${finding.line}:${finding.title}`;
const toggleFinding = (finding) => {
    const next = new Set(expanded.value);
    next.has(findingKey(finding)) ? next.delete(findingKey(finding)) : next.add(findingKey(finding));
    expanded.value = next;
};

const actionKey = (action) => JSON.stringify(action);
const pendingActions = (finding) => (finding.actions || []).filter((action) => !isDone(action));
const isDone = (action) => appliedKeys.value.has(actionKey(action))
    || (action.type === 'redirect' && redirectKinds.value.includes(action.kind));
// The same action can come from two rules (e.g. two http→https blocks); offer it once.
const allPending = computed(() => [...new Map(active.value.findings.flatMap(pendingActions).map((action) => [actionKey(action), action])).values()]);

const actionLabel = (action) => {
    if (action.type === 'redirect') {
        return { http_to_https: 'Redirect rule: HTTP → HTTPS', www_to_root: 'Redirect rule: www → root', to_domain: `Redirect rule: to ${action.target}` }[action.kind];
    }
    if (action.type === 'ip') return `${action.rule_type === 'allow' ? 'Whitelist' : 'Ban'} IP ${action.ip}`;
    return `${action.directory ? `${action.directory}/` : ''}.user.ini: ${action.key} = ${action.value}`;
};

const apply = async (actions) => {
    if (!actions.length) return;
    busy.value = 'apply';
    try {
        const data = await requestJson(panelRoute('websites.htaccess.apply', { id: props.website.id }), { body: { actions } });
        // One result per action, in the order sent.
        const applied = new Set(appliedKeys.value);
        data.results.forEach((result, index) => { if (result.ok) applied.add(actionKey(actions[index])); });
        appliedKeys.value = applied;
        redirectKinds.value = data.redirectKinds || redirectKinds.value;
        show([data.message, ...new Set(data.results.map((result) => `${result.ok ? '✓' : '✗'} ${result.message}`))].join('\n'), data.success ? 'success' : 'error');
    } catch (error) {
        show(error.message, 'error');
    } finally {
        busy.value = '';
    }
};

// ---- editor actions ---------------------------------------------------------
const copy = () => copyText(active.value.content, active.value.path);

const copyText = async (text, what) => {
    try {
        await navigator.clipboard.writeText(text);
        show(`${what} copied to the clipboard.`);
    } catch {
        show('The browser blocked clipboard access. Select the text and copy it by hand.', 'error');
    }
};

const download = () => {
    const url = URL.createObjectURL(new Blob([active.value.content], { type: 'text/plain' }));
    const link = Object.assign(document.createElement('a'), { href: url, download: 'htaccess.txt' });
    link.click();
    URL.revokeObjectURL(url);
};

const reset = () => { active.value.content = active.value.saved; };

const loadTemplate = (key) => {
    const template = props.templates.find((item) => item.key === key);
    if (!template) return;

    if (template.snippet) {
        active.value.content = `${template.public.trimEnd()}\n\n${active.value.content}`.trimEnd() + '\n';
        return;
    }
    // With one file only, the public template goes there; the root forwarder is not needed.
    const fills = editors.public
        ? Object.entries({ root: template.root, public: template.public }).filter(([, content]) => content)
        : [['root', template.public]];
    const overwrites = fills.filter(([key]) => editors[key].content.trim() !== '').map(([key]) => editors[key].path);
    if (overwrites.length && !window.confirm(`Replace the text in ${overwrites.join(' and ')} with the ${template.label} template? Nothing is saved until you press Save.`)) return;

    fills.forEach(([key, content]) => { editors[key].content = content; });
    saveTargets.value = fills.map(([key]) => key);
    fills.forEach(([key]) => key !== activeKey.value && check(key));
};

// ---- optional PHP version handler (root file, for Apache/cPanel hosts) -------
const handlerLine = /^[ \t]*(?:AddHandler|SetHandler|AddType)[ \t].*?x-httpd-(?:ea-|alt-)?php(\d)(\d)\b.*(?:\n|$)/gmi;
const cpanelBlock = /^# php -- BEGIN cPanel-generated handler[\s\S]*?^# php -- END cPanel-generated handler[^\n]*\n?/m;
const rootHandlerVersion = computed(() => {
    const match = [...(editors.root?.content || '').matchAll(handlerLine)][0];
    return match ? `${match[1]}.${match[2]}` : null;
});
// Replaces any earlier handler, so the file never selects two versions.
const addPhpHandler = () => {
    const rest = editors.root.content.replace(cpanelBlock, '').replace(handlerLine, '').replace(/^\s+/, '');
    editors.root.content = `${props.php.handler}${rest ? `\n${rest}` : ''}`;
    activeKey.value = 'root';
};

const canSave = computed(() => saveTargets.value.length > 0 && active.value.errors.length === 0 && !active.value.checking && !tooLarge.value);

const write = async (content, targets) => {
    const names = targets.map((key) => editors[key].path).join(' and ');
    if (!window.confirm(`Write this text to ${names}? The current file is kept as .htaccess.bak.`)) return false;
    busy.value = 'save';
    try {
        const data = await requestJson(panelRoute('websites.htaccess.save', { id: props.website.id }), {
            body: { content, targets },
        });
        (data.written || []).forEach((key) => {
            editors[key].content = content;
            editors[key].saved = content;
            editors[key].exists = true;
            check(key);
        });
        show(data.message);
        return true;
    } catch (error) {
        show(error.message, 'error');
        return false;
    } finally {
        busy.value = '';
    }
};
const save = () => write(active.value.content, saveTargets.value);

// ---- project check ----------------------------------------------------------
const diagnosisMeta = {
    ok: { label: 'Valid', icon: 'bi-check-circle-fill', chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
    none: { label: 'Not needed', icon: 'bi-dash-circle', chip: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
    missing: { label: 'Missing', icon: 'bi-file-earmark-x', chip: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
    incomplete: { label: 'Needs update', icon: 'bi-exclamation-triangle-fill', chip: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
    invalid: { label: 'Invalid', icon: 'bi-x-octagon-fill', chip: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' },
};

// Lines of the fix that are not in the file, to highlight in the review.
const fixLines = (editor) => {
    const current = new Set(editor.content.split('\n').map((line) => line.trim()));
    return editor.diagnosis.suggestion.replace(/\n$/, '').split('\n').map((text) => ({ text, added: text.trim() !== '' && !current.has(text.trim()) }));
};
// The file whose suggested fix is open in the preview off-canvas.
const previewKey = ref(null);
const preview = computed(() => (previewKey.value ? editors[previewKey.value] : null));
const loadFix = (editor) => {
    editor.content = editor.diagnosis.suggestion;
    previewKey.value = null;
    activeKey.value = editor.key;
};
const applyFix = async (editor) => {
    if (await write(editor.diagnosis.suggestion, [editor.key])) previewKey.value = null;
};
</script>

<template>
    <Head :title="`.htaccess Resolver - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-lg font-semibold">.htaccess Resolver</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Check, convert and edit the .htaccess files of {{ website.domain }}</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="self-start rounded-lg border px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-arrow-left mr-1"></i> Website dashboard</Link>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="notice" :class="['flex items-start gap-3 whitespace-pre-line rounded-xl border px-4 py-3 text-sm', notice.type === 'error' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-500/10 dark:text-red-300' : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300']">
                <p class="flex-1">{{ notice.message }}</p>
                <button type="button" class="opacity-70 hover:opacity-100" aria-label="Dismiss" @click="notice = null"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(340px,1fr)]">
                <!-- Editor -->
                <section class="min-w-0 rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                        <div class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                            <button v-for="target in targets" :key="target.key" type="button"
                                :class="['rounded-md px-3 py-1.5 text-sm font-medium transition', activeKey === target.key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400']"
                                @click="activeKey = target.key">
                                {{ target.label }}
                                <span class="ml-1 font-mono text-xs opacity-70">{{ target.path }}</span>
                                <span v-if="dirty(editors[target.key])" class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-amber-500 align-middle" title="Unsaved changes"></span>
                            </button>
                        </div>
                    </div>

                    <p class="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">
                        <template v-if="active.served">drust serves this folder. </template>
                        <template v-else>drust serves {{ editors.public?.path.replace('/.htaccess', '/') }}, so this file is only used on an Apache host whose document root is the project folder. </template>
                        <template v-if="!active.exists">The file does not exist yet; saving creates it.</template>
                    </p>

                    <div class="m-4 flex overflow-hidden rounded-xl border border-slate-200 bg-slate-950 dark:border-slate-700">
                        <div ref="gutter" class="max-h-[520px] select-none overflow-hidden py-3 text-right font-mono text-xs leading-5 text-slate-500" aria-hidden="true">
                            <div v-for="line in lineCount" :key="line" :class="['px-3', errorLines.has(line) ? 'bg-red-500/25 text-red-300' : '']">{{ line }}</div>
                        </div>
                        <textarea v-model="active.content" spellcheck="false" wrap="off" aria-label=".htaccess content"
                            class="h-[520px] min-w-0 flex-1 resize-none border-0 bg-transparent py-3 pl-2 pr-3 font-mono text-xs leading-5 text-slate-100 focus:ring-0"
                            placeholder="# Empty — load a template or paste rules here" @scroll="syncScroll"></textarea>
                    </div>

                    <div class="px-4">
                        <div v-if="active.checking" class="text-xs text-slate-500"><i class="bi bi-arrow-repeat mr-1"></i>Checking…</div>
                        <div v-else-if="tooLarge" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">The file is larger than {{ Math.round(maxBytes / 1024) }} KB.</div>
                        <ul v-else-if="active.errors.length" class="space-y-1 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
                            <li v-for="(error, index) in active.errors" :key="index"><i class="bi bi-x-circle mr-1"></i><span v-if="error.line" class="font-mono">Line {{ error.line }}:</span> {{ error.message }}</li>
                        </ul>
                        <div v-else class="text-sm text-emerald-600 dark:text-emerald-400"><i class="bi bi-check2-circle mr-1"></i>Valid .htaccess syntax</div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 p-4 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-slate-500">Save to</span>
                            <label v-for="target in targets" :key="target.key" class="flex items-center gap-1.5 text-sm">
                                <input v-model="saveTargets" type="checkbox" :value="target.key" class="rounded border-slate-300 dark:border-slate-600">
                                <span class="font-mono text-xs">{{ target.path }}</span>
                            </label>
                            <button type="button" :disabled="!canSave || busy === 'save'" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="save">
                                <i class="bi bi-save mr-1"></i>{{ busy === 'save' ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Quick actions -->
                <section class="min-w-0 space-y-5 self-start rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <!-- Project check: detect -> read -> validate -> suggest -> apply or copy -->
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-slate-500">Detected project <span class="ml-1 rounded-full bg-teal-100 px-2 py-0.5 font-semibold text-teal-700 dark:bg-teal-500/15 dark:text-teal-300">{{ project.label }}</span></p>
                            <Link :href="panelRoute('websites.filemanager', { id: website.id })" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"><i class="bi bi-folder2-open mr-1"></i>File Manager</Link>
                        </div>
                        <div class="mt-3 space-y-2">
                            <div v-for="target in targets" :key="target.key" class="rounded-xl border border-slate-200 p-2.5 dark:border-slate-800">
                                <template v-for="editor in [editors[target.key]]" :key="editor.key">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 truncate font-mono text-xs">{{ editor.path }}</span>
                                        <span v-if="editor.diagnosis" :class="['shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium', diagnosisMeta[editor.diagnosis.status].chip]">
                                            <i :class="['bi mr-1', diagnosisMeta[editor.diagnosis.status].icon]"></i>{{ diagnosisMeta[editor.diagnosis.status].label }}
                                        </span>
                                    </div>
                                    <ul v-if="editor.diagnosis?.problems.length" class="mt-1.5 space-y-0.5 text-xs text-slate-600 dark:text-slate-300">
                                        <li v-for="(problem, index) in editor.diagnosis.problems" :key="index" class="flex gap-1"><i class="bi bi-dot"></i><span>{{ problem }}</span></li>
                                    </ul>
                                    <div v-if="editor.diagnosis?.suggestion" class="mt-2 flex flex-wrap gap-1.5">
                                        <button type="button" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="previewKey = editor.key"><i class="bi bi-eye mr-1"></i>Preview fix</button>
                                        <button type="button" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="copyText(editor.diagnosis.suggestion, `Fixed ${editor.path}`)"><i class="bi bi-clipboard mr-1"></i>Copy</button>
                                        <button type="button" :disabled="busy === 'save'" class="rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="applyFix(editor)"><i class="bi bi-check2 mr-1"></i>Apply fix</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <h2 class="border-t border-slate-100 pt-4 text-sm font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">Quick actions</h2>

                    <div v-if="php && editors.root">
                        <p class="mb-2 text-xs font-medium text-slate-500">PHP version (root .htaccess)</p>
                        <span v-if="rootHandlerVersion === php.version" class="inline-flex items-center rounded-lg bg-emerald-100 px-2.5 py-1.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><i class="bi bi-check2 mr-1"></i>PHP {{ php.version }} added</span>
                        <button v-else type="button" class="rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700" @click="addPhpHandler">
                            <i class="bi bi-filetype-php mr-1"></i>{{ rootHandlerVersion ? `Change PHP ${rootHandlerVersion} → ${php.version}` : `Add PHP ${php.version}` }}
                        </button>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-medium text-slate-500">Templates</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="template in templates.filter((item) => !item.snippet)" :key="template.key" type="button" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="loadTemplate(template.key)">{{ template.label }}</button>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <button v-for="template in templates.filter((item) => item.snippet)" :key="template.key" type="button" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="loadTemplate(template.key)"><i class="bi bi-plus mr-0.5"></i>{{ template.label }}</button>
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-medium text-slate-500">File</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="copy"><i class="bi bi-clipboard mr-1"></i>Copy</button>
                            <button type="button" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="download"><i class="bi bi-download mr-1"></i>Download</button>
                            <button type="button" :disabled="!dirty(active)" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="reset"><i class="bi bi-arrow-counterclockwise mr-1"></i>Undo</button>
                        </div>
                    </div>

                    <div v-if="active.served && allPending.length">
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-xs font-medium text-slate-500">Apply to panel</p>
                            <button type="button" :disabled="busy === 'apply'" class="text-xs font-medium text-indigo-600 hover:underline disabled:opacity-50 dark:text-indigo-400" @click="apply(allPending)">Apply all ({{ allPending.length }})</button>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="action in allPending" :key="actionKey(action)" type="button" :disabled="busy === 'apply'" class="rounded-lg bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 disabled:opacity-50 dark:bg-indigo-500/15 dark:text-indigo-300" @click="apply([action])">
                                <i class="bi bi-magic mr-1"></i>{{ actionLabel(action) }}
                            </button>
                        </div>
                    </div>

                    <div v-if="active.served && active.findings.length">
                        <div class="mb-2 flex flex-wrap gap-1.5">
                            <button type="button" :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', statusFilter === 'all' ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300']" @click="statusFilter = 'all'">All {{ active.findings.length }}</button>
                            <button v-for="status in statusOrder.filter((item) => activeCounts[item])" :key="status" type="button"
                                :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', statusMeta[status].chip, statusFilter === status ? 'ring-2 ring-current' : '']"
                                @click="statusFilter = status">{{ statusMeta[status].label }} {{ activeCounts[status] }}</button>
                        </div>
                        <ul class="max-h-[28rem] space-y-1 overflow-y-auto pr-1">
                            <li v-for="(finding, index) in visibleFindings" :key="`${finding.line}-${index}`" :class="['rounded-lg border text-xs', expanded.has(findingKey(finding)) ? statusMeta[finding.status].border : 'border-transparent']">
                                <button type="button" class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-800/60" :aria-expanded="expanded.has(findingKey(finding))" @click="toggleFinding(finding)">
                                    <i :class="['bi', statusMeta[finding.status].icon, statusMeta[finding.status].chip, 'rounded px-1']"></i>
                                    <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-200">{{ finding.title }}</span>
                                    <span class="shrink-0 font-mono text-slate-400">{{ finding.line }}</span>
                                    <i :class="['bi shrink-0 text-slate-400 transition-transform', expanded.has(findingKey(finding)) ? 'bi-chevron-up' : 'bi-chevron-down']"></i>
                                </button>
                                <div v-if="expanded.has(findingKey(finding))" class="space-y-2 px-2 pb-2 pt-1">
                                    <p class="leading-relaxed text-slate-600 dark:text-slate-300">{{ finding.detail }}</p>
                                    <pre class="overflow-x-auto rounded-md bg-slate-950 p-2 text-[11px] leading-5 text-slate-200">{{ finding.source }}</pre>
                                    <div v-if="finding.actions?.length" class="flex flex-wrap gap-1.5">
                                        <template v-for="action in finding.actions" :key="actionKey(action)">
                                            <span v-if="isDone(action)" class="rounded-md bg-emerald-100 px-2 py-1 font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><i class="bi bi-check2 mr-1"></i>{{ actionLabel(action) }}</span>
                                            <button v-else type="button" :disabled="busy === 'apply'" class="rounded-md bg-indigo-600 px-2 py-1 font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="apply([action])"><i class="bi bi-magic mr-1"></i>{{ actionLabel(action) }}</button>
                                        </template>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>

            <!-- Other .htaccess files -->
            <section class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Other .htaccess files in this site</h2>
                <Deferred data="nested">
                    <template #fallback>
                        <p class="mt-3 text-sm text-slate-500"><i class="bi bi-arrow-repeat mr-1"></i>Scanning folders…</p>
                    </template>
                    <p v-if="!nested?.files?.length" class="mt-3 text-sm text-slate-500 dark:text-slate-400">None found in sub-folders.</p>
                    <div v-else class="mt-3 space-y-3">
                        <details v-for="file in nested.files" :key="file.file" class="rounded-xl border border-slate-200 p-3 dark:border-slate-800" :open="counts(file.findings).risk > 0">
                            <summary class="flex cursor-pointer flex-wrap items-center gap-2">
                                <span class="font-mono text-sm">{{ file.file }}</span>
                                <span v-for="status in statusOrder.filter((item) => counts(file.findings)[item])" :key="status" :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', statusMeta[status].chip]">{{ statusMeta[status].label }} {{ counts(file.findings)[status] }}</span>
                                <span v-if="file.errors.length" class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-700 dark:bg-red-500/15 dark:text-red-300">{{ file.errors.length }} syntax error(s)</span>
                            </summary>
                            <ul class="mt-3 space-y-2">
                                <li v-for="(finding, index) in file.findings" :key="index" class="text-sm">
                                    <span :class="['mr-2 rounded px-1.5 py-0.5 text-[11px] font-medium', statusMeta[finding.status].chip]">{{ statusMeta[finding.status].label }}</span>
                                    <span class="font-medium">{{ finding.title }}</span>
                                    <span class="font-mono text-xs text-slate-400"> line {{ finding.line }}</span>
                                    <div v-if="pendingActions(finding).length" class="mt-1">
                                        <button type="button" :disabled="busy === 'apply'" class="rounded-md bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="apply(pendingActions(finding))">Apply: {{ pendingActions(finding).map(actionLabel).join(', ') }}</button>
                                    </div>
                                </li>
                            </ul>
                        </details>
                        <p v-if="nested.truncated" class="text-xs text-slate-500">The scan stopped after 80 folders; deeper folders were not checked.</p>
                    </div>
                </Deferred>
            </section>
        </div>

        <Offcanvas :show="!!preview?.diagnosis?.suggestion" title="Suggested fix" :subtitle="preview ? `${preview.path} · ${project.label}` : ''" max-width="760px" @close="previewKey = null">
            <template v-if="preview?.diagnosis?.suggestion">
                <ul v-if="preview.diagnosis.problems.length" class="mb-3 space-y-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    <li v-for="(problem, index) in preview.diagnosis.problems" :key="index" class="flex gap-1.5"><i class="bi bi-exclamation-triangle mt-0.5"></i><span>{{ problem }}</span></li>
                </ul>
                <p class="mb-2 flex gap-4 text-xs text-slate-500">
                    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-500/40 align-middle"></span>Added</span>
                    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-500/40 align-middle"></span>Commented out (invalid)</span>
                </p>
                <pre class="overflow-auto rounded-xl bg-slate-950 py-2 text-[11px] leading-5 text-slate-200"><div v-for="(line, index) in fixLines(preview)" :key="index" :class="['px-3', line.added ? 'bg-emerald-500/20 text-emerald-200' : (line.text.startsWith('# [dPanel:') ? 'bg-red-500/20 text-red-200' : '')]">{{ line.added ? '+ ' : '  ' }}{{ line.text }}</div></pre>
                <div class="sticky bottom-0 -mx-5 mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-white px-5 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <button type="button" class="rounded-lg border px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="copyText(preview.diagnosis.suggestion, `Fixed ${preview.path}`)"><i class="bi bi-clipboard mr-1"></i>Copy</button>
                    <button type="button" class="rounded-lg border px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="loadFix(preview)"><i class="bi bi-pencil mr-1"></i>Load into editor</button>
                    <button type="button" :disabled="busy === 'save'" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="applyFix(preview)"><i class="bi bi-check2 mr-1"></i>{{ busy === 'save' ? 'Saving…' : 'Apply fix' }}</button>
                </div>
            </template>
        </Offcanvas>
    </AuthenticatedLayout>
</template>
