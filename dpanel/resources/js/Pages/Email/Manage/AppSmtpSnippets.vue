<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    email: { type: String, required: true },
    host: { type: String, required: true },
    fromName: { type: String, default: '' },
});

// The password is never shown by the panel, so snippets carry a placeholder.
const PASSWORD = 'YOUR_MAILBOX_PASSWORD';

const name = computed(() => props.fromName || props.email.split('@')[1] || 'My App');

const frameworks = computed(() => {
    const { email, host } = props;
    const from = name.value;

    return [
        {
            key: 'laravel',
            label: 'Laravel',
            icon: 'bi-fire',
            note: t('email.connectGuide.apps.laravelNote'),
            blocks: [{
                file: '.env',
                lang: 'dotenv',
                code: `MAIL_MAILER=smtp
MAIL_HOST=${host}
MAIL_PORT=465
# Laravel 11+ uses MAIL_SCHEME; Laravel 10 and older use MAIL_ENCRYPTION.
MAIL_SCHEME=smtps
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=${email}
MAIL_PASSWORD="${PASSWORD}"
MAIL_FROM_ADDRESS="${email}"
MAIL_FROM_NAME="${from}"`,
            }, {
                file: t('email.connectGuide.apps.terminal'),
                lang: 'bash',
                code: `php artisan config:clear
php artisan tinker --execute="Mail::raw('SMTP works', fn (\\$m) => \\$m->to('${email}')->subject('Test'));"`,
            }],
        },
        {
            key: 'wordpress',
            label: 'WordPress',
            icon: 'bi-wordpress',
            note: t('email.connectGuide.apps.wordpressNote'),
            blocks: [{
                file: 'wp-config.php',
                lang: 'php',
                code: `define('SMTP_HOST', '${host}');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', '${email}');
define('SMTP_PASS', '${PASSWORD}');
define('SMTP_FROM', '${email}');
define('SMTP_NAME', '${from}');`,
            }, {
                file: 'wp-content/mu-plugins/smtp.php',
                lang: 'php',
                code: `<?php
/**
 * Plugin Name: SMTP mail
 * Description: Sends all wp_mail() through the mailbox SMTP server.
 */
add_action('phpmailer_init', function ($mail) {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = SMTP_PORT;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->setFrom(SMTP_FROM, SMTP_NAME);
});

add_filter('wp_mail_from', fn () => SMTP_FROM);
add_filter('wp_mail_from_name', fn () => SMTP_NAME);`,
            }],
        },
        {
            key: 'ci4',
            label: 'CodeIgniter 4',
            icon: 'bi-fire',
            note: t('email.connectGuide.apps.ci4Note'),
            blocks: [{
                file: '.env',
                lang: 'dotenv',
                code: `email.fromEmail = '${email}'
email.fromName = '${from}'
email.protocol = 'smtp'
email.SMTPHost = '${host}'
email.SMTPUser = '${email}'
email.SMTPPass = '${PASSWORD}'
email.SMTPPort = 465
email.SMTPCrypto = 'ssl'
email.mailType = 'html'`,
            }, {
                file: 'Controller',
                lang: 'php',
                code: `$email = service('email');
$email->setTo('someone@example.com');
$email->setSubject('Test');
$email->setMessage('SMTP works');
$email->send() || log_message('error', $email->printDebugger(['headers']));`,
            }],
        },
        {
            key: 'ci3',
            label: 'CodeIgniter 3',
            icon: 'bi-fire',
            note: t('email.connectGuide.apps.ci3Note'),
            blocks: [{
                file: 'application/config/email.php',
                lang: 'php',
                code: `<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$config['protocol']    = 'smtp';
$config['smtp_host']   = '${host}';
$config['smtp_user']   = '${email}';
$config['smtp_pass']   = '${PASSWORD}';
$config['smtp_port']   = 465;
$config['smtp_crypto'] = 'ssl';
$config['mailtype']    = 'html';
$config['charset']     = 'utf-8';
$config['newline']     = "\\r\\n";
$config['crlf']        = "\\r\\n";`,
            }, {
                file: 'Controller',
                lang: 'php',
                code: `$this->load->library('email');
$this->email->from('${email}', '${from}');
$this->email->to('someone@example.com');
$this->email->subject('Test');
$this->email->message('SMTP works');
$this->email->send();`,
            }],
        },
        {
            key: 'phpmailer',
            label: 'PHP (PHPMailer)',
            icon: 'bi-filetype-php',
            note: t('email.connectGuide.apps.phpmailerNote'),
            blocks: [{
                file: 'send.php',
                lang: 'php',
                code: `<?php
use PHPMailer\\PHPMailer\\PHPMailer;

require 'vendor/autoload.php'; // composer require phpmailer/phpmailer

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host       = '${host}';
$mail->Port       = 465;
$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
$mail->SMTPAuth   = true;
$mail->Username   = '${email}';
$mail->Password   = '${PASSWORD}';
$mail->CharSet    = 'UTF-8';

$mail->setFrom('${email}', '${from}');
$mail->addAddress('someone@example.com');
$mail->Subject = 'Test';
$mail->Body    = 'SMTP works';
$mail->send();`,
            }],
        },
        {
            key: 'symfony',
            label: 'Symfony',
            icon: 'bi-braces',
            note: t('email.connectGuide.apps.symfonyNote'),
            blocks: [{
                file: '.env.local',
                lang: 'dotenv',
                code: `MAILER_DSN=smtps://${encodeURIComponent(email)}:${PASSWORD}@${host}:465`,
            }],
        },
        {
            key: 'node',
            label: 'Node.js',
            icon: 'bi-node-plus',
            note: t('email.connectGuide.apps.nodeNote'),
            blocks: [{
                file: 'mail.js',
                lang: 'javascript',
                code: `// npm install nodemailer
const nodemailer = require('nodemailer');

const transporter = nodemailer.createTransport({
    host: '${host}',
    port: 465,
    secure: true,
    auth: {
        user: '${email}',
        pass: process.env.SMTP_PASS, // ${PASSWORD}
    },
});

transporter.sendMail({
    from: '"${from}" <${email}>',
    to: 'someone@example.com',
    subject: 'Test',
    text: 'SMTP works',
}).then((info) => console.log('Sent', info.messageId));`,
            }],
        },
        {
            key: 'python',
            label: 'Python',
            icon: 'bi-filetype-py',
            note: t('email.connectGuide.apps.pythonNote'),
            blocks: [{
                file: 'send_mail.py',
                lang: 'python',
                code: `import smtplib
from email.message import EmailMessage

msg = EmailMessage()
msg["From"] = "${from} <${email}>"
msg["To"] = "someone@example.com"
msg["Subject"] = "Test"
msg.set_content("SMTP works")

with smtplib.SMTP_SSL("${host}", 465) as smtp:
    smtp.login("${email}", "${PASSWORD}")
    smtp.send_message(msg)`,
            }],
        },
        {
            key: 'django',
            label: 'Django',
            icon: 'bi-filetype-py',
            note: t('email.connectGuide.apps.djangoNote'),
            blocks: [{
                file: 'settings.py',
                lang: 'python',
                code: `EMAIL_BACKEND = "django.core.mail.backends.smtp.EmailBackend"
EMAIL_HOST = "${host}"
EMAIL_PORT = 465
EMAIL_USE_SSL = True
EMAIL_HOST_USER = "${email}"
EMAIL_HOST_PASSWORD = "${PASSWORD}"
DEFAULT_FROM_EMAIL = "${from} <${email}>"`,
            }],
        },
    ];
});

