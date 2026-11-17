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
    <Head :title="t('ui.legal.privacy')" />

    <LegalLayout :title="t('ui.legal.privacy')" :updated-at="t('legal.terms.updatedAt')">
        <p>{{ t('legal.privacy.intro', { app: appName }) }}</p>

        <section>
            <h2>{{ t('legal.privacy.s1.title') }}</h2>
            <ul>
                <li><strong>{{ t('legal.privacy.s1.account.label') }}</strong> — {{ t('legal.privacy.s1.account.text') }}</li>
                <li><strong>{{ t('legal.privacy.s1.hosting.label') }}</strong> — {{ t('legal.privacy.s1.hosting.text') }}</li>
                <li><strong>{{ t('legal.privacy.s1.logs.label') }}</strong> — {{ t('legal.privacy.s1.logs.text') }}</li>
                <li><strong>{{ t('legal.privacy.s1.chat.label') }}</strong> — {{ t('legal.privacy.s1.chat.text') }}</li>
                <li><strong>{{ t('legal.privacy.s1.creds.label') }}</strong> — {{ t('legal.privacy.s1.creds.text') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s2.title') }}</h2>
            <ul>
                <li>{{ t('legal.privacy.s2.li1') }}</li>
                <li>{{ t('legal.privacy.s2.li2') }}</li>
                <li>{{ t('legal.privacy.s2.li3') }}</li>
                <li>{{ t('legal.privacy.s2.li4') }}</li>
                <li>{{ t('legal.privacy.s2.li5') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s3.title') }}</h2>
            <p>{{ t('legal.privacy.s3.p1') }}</p>
            <ul>
                <li>{{ t('legal.privacy.s3.li1') }}</li>
                <li>{{ t('legal.privacy.s3.li2') }}</li>
                <li>{{ t('legal.privacy.s3.li3') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s4.title') }}</h2>
            <p>{{ t('legal.privacy.s4.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s5.title') }}</h2>
            <p>{{ t('legal.privacy.s5.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s6.title') }}</h2>
            <ul>
                <li>{{ t('legal.privacy.s6.li1') }}</li>
                <li>{{ t('legal.privacy.s6.li2') }}</li>
                <li>{{ t('legal.privacy.s6.li3') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s7.title') }}</h2>
            <p>{{ t('legal.privacy.s7.p1') }}</p>
        </section>

        <section>
            <h2>{{ t('legal.privacy.s8.title') }}</h2>
            <p>
                <i18n-t keypath="legal.privacy.s8.p1" scope="global"><template #contact><a v-if="contactEmail" :href="`mailto:dev@dengrweb.com`">dev@dengrweb.com</a><span v-else>{{ t('legal.terms.s10.admin') }}</span></template></i18n-t>
            </p>
        </section>
    </LegalLayout>
</template>
