<script setup>
import LegalLayout from '@/Layouts/LegalLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    contactEmail: { type: String, default: '' },
});

const page = usePage();
const appName = computed(() => page.props.app?.name ?? 'dPanel');
</script>

<template>
    <Head :title="t('legal.terms.headTitle')" />

    <LegalLayout :title="t('ui.legal.terms')" :updated-at="t('legal.terms.updatedAt')">
        <p>{{ t('legal.terms.intro', { app: appName }) }}</p>

        <section>
            <h2>{{ t('legal.terms.s1.title') }}</h2>
            <ul>
                <li>{{ t('legal.terms.s1.li1') }}</li>
                <li>{{ t('legal.terms.s1.li2') }}</li>
                <li>{{ t('legal.terms.s1.li3') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ t('legal.terms.s2.title') }}</h2>
            <p>{{ t('legal.terms.s2.p1') }}</p>
            <ul>
                <li>{{ t('legal.terms.s2.li1') }}</li>
                <li>{{ t('legal.terms.s2.li2') }}</li>
                <li>{{ t('legal.terms.s2.li3') }}</li>
            </ul>
            <p>{{ t('legal.terms.s2.p2') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s3.title') }}</h2>
            <p>{{ t('legal.terms.s3.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s4.title') }}</h2>
            <p>{{ t('legal.terms.s4.p1') }}</p>
            <ul>
                <li>{{ t('legal.terms.s4.li1') }}</li>
                <li>{{ t('legal.terms.s4.li2') }}</li>
                <li>{{ t('legal.terms.s4.li3') }}</li>
                <li>{{ t('legal.terms.s4.li4') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ t('legal.terms.s5.title') }}</h2>
            <p>{{ t('legal.terms.s5.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s6.title') }}</h2>
            <p>{{ t('legal.terms.s6.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s7.title') }}</h2>
            <p>{{ t('legal.terms.s7.p1', { app: appName }) }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s8.title') }}</h2>
            <p>{{ t('legal.terms.s8.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s9.title') }}</h2>
            <p>{{ t('legal.terms.s9.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.terms.s10.title') }}</h2>
            <p>
                <i18n-t keypath="legal.terms.s10.p1" scope="global"><template #contact><a v-if="contactEmail" :href="`mailto:dev@dengrweb.com`">dev@dengrweb.com</a><span v-else>{{ t('legal.terms.s10.admin') }}</span></template></i18n-t>
            </p>
        </section>

        <p class="text-xs text-slate-500">
            <i18n-t keypath="legal.terms.outro" scope="global"><template #link><a href="/privacy-policy">{{ t('ui.legal.privacy') }}</a></template></i18n-t>
        </p>
    </LegalLayout>
</template>
