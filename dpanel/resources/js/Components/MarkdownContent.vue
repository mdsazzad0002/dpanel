<script setup>
import { computed } from 'vue';
import { Marked } from 'marked';
import DOMPurify from 'dompurify';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import diff from 'highlight.js/lib/languages/diff';
import dockerfile from 'highlight.js/lib/languages/dockerfile';
import go from 'highlight.js/lib/languages/go';
import ini from 'highlight.js/lib/languages/ini';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import nginx from 'highlight.js/lib/languages/nginx';
import php from 'highlight.js/lib/languages/php';
import plaintext from 'highlight.js/lib/languages/plaintext';
import python from 'highlight.js/lib/languages/python';
import rust from 'highlight.js/lib/languages/rust';
import sql from 'highlight.js/lib/languages/sql';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';

const languages = { bash, css, diff, dockerfile, go, ini, javascript, json, nginx, php, plaintext, python, rust, sql, typescript, xml, yaml };
for (const [name, lang] of Object.entries(languages)) {
    if (!hljs.getLanguage(name)) hljs.registerLanguage(name, lang);
}
hljs.registerAliases(['sh', 'shell', 'zsh', 'console'], { languageName: 'bash' });
hljs.registerAliases(['html', 'vue', 'blade'], { languageName: 'xml' });
hljs.registerAliases(['env', 'toml', 'conf'], { languageName: 'ini' });

const props = defineProps({
    content: { type: String, default: '' },
});

const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const marked = new Marked({
    gfm: true,
    breaks: true,
    renderer: {
        code({ text, lang }) {
            const name = (lang || '').trim().split(/\s+/)[0].toLowerCase();
            const known = name && hljs.getLanguage(name);
            const body = known ? hljs.highlight(text, { language: name, ignoreIllegals: true }).value : escapeHtml(text);
            return `<div class="md-code"><div class="md-code-head"><span>${escapeHtml(name || 'text')}</span>`
                + '<button type="button" class="md-copy" data-copy><i class="bi bi-clipboard"></i> Copy</button></div>'
                + `<pre><code class="hljs">${body}</code></pre></div>`;
        },
    },
});

// Model output is untrusted: sanitize, and open links in a new tab.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.getAttribute('href')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
});

const html = computed(() => DOMPurify.sanitize(marked.parse(props.content || ''), { ADD_ATTR: ['target', 'data-copy'] }));

