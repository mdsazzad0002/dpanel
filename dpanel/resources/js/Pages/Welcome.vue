<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    canLogin: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
    installerBaseUrl: {
        type: String,
        required: true,
    },
    defaultPanelDomain: {
        type: String,
        default: '',
    },
    defaultServerBaseDir: {
        type: String,
        default: '',
    },
    defaultDbName: {
        type: String,
        default: 'serverpanel',
    },
    defaultDbUser: {
        type: String,
        default: 'serverpanel',
    },
    defaultDbHost: {
        type: String,
        default: '127.0.0.1',
    },
    defaultDbPort: {
        type: String,
        default: '3306',
    },
    defaultPanelEmail: {
        type: String,
        default: 'admin@example.com',
    },
});

const theme = ref('light');
const activeTab = ref('home');
const normalizedInstallerBaseUrl = computed(() => String(props.installerBaseUrl || '').replace(/\/+$/, ''));
const shellEscape = (value) => `"${String(value).replace(/(["\\$`])/g, '\\$1')}"`;

const firstRunWizard = reactive({
    preset: 'secure',
    installer_base_url: normalizedInstallerBaseUrl.value,
    project_dir: props.defaultServerBaseDir || '',
    panel_domain: props.defaultPanelDomain || '',
    panel_email: props.defaultPanelEmail || '',
    admin_user: 'serveradmin',
    panel_password: '',
    db_name: props.defaultDbName || 'serverpanel',
    db_user: props.defaultDbUser || 'serverpanel',
    db_host: props.defaultDbHost || '127.0.0.1',
    db_port: props.defaultDbPort || '3306',
    db_password: '',
    disable_root: true,
    include_firewall: true,
    include_ssl: true,
    ssh_key_only: true,
    password_length: 20,
    include_symbols: true,
});
const generatedPassword = ref('');
const installCopyStatus = ref('');
const passwordCopyStatus = ref('');

const presetMatrix = {
    basic: {
        label: 'Basic',
        modules: 'nginx,php,mariadb,supervisor',
        include_firewall: false,
        include_ssl: false,
        disable_root: false,
        ssh_key_only: false,
    },
    production: {
        label: 'Production',
        modules: 'nginx,php,mariadb,supervisor,firewall,fail2ban,ssl',
        include_firewall: true,
        include_ssl: true,
        disable_root: true,
        ssh_key_only: false,
    },
    secure: {
        label: 'Secure',
        modules: 'nginx,php,mariadb,supervisor,firewall,fail2ban,ssl,ssh-root-login',
        include_firewall: true,
        include_ssl: true,
        disable_root: true,
        ssh_key_only: true,
    },
    mail: {
        label: 'Mail Server',
        modules: 'nginx,php,mariadb,supervisor,firewall,fail2ban,ssl',
        include_firewall: true,
        include_ssl: true,
        disable_root: true,
        ssh_key_only: true,
    },
};

const selectedPreset = computed(() => presetMatrix[firstRunWizard.preset] || presetMatrix.production);
const generatedPasswordText = computed(() => generatedPassword.value || firstRunWizard.panel_password || '');
const remoteDownloadSnippet = computed(() => `

curl -fsSL "${normalizedInstallerBaseUrl.value}/discript/install.sh" -o install.sh
chmod +x install.sh
sudo env PANEL_INSTALL_BASE_URL="${normalizedInstallerBaseUrl.value}" bash install.sh panel install`);

