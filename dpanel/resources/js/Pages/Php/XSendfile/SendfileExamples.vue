<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CodeBlock from './CodeBlock.vue';

const { t } = useI18n();

const php = `<?php
session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

// A full path inside the site owner's home folder.
$file = '/home/USER/private/video.mp4';

header('X-Sendfile: ' . $file);
header('Content-Type: video/mp4');
// For a download instead of playback:
// header('Content-Disposition: attachment; filename="video.mp4"');
exit;`;

const laravel = `Route::get('/media/{video}', function (Video $video) {
    abort_unless(auth()->user()?->can('view', $video), 403);

    // storage_path() is inside the site folder, so it is allowed.
    $file = storage_path('app/private/'.$video->path);

    return response('', 200, [
        'X-Sendfile' => $file,
        'Content-Type' => mime_content_type($file) ?: 'application/octet-stream',
    ]);
})->middleware('auth');`;

const wordpress = `add_action('template_redirect', function () {
    if (! isset($_GET['download'])) {
        return;
    }
    if (! is_user_logged_in()) {
        auth_redirect();
    }

    $file = WP_CONTENT_DIR . '/private/' . basename($_GET['download']);
    if (! is_file($file)) {
        status_header(404);
        exit;
    }

    header('X-Sendfile: ' . $file);
    header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    exit;
});`;

const tabs = computed(() => [
    { key: 'php', label: t('php.xsendfile.examples.php'), code: php, note: '' },
    { key: 'laravel', label: t('php.xsendfile.examples.laravel'), code: laravel, note: t('php.xsendfile.examples.laravelNote') },
    { key: 'wordpress', label: t('php.xsendfile.examples.wordpress'), code: wordpress, note: t('php.xsendfile.examples.wordpressNote') },
]);

const active = ref('php');
const current = computed(() => tabs.value.find((tab) => tab.key === active.value) || tabs.value[0]);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">{{ t('php.xsendfile.examples.title') }}</h2>

        <div class="mt-4 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                :class="[
                    '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
                    active === tab.key
                        ? 'border-indigo-600 text-indigo-700 dark:border-indigo-400 dark:text-indigo-300'
                        : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200',
                ]"
                @click="active = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="mt-4 space-y-3">
            <CodeBlock :code="current.code" />
            <p v-if="current.note" class="text-sm text-slate-600 dark:text-slate-400">{{ current.note }}</p>
        </div>
    </section>
</template>
