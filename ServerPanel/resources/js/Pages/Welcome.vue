<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
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
});

const theme = ref('light');
const activeTab = ref('home');

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
                        Separate guides for home overview, SSH setup, server setup, and installer script usage.
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

                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-white"
                            >
                                Register
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
                            :class="tabClasses('ssh')"
                            @click="activeTab = 'ssh'"
                        >
                            SSH Setup
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="tabClasses('server')"
                            @click="activeTab = 'server'"
                        >
                            Server Setup
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
                                <p class="font-semibold text-slate-900 dark:text-white">SSH Setup</p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    Install OpenSSH, open firewall, and connect safely.
                                </p>
                            </div>
                            <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                                <p class="font-semibold text-slate-900 dark:text-white">Server Setup</p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    Manual package installation and Laravel deployment steps.
                                </p>
                            </div>
                            <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                                <p class="font-semibold text-slate-900 dark:text-white">Installer Setup</p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    One-command installation using `installer.sh`.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <div v-if="activeTab === 'ssh'" class="space-y-6">
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
                </div>

                <div v-if="activeTab === 'server'" class="space-y-6">
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
                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Installer Setup Guide</h2>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                            This guide uses the CyberPanel-style script at `/a_final_storing/ServerInstaller/installer.sh`.
                        </p>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">1. Download Script</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>curl -fsSL "http://192.168.0.50/a_final_storing/ServerInstaller/installer.sh" -o installer.sh
chmod +x installer.sh
sudo bash installer.sh --base-url "http://192.168.0.50/a_final_storing/ServerInstaller/" --remote-project-dir "/root/ServerPanel" --web-server both --php-versions "7.4,8.0,8.2,8.3,8.4,8.5" --php-default 8.2 --panel-port 8090
</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">2. Make Executable and Run</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code>chmod +x installer.sh

# Recommended (auto-detect archive from base URL)
sudo bash installer.sh --base-url "http://192.168.0.50/a_final_storing/ServerInstaller/" --remote-project-dir "/root/ServerPanel" --panel-port 8090

# Apache + multi PHP versions + MySQL + phpMyAdmin
sudo bash installer.sh --base-url "http://192.168.0.50/a_final_storing/ServerInstaller/" --remote-project-dir "/root/ServerPanel" --web-server apache --php-versions "7.4,8.0,8.2,8.3,8.4,8.5"

# OpenLiteSpeed + multi LSPHP versions + MySQL + phpMyAdmin
sudo bash installer.sh --base-url "http://192.168.0.50/a_final_storing/ServerInstaller/" --remote-project-dir "/root/ServerPanel" --web-server openlitespeed --php-versions "7.4,8.0,8.2,8.3,8.4,8.5"

# Install BOTH Apache + OpenLiteSpeed with JSON/PDO runtime checks
sudo bash installer.sh --base-url "http://192.168.0.50/a_final_storing/ServerInstaller/" --remote-project-dir "/root/ServerPanel" --web-server both --php-versions "7.4,8.0,8.2,8.3,8.4,8.5" --php-default 8.2 --panel-port 8090

# Optional custom database credentials (default is auto-generated random password)
sudo bash installer.sh --base-url "http://192.168.0.50/a_final_storing/ServerInstaller/" --remote-project-dir "/root/ServerPanel" --db-name "serverinstaller" --db-user "serverpanel" --db-password "StrongPassword123"

# Direct archive URL
sudo bash installer.sh --project-url "http://192.168.0.50/a_final_storing/ServerInstaller/ServerInstaller.zip" --project-target "/root/ServerPanel"

# Existing local project path
sudo bash installer.sh --project-dir "/root/ServerPanel"</code></pre>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">3. What Script Does</h2>
                        <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                            <p>Checks root access and Ubuntu OS.</p>
                            <p>Finds project path automatically (`ServerPanel` or `../ServerPanel`).</p>
                            <p>Can download `.tar.gz`, `.tgz`, or `.zip` project archives from URL.</p>
                            <p>Can auto-detect archive from a base URL using `--base-url`.</p>
                            <p>Moves extracted project to your target path (`--project-target` or `--remote-project-dir`).</p>
                            <p>Supports `--web-server apache|openlitespeed` and configurable PHP versions.</p>
                            <p>Sets CLI runtime to `--php-default` (default `8.2`) for Composer and Artisan.</p>
                            <p>Creates MySQL database/user automatically and updates `.env` connection values.</p>
                            <p>Generates a random DB password when `--db-password` is not provided.</p>
                            <p>Checks installed items first and installs only missing packages.</p>
                            <p>Installs Apache/OpenLiteSpeed, PHP, MySQL, phpMyAdmin, Composer, Node.js, SSH, and dependencies.</p>
                            <p>Maps default Apache `:80` to panel service port (default `:8090`) to avoid Forbidden on server IP.</p>
                            <p>Runs Laravel setup commands and migrations.</p>
                            <p>Applies permissions and restarts required services.</p>
                        </div>
                    </section>

                    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">4. Troubleshooting</h2>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-black sm:text-sm"><code># If blocked by permissions
sudo bash installer.sh --project-dir "/absolute/path/to/ServerPanel"

# If file not found
ls -la
pwd
find / -type f -path "*/ServerPanel/artisan" 2>/dev/null

# If download fails
ping 192.168.0.50
curl -I http://192.168.0.50/a_final_storing/ServerInstaller/installer.sh
curl -I http://192.168.0.50/a_final_storing/ServerInstaller/ServerInstaller.zip</code></pre>
                    </section>
                </div>
            </main>

            <footer class="mt-8 text-center text-sm text-slate-500 dark:text-slate-400">
                Laravel v{{ laravelVersion }} (PHP v{{ phpVersion }})
            </footer>
        </div>
    </div>
</template>