const activeKey = ref('laravel');
const active = computed(() => frameworks.value.find((item) => item.key === activeKey.value) || frameworks.value[0]);

const copied = ref('');
const copy = async (id, code) => {
    try {
        await navigator.clipboard.writeText(code);
    }
    catch {
        // Older browsers / non-HTTPS: fall back to a hidden textarea.
        const area = document.createElement('textarea');
        area.value = code;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }
    copied.value = id;
    window.setTimeout(() => { if (copied.value === id) copied.value = ''; }, 1500);
};
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 p-4 dark:border-slate-800">
            <h2 class="font-semibold">{{ t('email.connectGuide.apps.title') }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('email.connectGuide.apps.help') }}</p>
        </div>

        <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-2 dark:border-slate-800" role="tablist">
            <button
                v-for="item in frameworks"
                :key="item.key"
                type="button"
                role="tab"
                :aria-selected="item.key === activeKey"
                :class="[
                    'inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition',
                    item.key === activeKey
                        ? 'border-blue-600 text-blue-700 dark:border-blue-400 dark:text-blue-300'
                        : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200',
                ]"
                @click="activeKey = item.key"
            >
                <i :class="['bi', item.icon]"></i>
                {{ item.label }}
            </button>
        </div>

        <div class="space-y-4 p-4" role="tabpanel">
            <p v-if="active.note" class="text-sm text-slate-600 dark:text-slate-300">{{ active.note }}</p>

            <div v-for="(block, index) in active.blocks" :key="`${active.key}-${index}`" class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/60">
                    <span class="truncate font-mono text-xs text-slate-600 dark:text-slate-300"><i class="bi bi-file-earmark-code mr-1"></i>{{ block.file }}</span>
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="copy(`${active.key}-${index}`, block.code)"
                    >
                        <i :class="copied === `${active.key}-${index}` ? 'bi bi-check2 text-emerald-600' : 'bi bi-copy'"></i>
                        {{ copied === `${active.key}-${index}` ? t('email.connectGuide.apps.copied') : t('email.connectGuide.apps.copyCode') }}
                    </button>
                </div>
                <pre class="max-h-[420px] overflow-auto bg-slate-950 p-4 text-[12.5px] leading-relaxed text-slate-100"><code>{{ block.code }}</code></pre>
            </div>

            <div class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                <i class="bi bi-shield-lock mt-0.5"></i>
                <p>{{ t('email.connectGuide.apps.passwordHint', { placeholder: PASSWORD }) }}</p>
            </div>
        </div>
    </section>
</template>
