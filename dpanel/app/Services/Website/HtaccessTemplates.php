<?php

namespace App\Services\Website;

/**
 * Stock .htaccess files per project type, for the .htaccess resolver's
 * templates and its suggested fixes. A template with both `root` and
 * `public` fills both files: the project-root file forwards everything into
 * public/ for Apache hosts whose document root is the project folder.
 * `snippet` templates are added to the top of a file instead.
 */
class HtaccessTemplates
{
    /** Project types whose public file needs a front-controller rule, and the file it routes to. */
    public const FRONT_CONTROLLERS = [
        'wordpress' => 'index.php',
        'laravel' => 'index.php',
        'codeigniter' => 'index.php',
        'spa' => 'index.html',
    ];

    /** Project types that keep their web files in public/ under the project root. */
    public const PUBLIC_FOLDER_APPS = ['laravel', 'codeigniter'];

    /** @return array<int, array{key: string, label: string, snippet: bool, root: string|null, public: string}> */
    public static function all(): array
    {
        return [
            ['key' => 'wordpress', 'label' => 'WordPress', 'snippet' => false, 'root' => null, 'public' => self::WORDPRESS],
            ['key' => 'laravel', 'label' => 'Laravel', 'snippet' => false, 'root' => self::forwardToPublic('artisan'), 'public' => self::LARAVEL],
            ['key' => 'codeigniter', 'label' => 'CodeIgniter 4', 'snippet' => false, 'root' => self::forwardToPublic('spark'), 'public' => self::CODEIGNITER],
            ['key' => 'spa', 'label' => 'Single-page app (React / Vue)', 'snippet' => false, 'root' => null, 'public' => self::SPA],
            ['key' => 'https', 'label' => 'Force HTTPS + www → root', 'snippet' => true, 'root' => null, 'public' => self::HTTPS],
            ['key' => 'hardening', 'label' => 'Security hardening', 'snippet' => true, 'root' => null, 'public' => self::HARDENING],
        ];
    }

    /** @return array{key: string, label: string, snippet: bool, root: string|null, public: string}|null */
    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }

    /**
     * Picks the site's PHP version on Apache hosts. cPanel (EasyApache) reads
     * and rewrites this exact block, so it is kept in cPanel's own format.
     */
    public static function phpHandler(string $version): string
    {
        $handler = 'ea-php'.str_replace('.', '', $version);

        return <<<TXT
            # php -- BEGIN cPanel-generated handler, do not edit
            # Set the "{$handler}" package as the default "PHP" programming language.
            <IfModule mime_module>
              AddHandler application/x-httpd-{$handler} .php .php8 .phtml
            </IfModule>
            # php -- END cPanel-generated handler, do not edit

            TXT;
    }

    private static function forwardToPublic(string $cli): string
    {
        return <<<TXT
            <IfModule mod_rewrite.c>
                RewriteEngine On
                # Serve the app from public/ when the host points at the project folder.
                RewriteCond %{REQUEST_URI} !^/public/
                RewriteRule ^(.*)$ public/$1 [L]
            </IfModule>

            # Never serve project files from the root.
            <FilesMatch "^\\.(env|git)|composer\\.(json|lock)$|{$cli}$">
                Require all denied
            </FilesMatch>

            TXT;
    }

    private const WORDPRESS = <<<'TXT'
        # BEGIN WordPress
        <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
        RewriteBase /
        RewriteRule ^index\.php$ - [L]
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule . /index.php [L]
        </IfModule>
        # END WordPress

        TXT;

    private const LARAVEL = <<<'TXT'
        <IfModule mod_rewrite.c>
            <IfModule mod_negotiation.c>
                Options -MultiViews -Indexes
            </IfModule>

            RewriteEngine On

            # Handle Authorization Header
            RewriteCond %{HTTP:Authorization} .
            RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

            # Handle X-XSRF-Token Header
            RewriteCond %{HTTP:x-xsrf-token} .
            RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

            # Redirect Trailing Slashes If Not A Folder...
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_URI} (.+)/$
            RewriteRule ^ %1 [L,R=301]

            # Send Requests To Front Controller...
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule ^ index.php [L]
        </IfModule>

        TXT;

    private const CODEIGNITER = <<<'TXT'
        Options -Indexes

        <IfModule mod_rewrite.c>
            Options +FollowSymlinks
            RewriteEngine On

            # Redirect Trailing Slashes...
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteCond %{REQUEST_URI} (.+)/$
            RewriteRule ^ %1 [L,R=301]

            # Send everything that is not a file or folder to index.php.
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^(.*)$ index.php/$1 [L,NC,QSA]

            # Ensure Authorization header is passed along
            RewriteCond %{HTTP:Authorization} .
            RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
        </IfModule>

        TXT;

    private const SPA = <<<'TXT'
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteBase /
            RewriteRule ^index\.html$ - [L]
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule . /index.html [L]
        </IfModule>

        TXT;

    private const HTTPS = <<<'TXT'
        # Force HTTPS and drop www.
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{HTTPS} off [OR]
            RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
            RewriteCond %{HTTP_HOST} ^(?:www\.)?(.+)$ [NC]
            RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]
        </IfModule>

        TXT;

    private const HARDENING = <<<'TXT'
        # Block hidden files, backups and logs.
        <FilesMatch "^\.|\.(bak|log|sql|sh|ini|env)$">
            Require all denied
        </FilesMatch>

        # WordPress: no XML-RPC.
        <Files xmlrpc.php>
            Require all denied
        </Files>

        Options -Indexes

        TXT;
}