const generatedInstallSnippet = computed(() => {
    const installerBaseUrl = String(firstRunWizard.installer_base_url || normalizedInstallerBaseUrl.value || '').replace(/\/+$/, '');
    const projectDir = String(firstRunWizard.project_dir || '').trim();
    const panelDomain = String(firstRunWizard.panel_domain || '').trim();
    const panelEmail = String(firstRunWizard.panel_email || '').trim();
    const adminUser = String(firstRunWizard.admin_user || '').trim();
    const panelPassword = String(firstRunWizard.panel_password || generatedPasswordText.value || '').trim();
    const dbName = String(firstRunWizard.db_name || '').trim();
    const dbUser = String(firstRunWizard.db_user || '').trim();
    const dbHost = String(firstRunWizard.db_host || '127.0.0.1').trim();
    const dbPort = String(firstRunWizard.db_port || '3306').trim();
    const dbPassword = String(firstRunWizard.db_password || '').trim();
    const installCommand = [
        'curl -fsSL',
        shellEscape(`${installerBaseUrl}/discript/install.sh`),
        '-o install.sh && chmod +x install.sh && sudo env',
        `PANEL_INSTALL_BASE_URL=${shellEscape(installerBaseUrl)}`,
        projectDir ? `SERVER_BASE_DIR=${shellEscape(projectDir)}` : null,
        panelDomain ? `PANEL_DOMAIN=${shellEscape(panelDomain)}` : null,
        `PANEL_MODULES=${shellEscape(selectedPreset.value.modules)}`,
        `PANEL_DB_NAME=${shellEscape(dbName || 'serverpanel')}`,
        `PANEL_DB_USER=${shellEscape(dbUser || 'serverpanel')}`,
        `PANEL_DB_HOST=${shellEscape(dbHost || '127.0.0.1')}`,
        `PANEL_DB_PORT=${shellEscape(dbPort || '3306')}`,
        dbPassword ? `PANEL_DB_PASSWORD=${shellEscape(dbPassword)}` : null,
        projectDir ? `PANEL_APP_DIR=${shellEscape(projectDir)}` : null,
        panelEmail ? `PANEL_ADMIN_EMAIL=${shellEscape(panelEmail)}` : null,
        selectedPreset.value.include_firewall ? 'SKIP_FIREWALL=false' : 'SKIP_FIREWALL=true',
        selectedPreset.value.include_ssl ? 'SKIP_SSL=false' : 'SKIP_SSL=true',
        'bash install.sh panel install',
    ].filter(Boolean).join(' ');

    if (firstRunWizard.preset !== 'secure' || !adminUser || !panelPassword) {
        return installCommand;
    }

    return [
        installCommand,
        '&& sudo /usr/local/bin/panel user:create',
        `--username ${shellEscape(adminUser)}`,
        panelEmail ? `--panel-email ${shellEscape(panelEmail)}` : null,
        `--panel-password ${shellEscape(panelPassword)}`,
        firstRunWizard.disable_root ? '--disable-root' : '--keep-root',
    ].filter(Boolean).join(' ');
});

const applyPreset = (presetKey) => {
    const preset = presetMatrix[presetKey] || presetMatrix.production;
    firstRunWizard.preset = presetKey;
    firstRunWizard.include_firewall = preset.include_firewall;
    firstRunWizard.include_ssl = preset.include_ssl;
    firstRunWizard.disable_root = preset.disable_root;
    firstRunWizard.ssh_key_only = preset.ssh_key_only;
};

const generateStrongPassword = () => {
    const length = Math.max(12, Number(firstRunWizard.password_length || 20));
    const lowers = 'abcdefghijklmnopqrstuvwxyz';
    const uppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const digits = '0123456789';
    const symbols = firstRunWizard.include_symbols ? '!@#$%^&*()-_=+[]{}<>?' : '';
    const alphabet = `${lowers}${uppers}${digits}${symbols}`;

    if (!alphabet.length) {
        return '';
    }

    const output = [];
    const pool = new Uint32Array(length);
    window.crypto.getRandomValues(pool);

    output.push(lowers[pool[0] % lowers.length]);
    output.push(uppers[pool[1] % uppers.length]);
    output.push(digits[pool[2] % digits.length]);
    if (symbols) {
        output.push(symbols[pool[3] % symbols.length]);
    }

    for (let i = output.length; i < length; i += 1) {
        output.push(alphabet[pool[i] % alphabet.length]);
    }

    for (let i = output.length - 1; i > 0; i -= 1) {
        const j = pool[i % pool.length] % (i + 1);
        [output[i], output[j]] = [output[j], output[i]];
    }

    return output.join('');
};

const createAdminPassword = () => {
    let nextPanelPassword = generateStrongPassword();
    let nextDbPassword = generateStrongPassword();

    while (nextDbPassword && nextDbPassword === nextPanelPassword) {
        nextDbPassword = generateStrongPassword();
    }

    generatedPassword.value = nextPanelPassword;
    firstRunWizard.panel_password = nextPanelPassword;
    firstRunWizard.db_password = nextDbPassword;
};

const copyGeneratedCommand = async () => {
    const value = generatedInstallSnippet.value;
    if (!value) return;

    try {
        await navigator.clipboard.writeText(value);
        installCopyStatus.value = 'Copied.';
        window.setTimeout(() => {
            installCopyStatus.value = '';
        }, 2000);
    } catch {
        installCopyStatus.value = 'Copy failed.';
    }
};

