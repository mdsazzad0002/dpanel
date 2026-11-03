<?php

namespace App\Services\Website;

/**
 * Reads an Apache .htaccess file and says what each rule means on drust,
 * which never reads .htaccess itself.
 *
 * Every finding gets one status:
 *  - native:      drust already does this on its own (front controller,
 *                 compression, browser caching, hidden-file blocking...).
 *  - convertible: maps onto a panel setting (redirect rule, IP rule,
 *                 .user.ini); the finding carries the actions to apply.
 *  - manual:      has no panel equivalent and is silently ignored.
 *  - risk:        meant to keep something private, but drust serves it.
 */
class HtaccessResolver
{
    public const STATUSES = ['native', 'convertible', 'manual', 'risk'];

    public const MAX_BYTES = 128 * 1024;

    private const RULE_FLAGS = [
        'b', 'bctls', 'bne', 'c', 'chain', 'co', 'cookie', 'dpi', 'discardpath', 'e', 'env', 'end', 'f', 'forbidden',
        'g', 'gone', 'h', 'handler', 'l', 'last', 'n', 'next', 'nc', 'nocase', 'ne', 'noescape', 'ns', 'nosubreq',
        'p', 'proxy', 'pt', 'passthrough', 'qsa', 'qsappend', 'qsd', 'qsdiscard', 'qsl', 'qslast', 'r', 'redirect',
        's', 'skip', 't', 'type', 'unsafeallow3f', 'unsafeprefixstat',
    ];

    private const COND_FLAGS = ['nc', 'nocase', 'or', 'ornext', 'nv', 'novary'];

    /** Extensions drust refuses to serve (is_blocked_htaccess_path in drust). */
    private const GATEWAY_BLOCKED_EXTENSIONS = ['sh', 'bash', 'env', 'ini', 'conf', 'sql', 'sqlite', 'yml', 'yaml', 'json', 'md'];

    /** Blocks whose body is read as if it were at the top level. */
    private const TRANSPARENT_BLOCKS = ['ifmodule', 'ifdefine', 'ifversion', 'requireall', 'requireany'];

    private const ACCESS_DIRECTIVES = ['order', 'deny', 'allow', 'require', 'satisfy'];

    private const AUTH_DIRECTIVES = ['authtype', 'authname', 'authuserfile', 'authgroupfile', 'authbasicprovider'];

    private const NATIVE_DIRECTIVES = [
        'rewriteengine' => 'drust routes requests itself; there is no rewrite engine to switch on.',
        'rewritebase' => 'drust routes requests itself; there is no rewrite engine to configure.',
        'rewriteoptions' => 'drust routes requests itself; there is no rewrite engine to configure.',
        'expiresactive' => 'drust sets browser cache headers for static files itself.',
        'expiresbytype' => 'drust sets browser cache headers for static files itself.',
        'expiresdefault' => 'drust sets browser cache headers for static files itself.',
        'fileetag' => 'drust sends its own ETag and Last-Modified validators.',
        'addoutputfilterbytype' => 'drust compresses responses (gzip/brotli) on its own.',
        'addoutputfilter' => 'drust compresses responses (gzip/brotli) on its own.',
        'setoutputfilter' => 'drust compresses responses (gzip/brotli) on its own.',
        'deflatecompressionlevel' => 'drust compresses responses (gzip/brotli) on its own.',
        'browsermatch' => 'drust compresses responses (gzip/brotli) on its own.',
        'addtype' => 'drust picks content types from file extensions itself.',
        'addencoding' => 'drust picks content types from file extensions itself.',
        'addcharset' => 'drust picks content types from file extensions itself.',
        'adddefaultcharset' => 'PHP sets the charset of its own responses.',
        'serversignature' => 'drust does not print a server signature.',
    ];

    private string $file = '';

    private string $directory = '';

    private string $apex = '';

    /** @var array<int, array<string, mixed>> */
    private array $findings = [];

    /**
     * @param  string  $file  path relative to the document root, e.g. "wp-content/uploads/.htaccess"
     * @param  string  $domain  the website's own domain, to tell its redirects from other domains
     * @return array<int, array{file: string, line: int, source: string, status: string, title: string, detail: string, actions: array<int, array<string, mixed>>}>
     */
    public static function analyze(string $content, string $file, string $domain): array
    {
        $resolver = new self;
        $resolver->file = ltrim(str_replace('\\', '/', $file), '/');
        $directory = dirname($resolver->file);
        $resolver->directory = $directory === '.' ? '' : $directory;
        $resolver->apex = (string) preg_replace('/^www\./', '', strtolower(rtrim(trim($domain), '.')));
        $resolver->run(self::lines($content));

        usort($resolver->findings, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $resolver->findings;
    }

    /**
     * The target of every RewriteRule, e.g. "index.php" or "public/$1".
     *
     * @return array<int, string>
     */
    public static function rewriteTargets(string $content): array
    {
        $targets = [];
        foreach (self::lines($content) as ['text' => $text]) {
            [$directive, $arguments] = (new self)->split($text);
            if (strtolower($directive) === 'rewriterule' && isset($arguments[1])) {
                $targets[] = $arguments[1];
            }
        }

        return $targets;
    }

    /**
     * Mistakes that make Apache answer every request with a 500, so the file
     * is never written (or copied to an Apache host) with them in place.
     *
     * @return array<int, array{line: int, message: string}>
     */
    public static function validate(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            return [['line' => 0, 'message' => 'The file is larger than '.(self::MAX_BYTES / 1024).' KB.']];
        }
        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            return [['line' => 0, 'message' => 'The file must be plain UTF-8 text.']];
        }

