<?php

namespace Tests\Unit;

use App\Services\Website\HtaccessFixer;
use App\Services\Website\HtaccessResolver;
use App\Services\Website\HtaccessTemplates;
use PHPUnit\Framework\TestCase;

class HtaccessResolverTest extends TestCase
{
    /** @return array<int, string> "line status title" for each finding */
    private function summary(string $content, string $file = '.htaccess'): array
    {
        return array_map(
            fn (array $finding): string => "{$finding['line']} {$finding['status']} {$finding['title']}",
            HtaccessResolver::analyze($content, $file, 'example.com'),
        );
    }

    public function test_stock_wordpress_and_laravel_files_need_nothing(): void
    {
        $wordpress = HtaccessResolver::analyze(<<<'TXT'
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
            TXT, '.htaccess', 'example.com');
        $this->assertSame(['native'], array_values(array_unique(array_column($wordpress, 'status'))));

        $laravel = $this->summary(<<<'TXT'
            <IfModule mod_rewrite.c>
                RewriteEngine On
                RewriteCond %{HTTP:x-xsrf-token} .
                RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]
                RewriteCond %{REQUEST_FILENAME} !-d
                RewriteCond %{REQUEST_URI} (.+)/$
                RewriteRule ^ %1 [L,R=301]
                RewriteCond %{REQUEST_FILENAME} !-d
                RewriteCond %{REQUEST_FILENAME} !-f
                RewriteRule ^ index.php [L]
            </IfModule>
            TXT);
        $this->assertSame([
            '2 native RewriteEngine',
            '3 native Request header pass-through',
            '5 manual Trailing-slash redirect',
            '8 native Front controller (pretty URLs)',
        ], $laravel);
    }

    public function test_canonical_redirects_become_redirect_rules(): void
    {
        $findings = HtaccessResolver::analyze(<<<'TXT'
            RewriteCond %{HTTPS} off [OR]
            RewriteCond %{HTTP_HOST} ^www\.example\.com [NC]
            RewriteRule ^ https://example.com%{REQUEST_URI} [L,R=301]
            RewriteCond %{HTTP_HOST} ^old\.example\.com$
            RewriteRule ^(.*)$ https://new-site.org/$1 [R=302,L]
            RewriteRule ^shop/(.*)$ https://store.example.com/$1 [R=301,L]
            TXT, '.htaccess', 'example.com');

        $this->assertSame('convertible', $findings[0]['status']);
        $this->assertSame(['http_to_https', 'www_to_root'], array_column($findings[0]['actions'], 'kind'));
        $this->assertSame(['type' => 'redirect', 'kind' => 'to_domain', 'target' => 'new-site.org', 'status_code' => 302], $findings[1]['actions'][0]);
        // Only /shop/ moves, which a whole-host redirect rule cannot express.
        $this->assertSame('manual', $findings[2]['status']);
    }

    public function test_access_rules_convert_at_the_root_and_are_risks_in_folders(): void
    {
        $root = HtaccessResolver::analyze("Order Deny,Allow\nDeny from 1.2.3.4\nDeny from 10.0.0.0/8\n", '.htaccess', 'example.com');
        $this->assertSame('convertible', $root[0]['status']);
        $this->assertSame([['type' => 'ip', 'rule_type' => 'ban', 'ip' => '1.2.3.4']], $root[0]['actions']);
        $this->assertStringContainsString('10.0.0.0/8', $root[0]['detail']);

        $this->assertSame(['1 risk Private folder is publicly reachable'], $this->summary("Require all denied\n", 'storage/.htaccess'));
        $this->assertSame(['1 risk Password protection is not enforced'], $this->summary("AuthType Basic\nAuthUserFile /x/.htpasswd\nRequire valid-user\n", 'admin/.htaccess'));
    }

    public function test_file_blocks_are_judged_against_what_drust_already_refuses(): void
    {
        $this->assertSame([
            '1 native Sensitive files already blocked',
            '4 risk File block is not enforced',
            '7 risk PHP execution block is not enforced',
            '10 native Sensitive files already blocked',
        ], $this->summary(<<<'TXT'
            <FilesMatch "^\.ht">
            Require all denied
            </FilesMatch>
            <FilesMatch "\.(log|ini|sh)$">
            Require all denied
            </FilesMatch>
            <Files *.php>
            Deny from all
            </Files>
            <Files wp-config.php>
            Deny from all
            </Files>
            TXT, 'wp-content/uploads/.htaccess'));
    }

    public function test_php_settings_go_to_user_ini(): void
    {
        $findings = HtaccessResolver::analyze("php_value upload_max_filesize 64M\nphp_flag display_errors off\n", 'blog/.htaccess', 'example.com');

        $this->assertSame([
            ['type' => 'php_ini', 'directory' => 'blog', 'key' => 'upload_max_filesize', 'value' => '64M'],
            ['type' => 'php_ini', 'directory' => 'blog', 'key' => 'display_errors', 'value' => 'Off'],
        ], array_merge(...array_column($findings, 'actions')));
    }

    public function test_validation_reports_what_apache_would_reject(): void
    {
        $this->assertSame([], HtaccessResolver::validate("<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ index.php [L]\n</IfModule>\n"));

        $errors = HtaccessResolver::validate(<<<'TXT'
            <IfModule mod_rewrite.c>
            RewriteRule ^(abc index.php [L,Q]
            Options +Indexes FollowSymLinks
            RewriteRule ^ https://x.com [R=200]
            </Files>
            <Files secret.txt>
            TXT);

        $this->assertSame([
            [2, 'RewriteRule pattern "^(abc" is not a valid regular expression.'],
            [2, 'Unknown flag "Q".'],
            [3, 'Options must all start with + or -, or none of them may.'],
            [4, 'Redirect code "200" must be a 3xx status.'],
            [5, '</Files> closes <IfModule> from line 1.'],
            [6, '<Files> is never closed.'],
        ], array_map(fn (array $error): array => [$error['line'], $error['message']], $errors));
    }

    public function test_fixer_comments_out_broken_lines_and_closes_blocks(): void
    {
        $diagnosis = HtaccessFixer::diagnose("<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^(abc index.php [L]\nRewriteRule ^ index.php [L]\n", true, true, 'php');

        $this->assertSame('invalid', $diagnosis['status']);
        $this->assertSame(
            "<IfModule mod_rewrite.c>\nRewriteEngine On\n# [dPanel: RewriteRule pattern \"^(abc\" is not a valid regular expression.] RewriteRule ^(abc index.php [L]\nRewriteRule ^ index.php [L]\n</IfModule>\n",
            $diagnosis['suggestion'],
        );
        $this->assertSame([], HtaccessResolver::validate($diagnosis['suggestion']));
    }

    public function test_fixer_adds_the_rules_the_detected_project_needs(): void
    {
        // A Laravel public/.htaccess with only a redirect keeps it and gains the front controller.
        $public = HtaccessFixer::diagnose("Redirect 301 /old /new\n", true, true, 'laravel');
        $this->assertSame('incomplete', $public['status']);
        $this->assertStringStartsWith("Redirect 301 /old /new\n\n# Added by dPanel: stock Laravel rules\n", (string) $public['suggestion']);
        $this->assertSame('ok', HtaccessFixer::diagnose((string) $public['suggestion'], true, true, 'laravel')['status']);

        // The project root next to public/ needs the forwarder; a missing file gets the stock one.
        $root = HtaccessFixer::diagnose('', false, false, 'laravel');
        $this->assertSame('missing', $root['status']);
        $this->assertSame(HtaccessTemplates::find('laravel')['root'], $root['suggestion']);
        $this->assertSame('ok', HtaccessFixer::diagnose((string) $root['suggestion'], true, false, 'laravel')['status']);

        $this->assertSame(['status' => 'none', 'problems' => [], 'suggestion' => null], HtaccessFixer::diagnose('', false, true, 'php'));
    }

    public function test_every_template_is_valid(): void
    {
        foreach (HtaccessTemplates::all() as $template) {
            foreach (array_filter([$template['root'], $template['public']]) as $content) {
                $this->assertSame([], HtaccessResolver::validate($content), $template['key']);
            }
        }
    }

    public function test_php_handler_uses_cpanel_format_and_validates(): void
    {
        $handler = HtaccessTemplates::phpHandler('8.3');

        $this->assertStringContainsString('AddHandler application/x-httpd-ea-php83 .php .php8 .phtml', $handler);
        $this->assertSame([], HtaccessResolver::validate($handler));
        $this->assertSame('native', HtaccessResolver::analyze($handler, '.htaccess', 'example.com')[0]['status']);
    }
}