const copyGeneratedPassword = async () => {
    const value = generatedPasswordText.value;
    if (!value) return;

    try {
        await navigator.clipboard.writeText(value);
        passwordCopyStatus.value = 'Copied.';
        window.setTimeout(() => {
            passwordCopyStatus.value = '';
        }, 2000);
    } catch {
        passwordCopyStatus.value = 'Copy failed.';
    }
};

const troubleshootingSnippet = computed(() => `# If blocked by permissions
sudo bash install.sh panel install

# If file not found
ls -la
pwd
find / -type f -path "*/ServerPanel/artisan" 2>/dev/null

# If download fails
curl -I "${normalizedInstallerBaseUrl.value}/discript/install.sh"
curl -I "${normalizedInstallerBaseUrl.value}/discript/bootstrap/core.sh"`);

function applyTheme(nextTheme) {
    theme.value = nextTheme;
    document.documentElement.classList.toggle('dark', nextTheme === 'dark');
    localStorage.setItem('theme', nextTheme);
}

function toggleTheme() {
    applyTheme(theme.value === 'dark' ? 'light' : 'dark');
}

function tabClasses(tab) {
    return activeTab.value === tab
        ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
        : 'border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
}

onMounted(() => {
    createAdminPassword();
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light' || savedTheme === 'dark') {
        applyTheme(savedTheme);
        return;
    }

    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(prefersDark ? 'dark' : 'light');
});
</script>

<template>
    <Head title="ServerPanel Documentation" />

    <div class="min-h-screen bg-slate-100 text-slate-800 transition-colors dark:bg-slate-950 dark:text-slate-100">
        <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
            <header
                class="mb-8 flex flex-col gap-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">
                        ServerPanel Deployment Documentation
                    </h1>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        Separate guides for home overview, merged server+SSH setup, and installer script usage.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="toggleTheme"
                    >
                        {{ theme === 'dark' ? 'Light Mode' : 'Dark Mode' }}
                    </button>

                    <nav v-if="canLogin" class="flex items-center gap-2">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-white"
                        >
                            Dashboard
                        </Link>

                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                Log in
                            </Link>
                        </template>
                    </nav>
                </div>
            </header>

            <main class="space-y-6">
                <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="tabClasses('home')"
                            @click="activeTab = 'home'"
                        >
                            Home
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="tabClasses('server')"
                            @click="activeTab = 'server'"
                        >
                            Server + SSH Setup
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="tabClasses('installer')"
                            @click="activeTab = 'installer'"
                        >
                            Installer Setup
                        </button>
                    </div>
                </section>

                <div v-if="activeTab === 'home'" class="space-y-6">
                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">
                            Home Guide
                        </h2>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                            Use this page as the main index. Each tab focuses on one task so setup is easier and cleaner.
                        </p>
                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                                <p class="font-semibold text-slate-900 dark:text-white">Server + SSH Setup</p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    OpenSSH install, firewall, server packages, and Laravel deployment steps.
                                </p>
                            </div>
                            <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                                <p class="font-semibold text-slate-900 dark:text-white">Installer Setup</p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    One-command installation using `discript/install.sh`.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <div v-if="activeTab === 'server'" class="space-y-6">
                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">SSH Setup Guide</h2>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                            Start from SSH setup first, then continue with server setup steps below.
                        </p>
                    </section>
                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">1. Introduction</h2>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                            SSH (Secure Shell) is an encrypted protocol used to access and manage remote servers securely.
                        </p>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">2. Install SSH Server</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>sudo apt update
sudo apt install -y openssh-server</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">3. Enable and Start SSH Service</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>sudo systemctl start ssh
sudo systemctl enable ssh</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">4. Check SSH Status</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>sudo systemctl status ssh
sudo systemctl is-active ssh</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">5. Find Server IP Address</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>ip a
hostname -I</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">6. Allow SSH Through Firewall</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>sudo ufw allow OpenSSH
sudo ufw allow 22/tcp
sudo ufw enable
sudo ufw status</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">7. Connect to the Server</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>ssh username@server_ip</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">8. Example Connection</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>ssh root@192.168.0.100</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">9. Troubleshooting</h2>
                        <div class="mt-4 space-y-4 text-sm text-slate-700 dark:text-slate-300">
                            <p>Connection timeout: check firewall and network routing.</p>
                            <p>SSH service not running: `sudo systemctl restart ssh`.</p>
                            <p>Firewall blocking port 22: verify `sudo ufw status`.</p>
                            <p>Wrong IP address: re-check `hostname -I`.</p>
                        </div>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">10. Security Best Practices</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code># Disable root login
