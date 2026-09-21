<script setup>
import LegalLayout from '@/Layouts/LegalLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    contactEmail: { type: String, default: '' },
});

const page = usePage();
const appName = computed(() => page.props.app?.name ?? 'dPanel');
</script>

<template>
    <Head title="Privacy Policy" />

    <LegalLayout title="Privacy Policy" updated-at="August 27, 2026">
        <p>
            This Privacy Policy explains how {{ appName }} ("we", "us", "our") collects, uses, and protects
            information when you use this control panel to manage servers, websites, mail, databases, and related
            services, including the AI Chat Engine and AI Gateway features.
        </p>

        <section>
            <h2>1. Information We Collect</h2>
            <ul>
                <li><strong>Account information</strong> — name, email address, role/permissions, and authentication details (including two-factor setup) for panel users.</li>
                <li><strong>Server &amp; hosting data</strong> — configuration and metadata for the websites, mail accounts, databases, DNS zones, and backups you manage through the panel.</li>
                <li><strong>Operational logs</strong> — request logs, audit trails, and usage records (e.g. AI Gateway request/usage logs) kept for security, billing accuracy, and troubleshooting.</li>
                <li><strong>AI Chat Engine data</strong> — messages, comments, and contact details (name, platform ID) exchanged through connected channels such as Telegram and Facebook, along with the AI-generated replies and any system prompts you configure.</li>
                <li><strong>Third-party credentials</strong> — API keys and access tokens you provide to connect external services (AI providers, Telegram bots, Facebook Pages, WHMCS, SSH targets, etc.), stored so the panel can act on your behalf.</li>
            </ul>
        </section>

        <section>
            <h2>2. How We Use Information</h2>
            <ul>
                <li>To operate, secure, and maintain the panel and the services you manage through it.</li>
                <li>To process AI requests — when you use the AI Gateway or AI Chat Engine, message content is sent to the AI provider you've configured (e.g. OpenAI, Anthropic, Google) solely to generate a response; each provider's own terms and privacy practices apply to that processing.</li>
                <li>To send messages on your behalf through connected channels (Telegram, Facebook Messenger, feed comments) as configured by you, including AI auto-replies and scheduled/broadcast messages.</li>
                <li>To monitor for security incidents, abuse, and to maintain audit logs required for reliable operation.</li>
                <li>To communicate with you about your account, service status, or changes to this policy.</li>
            </ul>
        </section>

        <section>
            <h2>3. Sharing of Information</h2>
            <p>
                We do not sell personal information. Data is shared only:
            </p>
            <ul>
                <li>With third-party AI providers, messaging platforms, and other services you explicitly connect and configure (their own privacy policies govern their processing of that data).</li>
                <li>With infrastructure providers necessary to host and run the panel.</li>
                <li>When required by law, legal process, or to protect the rights, property, or safety of the panel operator, its users, or others.</li>
            </ul>
        </section>

        <section>
            <h2>4. Data Retention</h2>
            <p>
                Account and configuration data is retained for as long as your account is active. Operational and
                request logs (including AI Gateway usage/request logs and Chat Engine conversation history) are
                retained for as long as needed for security, billing, and troubleshooting purposes, and may be
                cleared periodically or on request.
            </p>
        </section>

        <section>
            <h2>5. Security</h2>
            <p>
                We use reasonable administrative and technical safeguards — including access controls, role-based
                permissions, two-factor authentication support, and webhook signature verification for connected
                channels — to protect information from unauthorized access, alteration, or disclosure. No method of
                transmission or storage is 100% secure, and we cannot guarantee absolute security.
            </p>
        </section>

        <section>
            <h2>6. Your Choices</h2>
            <ul>
                <li>You can disconnect a channel (Telegram, Facebook Page) or AI provider at any time from the panel, which stops further data from being sent to it.</li>
                <li>You can turn off AI auto-reply per conversation or per channel and switch to manual replies.</li>
                <li>You can request access to, correction of, or deletion of your account data by contacting us using the details below.</li>
            </ul>
        </section>

        <section>
            <h2>7. Changes to This Policy</h2>
            <p>
                We may update this Privacy Policy from time to time. Material changes will be reflected by updating
                the "Last updated" date above. Continued use of the panel after changes take effect constitutes
                acceptance of the revised policy.
            </p>
        </section>

        <section>
            <h2>8. Contact Us</h2>
            <p>
                If you have questions about this Privacy Policy or how your data is handled, contact us at
                <a v-if="contactEmail" :href="`mailto:${contactEmail}`">{{ contactEmail }}</a><span v-else>the administrator of this panel</span>.
            </p>
        </section>
    </LegalLayout>
</template>