        $errors = [];
        $error = function (int $line, string $message) use (&$errors): void {
            $errors[] = ['line' => $line, 'message' => $message];
        };
        $open = [];
        foreach (self::lines($content) as ['line' => $line, 'text' => $text]) {
            if (str_starts_with($text, '<')) {
                if (preg_match('/^<\/\s*([a-z]+)\s*>$/i', $text, $close)) {
                    $top = array_pop($open);
                    if ($top === null) {
                        $error($line, "</{$close[1]}> closes a block that was never opened.");
                    } elseif (strcasecmp($top['name'], $close[1]) !== 0) {
                        $error($line, "</{$close[1]}> closes <{$top['name']}> from line {$top['line']}.");
                    }
                } elseif (preg_match('/^<\s*([a-z]+)(\s+[^>]*)?>$/i', $text, $tag)) {
                    if (! in_array(strtolower($tag[1]), ['else'], true) && trim($tag[2] ?? '') === '') {
                        $error($line, "<{$tag[1]}> needs an argument.");
                    }
                    $open[] = ['name' => $tag[1], 'line' => $line];
                } else {
                    $error($line, 'This block tag is not closed with ">".');
                }

                continue;
            }

            [$directive, $arguments] = (new self)->split($text);
            $key = strtolower($directive);
            if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $directive)) {
                $error($line, "\"{$directive}\" is not a directive name.");

                continue;
            }
            switch ($key) {
                case 'rewriterule':
                    if (count($arguments) < 2 || count($arguments) > 3) {
                        $error($line, 'RewriteRule needs a pattern, a target and optional [flags].');
                        break;
                    }
                    self::checkRegex($line, $arguments[0], 'RewriteRule pattern', $error);
                    if (isset($arguments[2])) {
                        self::checkFlags($line, $arguments[2], self::RULE_FLAGS, $arguments[1], $error);
                    }
                    break;
                case 'rewritecond':
                    if (count($arguments) < 2 || count($arguments) > 3) {
                        $error($line, 'RewriteCond needs a test string, a condition and optional [flags].');
                        break;
                    }
                    if (! preg_match('/^!?(-[dfslFUxhHLe]|-eq|-ne|-lt|-le|-gt|-ge|-ipmatch|[<>=]=?|=)/', $arguments[1])) {
                        self::checkRegex($line, $arguments[1], 'RewriteCond pattern', $error);
                    }
                    if (isset($arguments[2])) {
                        self::checkFlags($line, $arguments[2], self::COND_FLAGS, '', $error);
                    }
                    break;
                case 'rewriteengine':
                    if (count($arguments) !== 1 || ! in_array(strtolower($arguments[0]), ['on', 'off'], true)) {
                        $error($line, 'RewriteEngine takes On or Off.');
                    }
                    break;
                case 'options':
                    $signed = collect($arguments)->filter(fn (string $option): bool => in_array($option[0] ?? '', ['+', '-'], true))->count();
                    if ($arguments === []) {
                        $error($line, 'Options needs at least one option.');
                    } elseif ($signed !== 0 && $signed !== count($arguments)) {
                        $error($line, 'Options must all start with + or -, or none of them may.');
                    }
                    break;
                case 'order':
                    if (! in_array(strtolower(str_replace(' ', '', implode('', $arguments))), ['deny,allow', 'allow,deny', 'mutual-failure'], true)) {
                        $error($line, 'Order takes "Deny,Allow", "Allow,Deny" or "Mutual-failure".');
                    }
                    break;
                case 'deny':
                case 'allow':
                    if (strtolower($arguments[0] ?? '') !== 'from' || count($arguments) < 2) {
                        $error($line, ucfirst($key).' needs "from" and a host, IP or "all".');
                    }
                    break;
                case 'require':
                    if ($arguments === []) {
                        $error($line, 'Require needs an argument, e.g. "all granted".');
                    }
                    break;
                case 'errordocument':
                    if (! preg_match('/^[45]\d\d$/', $arguments[0] ?? '') || count($arguments) < 2) {
                        $error($line, 'ErrorDocument needs a 4xx/5xx status code and a page or message.');
                    }
                    break;
                case 'php_value':
                case 'php_admin_value':
                case 'php_flag':
                case 'php_admin_flag':
                    if (count($arguments) < 2) {
                        $error($line, "{$directive} needs a setting name and a value.");
                    } elseif (str_ends_with($key, '_flag') && ! in_array(strtolower($arguments[1]), ['on', 'off', '1', '0', 'true', 'false', 'yes', 'no'], true)) {
                        $error($line, "{$directive} takes On or Off.");
                    }
                    break;
                case 'redirect':
                case 'redirectmatch':
                case 'redirectpermanent':
                case 'redirecttemp':
                    if (count($arguments) < 2) {
                        $error($line, "{$directive} needs a source path and a target URL.");
                    } elseif ($key === 'redirectmatch') {
                        $pattern = preg_match('/^(permanent|temp|seeother|gone|\d{3})$/i', $arguments[0]) ? ($arguments[1] ?? '') : $arguments[0];
                        self::checkRegex($line, $pattern, 'RedirectMatch pattern', $error);
                    }
                    break;
                case 'header':
                    if ($arguments === []) {
                        $error($line, 'Header needs an action, e.g. "set X-Frame-Options SAMEORIGIN".');
                    }
                    break;
            }
        }
        foreach ($open as $block) {
            $error($block['line'], "<{$block['name']}> is never closed.");
        }

        return $errors;
    }

    /** @param callable(int, string): void $error */
    private static function checkRegex(int $line, string $pattern, string $what, callable $error): void
    {
        $pattern = ltrim($pattern, '!');
        // Apache's back-references (%1, $1) and variables are not regex syntax errors.
        $probe = preg_replace('/%\{[^}]*\}/', 'x', $pattern);
        set_error_handler(fn (): bool => true);
        try {
            $valid = preg_match('#'.str_replace('#', '\#', (string) $probe).'#', '') !== false;
        } finally {
            restore_error_handler();
        }
        if (! $valid) {
            $error($line, "{$what} \"{$pattern}\" is not a valid regular expression.");
        }
    }

    /**
     * @param  array<int, string>  $known
     * @param  callable(int, string): void  $error
     */
    private static function checkFlags(int $line, string $flags, array $known, string $substitution, callable $error): void
    {
        if (! preg_match('/^\[[^\]]+\]$/', $flags)) {
            $error($line, "Flags \"{$flags}\" must be written as [FLAG,FLAG].");

            return;
        }
        foreach (explode(',', trim($flags, '[]')) as $flag) {
            [$name, $value] = array_pad(explode('=', trim($flag), 2), 2, null);
            if (! in_array(strtolower((string) $name), $known, true)) {
                $error($line, "Unknown flag \"{$name}\".");
            } elseif (in_array(strtolower((string) $name), ['r', 'redirect'], true) && $value !== null && $substitution !== '-'
                && ! preg_match('/^(3\d\d|permanent|temp|seeother)$/i', $value)) {
                $error($line, "Redirect code \"{$value}\" must be a 3xx status.");
            }
        }
    }

    /**
     * Logical lines (continuations joined, comments and blanks dropped).
     *
     * @return array<int, array{line: int, text: string}>
     */
    private static function lines(string $content): array
    {
        $lines = [];
        $pending = null;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $raw) {
            $text = trim($raw);
            if ($pending !== null) {
                $pending['text'] .= ' '.$text;
            } elseif ($text === '' || str_starts_with($text, '#')) {
                continue;
            } else {
                $pending = ['line' => $index + 1, 'text' => $text];
            }
            if (str_ends_with($pending['text'], '\\')) {
                $pending['text'] = rtrim(substr($pending['text'], 0, -1));

                continue;
            }
            $lines[] = $pending;
            $pending = null;
        }
        if ($pending !== null) {
            $lines[] = $pending;
        }

        return $lines;
    }

    /** @param array<int, array{line: int, text: string}> $lines */
    private function run(array $lines): void
    {
        $conditions = [];
        $access = [];
        $auth = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            ['line' => $line, 'text' => $text] = $lines[$i];

            if (preg_match('/^<\/\s*([a-z]+)/i', $text)) {
                // Closing a transparent block; a stray close is harmless.
                continue;
            }
            if (preg_match('/^<\s*([a-z]+)\s*([^>]*)>$/i', $text, $open)) {
                $name = strtolower($open[1]);
                $argument = trim($open[2], " \t\"'");
                $isLiteSpeed = $name === 'ifmodule' && stripos($argument, 'litespeed') !== false;
                if (in_array($name, self::TRANSPARENT_BLOCKS, true) && ! $isLiteSpeed) {
                    continue;
                }
                [$body, $i] = $this->collectBlock($lines, $i, $name);
                $this->block($name, $argument, $line, $text, $body, $isLiteSpeed);

                continue;
            }

            [$directive, $arguments] = $this->split($text);
            $key = strtolower($directive);

            if ($key === 'rewritecond') {
                $conditions[] = ['line' => $line, 'text' => $text, 'args' => $arguments];

                continue;
            }
            if ($key === 'rewriterule') {
                $this->rewrite($conditions, $line, $text, $arguments);
                $conditions = [];

                continue;
            }
            if (in_array($key, self::ACCESS_DIRECTIVES, true)) {
                $isAuthRequire = $key === 'require' && preg_match('/^(valid-user|user|group)$/i', (string) ($arguments[0] ?? ''));
                if ($isAuthRequire) {
                    $auth[] = ['line' => $line, 'text' => $text];
                } else {
                    $access[] = ['line' => $line, 'text' => $text, 'key' => $key, 'args' => $arguments];
                }

                continue;
            }
            if (in_array($key, self::AUTH_DIRECTIVES, true)) {
                $auth[] = ['line' => $line, 'text' => $text];

                continue;
            }
            $this->directive($key, $line, $text, $arguments);
        }

        foreach ($conditions as $condition) {
            $this->add($condition['line'], $condition['text'], 'native', 'Unused RewriteCond', 'It is not followed by a RewriteRule, so Apache ignored it too.');
        }
        if ($access !== []) {
            $this->access($access);
        }
        if ($auth !== []) {
            $this->add(
                $auth[0]['line'],
                implode("\n", array_column($auth, 'text')),
                'risk',
                'Password protection is not enforced',
                $this->where().' is meant to ask for a username and password, but drust never reads .htaccess, so it is open to everyone. Move it outside the document root, or add a login in the application.',
            );
        }
    }

    /**
     * Lines up to the matching close tag of $name, and the index of that tag.
     *
     * @param  array<int, array{line: int, text: string}>  $lines
     * @return array{0: array<int, array{line: int, text: string}>, 1: int}
     */
    private function collectBlock(array $lines, int $start, string $name): array
    {
        $depth = 0;
        $body = [];
        for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
            $text = $lines[$i]['text'];
            if (preg_match('/^<\s*'.preg_quote($name, '/').'\b/i', $text)) {
                $depth++;
            } elseif (preg_match('/^<\/\s*'.preg_quote($name, '/').'\s*>/i', $text)) {
                if ($depth === 0) {
                    return [$body, $i];
                }
                $depth--;
            }
            $body[] = $lines[$i];
        }

        return [$body, $count - 1];
    }

    /** @param array<int, array{line: int, text: string}> $body */
    private function block(string $name, string $argument, int $line, string $openTag, array $body, bool $isLiteSpeed): void
    {
        $closeName = $isLiteSpeed ? 'IfModule' : ucfirst($name);
        $source = implode("\n", [$openTag, ...array_map(fn (array $row): string => '    '.$row['text'], $body), "</{$closeName}>"]);

        if ($isLiteSpeed) {
            $this->add($line, $source, 'native', 'LiteSpeed-only settings', 'Only LiteSpeed reads this block. drust has its own edge cache (Website → Edge Cache).');

            return;
        }

        if (in_array($name, ['files', 'filesmatch'], true)) {
            $this->filesBlock($name, $argument, $line, $source, $body);

            return;
        }

        $label = match ($name) {
            'limit', 'limitexcept' => 'Per-method rules are not applied',
            'if', 'elseif', 'else' => 'Conditional block is not applied',
            default => "<{$closeName}> block is not applied",
        };
        $denies = $this->bodyDenies($body);
        $this->add(
            $line,
            $source,
            $denies ? 'risk' : 'manual',
            $label,
            $denies
                ? 'This block restricts access, but drust never reads .htaccess, so the restriction is gone.'
                : 'drust has no equivalent for this block, so its contents are ignored.',
        );
    }

    /** @param array<int, array{line: int, text: string}> $body */
    private function filesBlock(string $name, string $argument, int $line, string $source, array $body): void
    {
        if (! $this->bodyDenies($body)) {
            $nativeOnly = $body !== [] && collect($body)->every(function (array $row): bool {
                [$directive] = $this->split($row['text']);

                return array_key_exists(strtolower($directive), self::NATIVE_DIRECTIVES);
            });
            $this->add(
                $line,
                $source,
                $nativeOnly ? 'native' : 'manual',
                $nativeOnly ? 'Per-file caching settings' : 'Per-file settings are not applied',
                $nativeOnly
                    ? 'drust sets browser cache headers for static files itself.'
                    : 'drust has no per-file settings, so the directives in this block are ignored.',
            );

            return;
        }

        $targets = $this->fileTargets($name, $argument);
        if ($targets === null) {
            $this->add($line, $source, 'risk', 'File block is not enforced', "Files matching \"{$argument}\" are meant to be blocked, but drust never reads .htaccess. Check that none of them are reachable from the web.");

            return;
        }

        $exposed = array_values(array_filter($targets, fn (string $target): bool => ! $this->gatewayProtects($target)));
        if ($exposed === []) {
            $this->add($line, $source, 'native', 'Sensitive files already blocked', 'drust already refuses hidden files and config-type files ('.implode(', ', $targets).'), and runs PHP files instead of serving their source.');

            return;
        }

        $phpExposed = collect($exposed)->contains(fn (string $target): bool => str_ends_with($target, '.php'));
        $this->add(
            $line,
            $source,
            'risk',
            $phpExposed ? 'PHP execution block is not enforced' : 'File block is not enforced',
            $phpExposed
                ? $this->where().' is meant to stop PHP files ('.implode(', ', $exposed).') from running, but drust runs them. Remove any PHP files that should not be there, and keep uploads free of scripts.'
                : 'Files matching '.implode(', ', $exposed).' in '.$this->where().' are meant to be blocked, but drust serves them. Move them outside the document root.',
        );
    }

    /**
     * What a <Files>/<FilesMatch> argument covers: exact names ("wp-config.php",
     * ".htaccess") and extensions ("*.log"), or null when the pattern is too
     * clever to read.
     *
     * @return array<int, string>|null
     */
    private function fileTargets(string $name, string $argument): ?array
    {
        $argument = trim($argument);
        if ($name === 'files') {
            if (str_starts_with($argument, '~')) {
                return $this->fileTargets('filesmatch', trim(substr($argument, 1), " \t\"'"));
            }
            if (preg_match('/^\*\.([a-z0-9]+)$/i', $argument, $match)) {
                return ['*.'.strtolower($match[1])];
            }

            return $argument !== '' && ! preg_match('/[*?\[]/', $argument) ? [strtolower($argument)] : null;
        }

        $anchored = str_starts_with($argument, '^');
        $pattern = rtrim(ltrim($argument, '^'), '$');
        if ($anchored && preg_match('/^\\\\\.\(?([a-z0-9_|-]+)\)?/i', $pattern, $match)) {
            // ^\.(htaccess|env) or ^\.ht: names that start with a dot.
            return array_map(fn (string $part): string => '.'.strtolower($part), explode('|', $match[1]));
        }
        if (! preg_match('/\\\\\.\(([a-z0-9|]+)\)$/i', $pattern, $match) && ! preg_match('/\\\\\.([a-z0-9]+)$/i', $pattern, $match)) {
            return null;
        }
        $extensions = array_map('strtolower', explode('|', $match[1]));
        $prefix = substr($pattern, 0, -strlen($match[0]));
        if (in_array($prefix, ['', '.*', '.+'], true) && ! $anchored || in_array($prefix, ['.*', '.+'], true)) {
            return array_map(fn (string $extension): string => "*.{$extension}", $extensions);
        }
        // A specific name such as ^wp-config\.php$ or (xmlrpc|wp-trackback)\.php.
        if (! preg_match('/^\(?([a-z0-9_|-]+)\)?$/i', $prefix, $names)) {
            return null;
        }

        return collect(explode('|', $names[1]))->crossJoin($extensions)
            ->map(fn (array $pair): string => strtolower($pair[0].'.'.$pair[1]))
            ->all();
    }

    private function gatewayProtects(string $target): bool
    {
        if (str_starts_with($target, '*.')) {
            $extension = substr($target, 2);

            return in_array($extension, self::GATEWAY_BLOCKED_EXTENSIONS, true) || in_array($extension, ['ht', 'htaccess', 'htpasswd'], true);
        }
        // Running wp-config.php and friends prints nothing, so drust's default
        // (execute, never serve the source) is as safe as blocking them.
        if (in_array($target, ['wp-config.php', 'config.php', 'configuration.php', 'settings.php'], true)) {
            return true;
        }
        // drust refuses every path segment that starts with a dot.
        if (str_starts_with($target, '.')) {
            return true;
        }

        return in_array(strtolower((string) pathinfo($target, PATHINFO_EXTENSION)), self::GATEWAY_BLOCKED_EXTENSIONS, true);
    }

    /** @param array<int, array{line: int, text: string}> $body */
    private function bodyDenies(array $body): bool
    {
        foreach ($body as $row) {
            if (preg_match('/^(deny\s+from\s+all|require\s+all\s+denied|require\s+(valid-user|user|group)\b)/i', $row['text'])
                || preg_match('/^rewriterule\s+.*\[[^\]]*\b(F|G)\b[^\]]*\]$/i', $row['text'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{line: int, text: string, args: array<int, string>}> $conditions */
    private function rewrite(array $conditions, int $line, string $text, array $arguments): void
    {
        $source = implode("\n", [...array_column($conditions, 'text'), $text]);
        $startLine = $conditions[0]['line'] ?? $line;
        [$pattern, $substitution] = [(string) ($arguments[0] ?? ''), (string) ($arguments[1] ?? '')];
        $flags = $this->flags((string) ($arguments[2] ?? ''));
        $conds = array_map(fn (array $condition): array => [
            'test' => strtoupper((string) ($condition['args'][0] ?? '')),
            'pattern' => (string) ($condition['args'][1] ?? ''),
            'flags' => $this->flags((string) ($condition['args'][2] ?? '')),
        ], $conditions);

        $isAbsolute = (bool) preg_match('#^https?://#i', $substitution);
        if (array_key_exists('R', $flags) || $isAbsolute) {
            $this->redirect($startLine, $source, $pattern, $substitution, $flags, $conds);

            return;
        }

        if ($substitution === '-') {
            if (array_key_exists('F', $flags) || array_key_exists('G', $flags)) {
                $blocksHidden = (bool) preg_match('/^\^?(\(\.\*\/\)\?|\.\*\/)?\\\\\./', $pattern) || preg_match('/(^|\/|\(|\|)\\\\\.(git|env|ht|svn)/i', $pattern);
                $this->add(
                    $startLine,
                    $source,
                    $blocksHidden ? 'native' : 'risk',
                    $blocksHidden ? 'Hidden files already blocked' : 'Blocking rule is not enforced',
                    $blocksHidden
                        ? 'drust refuses every path segment that starts with a dot.'
                        : 'This rule answers matching requests with 403/410, but drust never reads .htaccess, so they are served. Block them in the application, or remove the files they protect.',
                );

                return;
            }
            $environment = collect(array_keys($flags))->first(fn (string $flag): bool => str_starts_with($flag, 'E='));
            if ($environment !== null) {
                // E=HTTP_X:%{HTTP:X} copies a request header, which drust
                // already hands to PHP as HTTP_* for every header.
                $copiesHeader = (bool) preg_match('/^E=HTTP_[A-Z0-9_]+:%\{HTTP:[^}]+\}$/i', (string) $environment);
                $this->add(
                    $startLine,
                    $source,
                    $copiesHeader ? 'native' : 'manual',
                    $copiesHeader ? 'Request header pass-through' : 'Environment variable is not set',
                    $copiesHeader
                        ? 'drust passes every request header (Authorization, X-XSRF-Token...) to PHP as HTTP_* on its own.'
                        : 'drust does not set custom environment variables per request. Set it in the application or its .env file instead.',
                );

                return;
            }
            $this->add($startLine, $source, 'native', 'Stop rule for existing files', 'drust serves files that exist on disk before anything else.');

            return;
        }

        if (preg_match('#^/?index\.php(/\$\d|/%\{REQUEST_URI\})?$#i', $substitution) || preg_match('#^/?index\.php/?$#i', $substitution)) {
            if ($this->directory === '') {
                $this->add($startLine, $source, 'native', 'Front controller (pretty URLs)', 'drust sends every request that does not match a file to index.php, the same as this rule.');
            } else {
                $this->add($startLine, $source, 'manual', 'Front controller in a subfolder', "drust sends missing URLs to the document root's index.php, not to {$this->directory}/index.php. Run the app in {$this->directory} as its own website (or alias) so its pretty URLs keep working.");
            }

            return;
        }
        if (preg_match('#^/?index\.php\?#i', $substitution)) {
            $this->add($startLine, $source, 'manual', 'Front controller with a query parameter', 'drust sends missing URLs to index.php with the original path in REQUEST_URI, not as "'.$substitution.'". Most apps read REQUEST_URI when this is missing; if yours does not, turn on its path-based routing (e.g. "clean URLs").');

            return;
        }

        $this->add($startLine, $source, 'manual', 'Custom rewrite is not applied', 'drust serves files that exist and sends everything else to index.php. This rewrite to "'.$substitution.'" has to move into the application\'s routes.');
    }

    /**
     * @param  array<string, string>  $flags
     * @param  array<int, array{test: string, pattern: string, flags: array<string, string>}>  $conds
     */
    private function redirect(int $line, string $source, string $pattern, string $substitution, array $flags, array $conds): void
    {
        $status = (int) ($flags['R'] ?? 0) ?: 302;
        $status = in_array($status, [301, 302, 307, 308], true) ? $status : 301;
        $httpsCond = collect($conds)->contains(fn (array $c): bool => $this->isPlainHttpCondition($c));
        $wwwCond = collect($conds)->contains(fn (array $c): bool => $c['test'] === '%{HTTP_HOST}' && preg_match('/^\^?www\\\\?\./i', $c['pattern']));
        $catchAll = in_array($pattern, ['^', '.', '.*', '^.*', '^.*$', '(.*)', '(.*)$', '^(.*)', '^(.*)$', '^/?(.*)$', '^/(.*)$', '^/?(.*)'], true);

        $trailingSlash = preg_match('#^\^?\(\.[*+]\)/\$$#', $pattern)
            || collect($conds)->contains(fn (array $c): bool => $c['test'] === '%{REQUEST_URI}' && $c['pattern'] === '(.+)/$');
        if ($trailingSlash) {
            $this->add($line, $source, 'manual', 'Trailing-slash redirect', 'drust does not strip trailing slashes. Laravel and most routers treat /page/ and /page the same, so links keep working; only this 301 to the slash-less URL is lost.');

            return;
        }

        if (! preg_match('#^(https?)://((?:www\.)?(?:%\{[a-z_]+\}|%\d|[^/%]+))(.*)$#i', $substitution, $match)) {
            $this->add($line, $source, 'manual', 'Path redirect is not supported', 'drust redirect rules work on whole hostnames, not single paths. Add this redirect in the application (a route or a redirect plugin).');

            return;
        }
        [, $scheme, $host, $path] = $match;
        $scheme = strtolower($scheme);
        $host = strtolower($host);
        $keepsPath = (bool) preg_match('#^(/?\$1|/?%\{REQUEST_URI\}|%\{REQUEST_URI\})$#i', $path);

        $hostKind = match (true) {
            in_array($host, ['%{http_host}', '%{server_name}'], true) => 'same',
            in_array($host, ['www.%{http_host}', 'www.%{server_name}', 'www.%1'], true), $host === "www.{$this->apex}" => 'www',
            $host === '%1' && $wwwCond, $host === $this->apex => 'apex',
            (bool) preg_match('/^[a-z0-9.-]+$/', $host) => 'other',
            default => 'unknown',
        };

        if (! $catchAll || ! $keepsPath) {
            $this->add($line, $source, 'manual', 'Path redirect is not supported', 'drust redirect rules move whole hostnames and keep the path. This rule only redirects some URLs (or drops the path), so add it in the application (a route or a redirect plugin).');

            return;
        }

        $actions = [];
        $titles = [];
        if ($scheme === 'https' && $httpsCond && in_array($hostKind, ['same', 'apex', 'www'], true)) {
            $actions[] = $this->redirectAction('http_to_https', null, $status);
            $titles[] = 'HTTP → HTTPS';
        }
        if ($hostKind === 'apex' && ($wwwCond || ! $httpsCond)) {
            $actions[] = $this->redirectAction('www_to_root', null, $status);
            $titles[] = 'www → root domain';
        }
        if ($hostKind === 'other') {
            $actions[] = $this->redirectAction('to_domain', $host, $status);
            $titles[] = "Redirect to {$host}";
        }

        if ($hostKind === 'www') {
            $this->add($line, $source, 'manual', 'Root → www redirect is not supported', 'drust only redirects www to the root domain. To keep www as the main address, make www.'.$this->apex.' the website\'s domain and redirect the root domain to it with a "Redirect to domain" rule.', $actions);

            return;
        }
        if ($actions === []) {
            $this->add($line, $source, 'manual', 'Redirect is not supported', 'This redirect does not match any drust redirect rule (HTTP → HTTPS, www → root, or to another domain). Add it in the application instead.');

            return;
        }

        $this->add($line, $source, 'convertible', implode(' + ', $titles), 'drust can do this with its own redirect '.(count($actions) > 1 ? 'rules' : 'rule').' (Rules → Redirect Rules), answered before PHP runs.', $actions);
    }

    /** @param array{test: string, pattern: string, flags: array<string, string>} $condition */
    private function isPlainHttpCondition(array $condition): bool
    {
        $pattern = strtolower($condition['pattern']);

        return match ($condition['test']) {
            '%{HTTPS}', '%{ENV:HTTPS}' => in_array($pattern, ['off', '!on', '!=on', '^off$', '!^on$'], true),
            '%{SERVER_PORT}' => in_array($pattern, ['80', '^80$', '!443', '!^443$', '!=443'], true),
            '%{HTTP:X-FORWARDED-PROTO}', '%{REQUEST_SCHEME}' => in_array($pattern, ['!https', '^http$', 'http', '!^https$', '!=https'], true),
            default => false,
        };
    }

    /** @param array<int, string> $arguments */
    private function directive(string $key, int $line, string $text, array $arguments): void
    {
        if (array_key_exists($key, self::NATIVE_DIRECTIVES)) {
            $this->add($line, $text, 'native', ucfirst($this->directiveName($text)), self::NATIVE_DIRECTIVES[$key]);

            return;
        }

        switch ($key) {
            case 'options':
                $indexes = (bool) preg_match('/(^|\s)\+?Indexes\b/', implode(' ', $arguments)) && ! preg_match('/-Indexes\b/', implode(' ', $arguments));
                $this->add(
                    $line,
                    $text,
                    $indexes ? 'manual' : 'native',
                    $indexes ? 'Directory listing is not available' : 'Options',
                    $indexes ? 'drust never lists folder contents. Add an index file if visitors need a file list.' : 'drust never lists folder contents, follows symlinks inside the site, and has no MultiViews.',
                );

                return;
            case 'directoryindex':
                $standard = collect($arguments)->contains(fn (string $file): bool => in_array(strtolower(ltrim($file, '/')), ['index.php', 'index.html', 'index.htm'], true));
                $this->add(
                    $line,
                    $text,
                    $standard ? 'native' : 'manual',
                    'DirectoryIndex',
                    $standard ? 'drust already serves index.php, then index.html, for folder URLs.' : 'drust only looks for index.php and index.html as folder index files. Rename the file or link to it directly.',
                );

                return;
            case 'php_value':
            case 'php_flag':
            case 'php_admin_value':
            case 'php_admin_flag':
                $this->phpSetting($key, $line, $text, $arguments);

                return;
            case 'redirect':
            case 'redirectpermanent':
            case 'redirecttemp':
            case 'redirectmatch':
                $this->redirectDirective($key, $line, $text, $arguments);

                return;
            case 'addhandler':
            case 'sethandler':
            case 'forcetype':
                if (preg_match('/php(\d)(\d)/i', implode(' ', $arguments), $version)) {
                    $this->add($line, $text, 'native', 'PHP version handler', "Selects PHP {$version[1]}.{$version[2]} on Apache/cPanel hosts. drust takes the PHP version from the website's runtime settings instead (Edit runtime on this website's page).");

                    return;
                }
                $this->add($line, $text, 'manual', 'Handler is not applied', 'drust runs only .php files through PHP and serves everything else as a static file.');

                return;
            case 'header':
            case 'requestheader':
                $this->add($line, $text, 'manual', 'Header is not applied', 'drust does not add custom headers from .htaccess. Send this header from the application (e.g. a middleware or header() call).');

                return;
            case 'errordocument':
                $this->add($line, $text, 'manual', 'Custom error page is not applied', 'drust shows its own error pages for missing static files. PHP apps can still render their own 404 page.');

                return;
            case 'setenv':
            case 'setenvif':
            case 'setenvifnocase':
                $passesAuthorization = stripos($text, 'HTTP_AUTHORIZATION') !== false;
                $this->add(
                    $line,
                    $text,
                    $passesAuthorization ? 'native' : 'manual',
                    $passesAuthorization ? 'Authorization header pass-through' : 'Environment variable is not set',
                    $passesAuthorization
                        ? 'drust passes the Authorization header to PHP as HTTP_AUTHORIZATION on its own.'
                        : 'drust does not set environment variables from .htaccess. Put the value in the application\'s .env file or config instead.',
                );

                return;
            default:
                $this->add($line, $text, 'manual', ucfirst($this->directiveName($text)).' is not applied', 'drust does not read .htaccess and has no equivalent for this directive.');
        }
    }

    /** @param array<int, string> $arguments */
    private function phpSetting(string $key, int $line, string $text, array $arguments): void
    {
        $name = strtolower((string) ($arguments[0] ?? ''));
        $value = trim(implode(' ', array_slice($arguments, 1)), " \t\"'");
        if (! preg_match('/^[a-z0-9_.]+$/', $name) || $value === '' || preg_match('/[\r\n"]/', $value)) {
            $this->add($line, $text, 'manual', 'PHP setting', 'This line could not be read. Set the value in a .user.ini file by hand.');

            return;
        }
        if (str_ends_with($key, '_flag')) {
            $value = in_array(strtolower($value), ['on', '1', 'true', 'yes'], true) ? 'On' : 'Off';
        }

        $this->add(
            $line,
            $text,
            'convertible',
            "PHP setting {$name}",
            'Apache passes this to PHP; with PHP-FPM it belongs in a .user.ini file in '.$this->where().'. PHP re-reads .user.ini within about 5 minutes.',
            [['type' => 'php_ini', 'directory' => $this->directory, 'key' => $name, 'value' => $value]],
        );
    }

    /** @param array<int, string> $arguments */
    private function redirectDirective(string $key, int $line, string $text, array $arguments): void
    {
        $status = match ($key) {
            'redirectpermanent' => 301,
            'redirecttemp' => 302,
            default => 302,
        };
        if (in_array($key, ['redirect', 'redirectmatch'], true) && isset($arguments[0]) && ! str_starts_with($arguments[0], '/') && ! str_starts_with($arguments[0], '^')) {
            $code = strtolower(array_shift($arguments));
            $status = match ($code) {
                'permanent' => 301,
                'temp' => 302,
                'seeother' => 303,
                default => is_numeric($code) ? (int) $code : 302,
            };
        }
        [$from, $to] = [(string) ($arguments[0] ?? ''), (string) ($arguments[1] ?? '')];

        $wholeSite = $key === 'redirectmatch'
            ? (bool) preg_match('#^\^?/?\(\.\*\)\$?$#', $from) && preg_match('#^https?://[^/]+/?\$1$#i', $to)
            : $from === '/' && preg_match('#^https?://[^/]+/?$#i', $to);
        $host = strtolower((string) parse_url($to, PHP_URL_HOST));
        if ($wholeSite && $host !== '' && $host !== $this->apex && $host !== "www.{$this->apex}" && in_array($status, [301, 302, 307, 308], true) && $this->directory === '') {
            $this->add(
                $line,
                $text,
                'convertible',
                "Redirect to {$host}",
                'drust can send the whole site to this domain with a "Redirect to domain" rule, keeping each path.',
                [$this->redirectAction('to_domain', $host, $status)],
            );

            return;
        }

        $this->add($line, $text, 'manual', 'Path redirect is not supported', 'drust redirect rules work on whole hostnames, not single paths. Add this redirect in the application (a route or a redirect plugin).');
    }

    /** @param array<int, array{line: int, text: string, key: string, args: array<int, string>}> $access */
    private function access(array $access): void
    {
        $source = implode("\n", array_column($access, 'text'));
        $line = $access[0]['line'];
        $denyAll = false;
        $allowIps = [];
        $denyIps = [];

        foreach ($access as $row) {
            $words = array_map('strtolower', $row['args']);
            if (($row['key'] === 'deny' && ($words[1] ?? '') === 'all') || ($row['key'] === 'require' && $words === ['all', 'denied'])) {
                $denyAll = true;
            } elseif ($row['key'] === 'deny' && ($words[0] ?? '') === 'from') {
                array_push($denyIps, ...array_slice($row['args'], 1));
            } elseif ($row['key'] === 'allow' && ($words[0] ?? '') === 'from' && ($words[1] ?? '') !== 'all') {
                array_push($allowIps, ...array_slice($row['args'], 1));
            } elseif ($row['key'] === 'require' && ($words[0] ?? '') === 'not' && ($words[1] ?? '') === 'ip') {
                array_push($denyIps, ...array_slice($row['args'], 2));
            } elseif ($row['key'] === 'require' && ($words[0] ?? '') === 'ip') {
                array_push($allowIps, ...array_slice($row['args'], 1));
            }
        }

        if (! $denyAll && $allowIps === [] && $denyIps === []) {
            $this->add($line, $source, 'native', 'Open access', 'Everyone may visit, which is what drust does anyway.');

            return;
        }

        if ($denyAll && $allowIps === []) {
            $this->add(
                $line,
                $source,
                'risk',
                $this->directory === '' ? 'Site-wide block is not enforced' : 'Private folder is publicly reachable',
                $this->directory === ''
                    ? 'This file denies every visitor, but drust never reads .htaccess, so the whole site is public. If the site really should be closed, add an IP whitelist instead.'
                    : "{$this->directory}/ is meant to be closed to visitors, but drust serves its files. Move the folder outside the document root (or into a hidden .folder) so it cannot be downloaded.",
            );

            return;
        }

        if ($this->directory !== '') {
            $this->add($line, $source, 'risk', 'Folder IP restriction is not enforced', "Only some IPs may open {$this->directory}/ under Apache, but drust's IP rules work for the whole website, not one folder, so the folder is open to everyone. Move it outside the document root, or protect it in the application.");

            return;
        }

        $actions = [];
        $skipped = [];
        foreach ([...array_map(fn (string $ip): array => ['allow', $ip], $allowIps), ...array_map(fn (string $ip): array => ['ban', $ip], $denyIps)] as [$type, $ip]) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $actions[] = ['type' => 'ip', 'rule_type' => $type, 'ip' => $ip];
            } else {
                $skipped[] = $ip;
            }
        }
        $detail = $allowIps !== []
            ? 'drust can limit the whole website to these IPs with its IP whitelist.'
            : 'drust can block these IPs for the whole website with its IP ban list.';
        if ($skipped !== []) {
            $detail .= ' Ranges and partial addresses ('.implode(', ', $skipped).') are not supported by IP rules; add each address on its own.';
        }

        $this->add($line, $source, $actions === [] ? 'manual' : 'convertible', $allowIps !== [] ? 'IP whitelist' : 'IP ban list', $detail, $actions);
    }

    /** @return array{type: string, kind: string, target: string|null, status_code: int} */
    private function redirectAction(string $kind, ?string $target, int $status): array
    {
        return ['type' => 'redirect', 'kind' => $kind, 'target' => $target, 'status_code' => $status === 303 ? 302 : $status];
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function add(int $line, string $source, string $status, string $title, string $detail, array $actions = []): void
    {
        $this->findings[] = [
            'file' => $this->file,
            'line' => $line,
            'source' => $source,
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            'actions' => $actions,
        ];
    }

    private function where(): string
    {
        return $this->directory === '' ? 'the website root' : "{$this->directory}/";
    }

    private function directiveName(string $text): string
    {
        return (string) strtok($text, " \t");
    }

    /**
     * Directive name and its arguments, honouring double quotes.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function split(string $text): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $text, $matches, PREG_SET_ORDER);
        $words = array_map(fn (array $match): string => ($match[2] ?? '') !== '' ? $match[2] : stripslashes($match[1]), $matches);

        return [(string) array_shift($words), $words];
    }

    /** @return array<string, string> flag name (upper case) => value */
    private function flags(string $flags): array
    {
        $parsed = [];
        foreach (explode(',', trim($flags, '[] ')) as $flag) {
            $flag = trim($flag);
            if ($flag === '') {
                continue;
            }
            if (preg_match('/^E=/i', $flag)) {
                $parsed['E='.substr($flag, 2)] = '';

                continue;
            }
            [$name, $value] = array_pad(explode('=', $flag, 2), 2, '');
            $name = strtoupper($name);
            $name = match ($name) {
                'REDIRECT' => 'R',
                'FORBIDDEN' => 'F',
                'GONE' => 'G',
                default => $name,
            };
            $parsed[$name] = $value;
        }

        return $parsed;
    }
}