const onClick = async (e) => {
    const button = e.target.closest('[data-copy]');
    if (!button) return;
    const code = button.closest('.md-code')?.querySelector('code')?.innerText ?? '';
    try {
        await navigator.clipboard.writeText(code);
        button.innerHTML = '<i class="bi bi-check2"></i> Copied';
        setTimeout(() => { button.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 1500);
    } catch {
        // Clipboard API unavailable — the code is still selectable.
    }
};
</script>

<template>
    <div class="md-body" v-html="html" @click="onClick"></div>
</template>

<style>
.md-body { font-size: 0.875rem; line-height: 1.65; overflow-wrap: anywhere; }
.md-body > :first-child { margin-top: 0; }
.md-body > :last-child { margin-bottom: 0; }
.md-body p, .md-body ul, .md-body ol, .md-body blockquote, .md-body table, .md-body .md-code { margin: 0.6em 0; }
.md-body h1, .md-body h2, .md-body h3, .md-body h4 { margin: 1.1em 0 0.5em; font-weight: 600; line-height: 1.3; }
.md-body h1 { font-size: 1.35em; }
.md-body h2 { font-size: 1.2em; }
.md-body h3 { font-size: 1.05em; }
.md-body ul { list-style: disc; padding-left: 1.4em; }
.md-body ol { list-style: decimal; padding-left: 1.4em; }
.md-body li { margin: 0.2em 0; }
.md-body li > ul, .md-body li > ol { margin: 0.2em 0; }
.md-body a { color: #2563eb; text-decoration: underline; text-underline-offset: 2px; }
.md-body strong { font-weight: 600; }
.md-body hr { margin: 1em 0; border-color: #e2e8f0; }
.md-body blockquote { border-left: 3px solid #cbd5e1; padding-left: 0.9em; color: #64748b; }
.md-body img { max-width: 100%; border-radius: 0.5rem; }
.md-body :not(pre) > code { background: rgba(100, 116, 139, 0.15); border-radius: 0.3rem; padding: 0.1em 0.35em; font-size: 0.85em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.md-body table { display: block; overflow-x: auto; border-collapse: collapse; font-size: 0.8125rem; }
.md-body th, .md-body td { border: 1px solid #e2e8f0; padding: 0.35em 0.7em; text-align: left; }
.md-body th { background: #f8fafc; font-weight: 600; }
.md-body input[type='checkbox'] { margin-right: 0.4em; }

.md-code { overflow: hidden; border-radius: 0.5rem; border: 1px solid #e2e8f0; background: #f8fafc; }
.md-code-head { display: flex; align-items: center; justify-content: space-between; padding: 0.25rem 0.5rem 0.25rem 0.75rem; border-bottom: 1px solid #e2e8f0; font-size: 0.7rem; color: #64748b; }
.md-copy { border-radius: 0.25rem; padding: 0.1rem 0.4rem; }
.md-copy:hover { background: rgba(100, 116, 139, 0.15); color: #0f172a; }
.md-code pre { margin: 0; padding: 0.75rem; overflow-x: auto; font-size: 0.8rem; line-height: 1.55; }
.md-code code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre; }

.dark .md-body a { color: #60a5fa; }
.dark .md-body hr, .dark .md-body th, .dark .md-body td { border-color: #334155; }
.dark .md-body th { background: #1e293b; }
.dark .md-body blockquote { border-color: #475569; color: #94a3b8; }
.dark .md-code { border-color: #334155; background: #0f172a; }
.dark .md-code-head { border-color: #334155; color: #94a3b8; }
.dark .md-copy:hover { color: #f1f5f9; }

/* Syntax colours (GitHub-like), light then dark. */
.hljs-comment, .hljs-quote { color: #6a737d; font-style: italic; }
.hljs-keyword, .hljs-selector-tag, .hljs-built_in, .hljs-meta .hljs-keyword { color: #d73a49; }
.hljs-string, .hljs-regexp, .hljs-addition, .hljs-meta .hljs-string { color: #032f62; }
.hljs-number, .hljs-literal, .hljs-variable, .hljs-template-variable, .hljs-attr, .hljs-attribute { color: #005cc5; }
.hljs-title, .hljs-section, .hljs-function .hljs-title, .hljs-title.class_ { color: #6f42c1; }
.hljs-name, .hljs-tag, .hljs-selector-id, .hljs-selector-class { color: #22863a; }
.hljs-deletion { color: #b31d28; background: #ffeef0; }
.hljs-addition { background: #f0fff4; }
.hljs-meta { color: #735c0f; }
.dark .hljs-comment, .dark .hljs-quote { color: #8b949e; }
.dark .hljs-keyword, .dark .hljs-selector-tag, .dark .hljs-built_in, .dark .hljs-meta .hljs-keyword { color: #ff7b72; }
.dark .hljs-string, .dark .hljs-regexp, .dark .hljs-addition, .dark .hljs-meta .hljs-string { color: #a5d6ff; }
.dark .hljs-number, .dark .hljs-literal, .dark .hljs-variable, .dark .hljs-template-variable, .dark .hljs-attr, .dark .hljs-attribute { color: #79c0ff; }
.dark .hljs-title, .dark .hljs-section, .dark .hljs-function .hljs-title, .dark .hljs-title.class_ { color: #d2a8ff; }
.dark .hljs-name, .dark .hljs-tag, .dark .hljs-selector-id, .dark .hljs-selector-class { color: #7ee787; }
.dark .hljs-deletion { color: #ffa198; background: rgba(248, 81, 73, 0.15); }
.dark .hljs-addition { background: rgba(46, 160, 67, 0.15); }
.dark .hljs-meta { color: #d29922; }
</style>