sudo nano /etc/ssh/sshd_config
PermitRootLogin no
sudo systemctl restart ssh

# Use SSH keys
ssh-keygen -t ed25519 -C "admin@example.com"
ssh-copy-id username@server_ip

# Optional: change SSH port
sudo nano /etc/ssh/sshd_config
Port 2222
sudo ufw allow 2222/tcp
sudo systemctl restart ssh</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Server Setup Guide</h2>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                            After SSH is ready, continue with package install and panel deployment.
                        </p>
                    </section>
                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">1. Connect to Ubuntu Server</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>ssh-keygen -t ed25519 -C "your_email@example.com"
ssh-copy-id ubuntu@your_server_ip
ssh ubuntu@your_server_ip</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">2. Install Required Packages</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx git unzip curl sqlite3
sudo apt install -y php-cli php-fpm php-mbstring php-xml php-curl php-zip php-sqlite3
sudo apt install -y composer nodejs npm</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">3. Install and Configure ServerPanel</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>cd /var/www
sudo git clone &lt;your-repository-url&gt; serverpanel
cd serverpanel

sudo composer install --no-dev --optimize-autoloader
npm install
npm run build

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate --force
php artisan optimize</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">4. Run Process (Production)</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>sudo chown -R www-data:www-data /var/www/serverpanel
sudo chmod -R 775 /var/www/serverpanel/storage /var/www/serverpanel/bootstrap/cache

sudo systemctl enable nginx
sudo systemctl enable php8.3-fpm
sudo systemctl restart nginx
sudo systemctl restart php8.3-fpm</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">5. Quick Fixes</h2>
                        <div class="mt-4 space-y-4 text-sm text-slate-700 dark:text-slate-300">
                            <p>Permission denied (publickey): add your public key to `~/.ssh/authorized_keys`.</p>
                            <p>502 Bad Gateway: check `sudo systemctl status php8.3-fpm`.</p>
                            <p>Build failed: reinstall npm dependencies and run build again.</p>
                            <p>SQLite error: ensure `DB_CONNECTION=sqlite` and `database/database.sqlite` exists.</p>
                        </div>
                    </section>
                </div>

                <div v-if="activeTab === 'installer'" class="space-y-6">
                    <section class="rounded-xl border border-cyan-200 bg-cyan-50 p-6 shadow-sm ring-1 ring-cyan-100 dark:border-cyan-900/60 dark:bg-cyan-950/20 dark:ring-cyan-900/30">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="max-w-2xl">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-800 dark:text-cyan-200">First-Run Wizard</p>
                                <h2 class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">Generate a secure install command</h2>
                                <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                                    Pick a preset, fill the important defaults once, and the wizard builds the install command with database and hardening values prefilled.
                                </p>
                            </div>
                            <div class="rounded-xl bg-white p-4 text-xs text-slate-600 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
                                <p class="font-semibold text-slate-900 dark:text-white">Preset order</p>
                                <ol class="mt-2 space-y-1">
                                    <li>1. Basic</li>
                                    <li>2. Production</li>
                                    <li>3. Secure</li>
                                    <li>4. Mail server</li>
                                </ol>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-4 lg:grid-cols-2">
                            <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                                <div>
                                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Preset</label>
                                    <select v-model="firstRunWizard.preset" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" @change="applyPreset(firstRunWizard.preset)">
                                        <option value="basic">Basic</option>
                                        <option value="production">Production</option>
                                        <option value="secure">Secure</option>
                                        <option value="mail">Mail Server</option>
                                    </select>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Installer base URL</label>
                                        <input v-model="firstRunWizard.installer_base_url" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Project directory</label>
                                        <input v-model="firstRunWizard.project_dir" type="text" placeholder="/var/www/serverpanel" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Panel domain</label>
                                        <input v-model="firstRunWizard.panel_domain" type="text" placeholder="panel.example.com" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Panel email</label>
                                        <input v-model="firstRunWizard.panel_email" type="email" placeholder="admin@example.com" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Admin user</label>
                                        <input v-model="firstRunWizard.admin_user" type="text" placeholder="serveradmin" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Database name</label>
                                        <input v-model="firstRunWizard.db_name" type="text" placeholder="serverpanel" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Database user</label>
                                        <input v-model="firstRunWizard.db_user" type="text" placeholder="serverpanel" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Database host</label>
                                        <input v-model="firstRunWizard.db_host" type="text" placeholder="127.0.0.1" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Database port</label>
                                        <input v-model="firstRunWizard.db_port" type="text" placeholder="3306" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Panel admin password</label>
                                        <input v-model="firstRunWizard.panel_password" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono dark:border-slate-700 dark:bg-slate-800" placeholder="Generate or paste once" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Database password</label>
                                        <input v-model="firstRunWizard.db_password" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono dark:border-slate-700 dark:bg-slate-800" placeholder="Generate or paste once" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Password length</label>
                                        <input v-model="firstRunWizard.password_length" type="number" min="12" max="64" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-3 text-xs text-slate-700 dark:text-slate-300">
                                    <label class="flex items-center gap-2">
                                        <input v-model="firstRunWizard.disable_root" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                                        Disable root login
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="firstRunWizard.ssh_key_only" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                                        SSH key-only mode
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="firstRunWizard.include_firewall" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                                        Include firewall
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="firstRunWizard.include_ssl" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                                        Include SSL
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input v-model="firstRunWizard.include_symbols" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                                        Symbols in password
                                    </label>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-white dark:bg-slate-100 dark:text-slate-900" @click="createAdminPassword">
                                        Generate Strong Password
                                    </button>
                                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200" @click="copyGeneratedPassword">
                                        Copy Password
                                    </button>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ passwordCopyStatus || 'The password is displayed once here for copying.' }}</p>

                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Generated Password</p>
                                    <p class="mt-2 break-all font-mono text-sm text-slate-900 dark:text-slate-100">{{ generatedPasswordText }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 rounded-xl bg-slate-950 p-4 text-slate-100 shadow-inner">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Generated Command</p>
                                    <p class="text-sm text-slate-300">Copy this once and run it on the target server.</p>
                                </div>
                                <button type="button" class="rounded-md border border-slate-600 px-3 py-2 text-xs font-semibold text-slate-100 hover:bg-slate-800" @click="copyGeneratedCommand">
                                    Copy Install Command
                                </button>
                            </div>
                            <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-black/40 p-4 font-mono text-xs text-emerald-300">{{ generatedInstallSnippet }}</pre>
                            <p class="mt-2 text-xs text-slate-400">{{ installCopyStatus || 'Secure preset installs ssh-root-login and runs panel user:create after bootstrap. Mail preset keeps mail-stack follow-up manual for now.' }}</p>
                        </div>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Bootstrap Setup Guide</h2>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                            Use one file: `discript/install.sh` (installer + bootstrap menu).
                        </p>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">1. Root Directory Check</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>cd /path/to/ServerInstaller
ls -la

# Required file in root:
# discript/install.sh</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">2. Run Interactive Menu</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>chmod +x discript/install.sh
sudo env PANEL_INSTALL_BASE_URL="https://your-domain.example" bash discript/install.sh panel install</code></pre>
                        <p class="mt-3 text-sm text-slate-700 dark:text-slate-300">
                            The install flow brings in the runtime, installs selected modules, provisions the database, and updates `.env` automatically.
                        </p>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">3. Direct CLI Examples (No Menu)</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code># Recommended baseline
sudo env PANEL_INSTALL_BASE_URL="https://your-domain.example" SERVER_BASE_DIR="/var/www/serverpanel" PANEL_DOMAIN="panel.example.com" bash discript/install.sh panel install

# Secure preset
sudo env PANEL_INSTALL_BASE_URL="https://your-domain.example" SERVER_BASE_DIR="/var/www/serverpanel" PANEL_DOMAIN="panel.example.com" PANEL_MODULES="nginx,php,mariadb,supervisor,firewall,fail2ban,ssl,ssh-root-login" bash discript/install.sh panel install

# Custom DB credentials
sudo env PANEL_INSTALL_BASE_URL="https://your-domain.example" SERVER_BASE_DIR="/var/www/serverpanel" PANEL_DB_NAME="serverpanel" PANEL_DB_USER="serverpanel" PANEL_DB_PASSWORD="StrongPassword123" bash discript/install.sh panel install</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">4. Remote Download + Run</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>{{ remoteDownloadSnippet }}</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">5. Troubleshooting</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>{{ troubleshootingSnippet }}</code></pre>
                    </section>
                </div>
            </main>

            <footer class="mt-8 text-center text-sm text-slate-500 dark:text-slate-400">
                Laravel v{{ laravelVersion }} (PHP v{{ phpVersion }})
            </footer>
        </div>
    </div>
</template>
