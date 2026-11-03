<?php

namespace App\Services\Website;

/**
 * Compares a .htaccess file with what the detected project needs and builds
 * a corrected version: broken lines are commented out (Apache would answer
 * every request with a 500 otherwise) and the project's missing rules are
 * added from its stock template.
 */
class HtaccessFixer
{
    private const REPAIR_PASSES = 3;

    /**
     * @param  bool  $served  true for the folder drust serves; false for a project root next to public/
     * @param  string  $app  detected project type (wordpress, laravel, codeigniter, static, php, unknown)
     * @return array{status: string, problems: array<int, string>, suggestion: string|null} status is
     *                                                                                      ok, none (no file and none needed), missing, invalid or incomplete
     */
    public static function diagnose(string $content, bool $exists, bool $served, string $app): array
    {
        $template = HtaccessTemplates::find($app);
        $templateContent = $template ? ($served ? $template['public'] : $template['root']) : null;
        $label = $template['label'] ?? $app;

        if (trim($content) === '') {
            return $templateContent === null
                ? ['status' => 'none', 'problems' => [], 'suggestion' => null]
                : ['status' => 'missing', 'problems' => [($exists ? 'This .htaccess is empty' : 'There is no .htaccess here')."; a {$label} site on Apache needs one."], 'suggestion' => $templateContent];
        }

        $problems = [];
        $errors = HtaccessResolver::validate($content);
        $base = $content;
        if ($errors !== []) {
            $problems[] = count($errors).' syntax '.(count($errors) === 1 ? 'error' : 'errors').' — Apache answers every request with "500 Internal Server Error" until '.(count($errors) === 1 ? 'it is' : 'they are').' fixed.';
            $base = self::repair($content);
            if ($base === null) {
                $problems[] = 'The errors could not be fixed line by line, so the suggestion starts from the stock '.$label.' file.';
            }
        }

        $missing = self::missingRule($content, $served, $app);
        if ($missing !== null) {
            $problems[] = $missing;
        }

        $suggestion = $base ?? $templateContent;
        if ($base !== null && $missing !== null && $templateContent !== null) {
            $suggestion = rtrim($base)."\n\n# Added by dPanel: stock {$label} rules\n".$templateContent;
        }
        $changed = $errors !== [] || ($missing !== null && $templateContent !== null);

        return [
            'status' => $errors !== [] ? 'invalid' : ($missing !== null ? 'incomplete' : 'ok'),
            'problems' => $problems,
            'suggestion' => $changed ? $suggestion : null,
        ];
    }

    /** What the project needs from this file but cannot find, or null. */
    private static function missingRule(string $content, bool $served, string $app): ?string
    {
        $targets = HtaccessResolver::rewriteTargets($content);
        if ($served && isset(HtaccessTemplates::FRONT_CONTROLLERS[$app])) {
            $file = HtaccessTemplates::FRONT_CONTROLLERS[$app];
            $pattern = '#^/?'.preg_quote($file, '#').'($|/|\?)#i';
            if (! collect($targets)->contains(fn (string $target): bool => (bool) preg_match($pattern, $target))) {
                return "No rule sends pretty URLs to {$file}, so on Apache every page except the home page returns 404.";
            }
        }
        if (! $served && in_array($app, HtaccessTemplates::PUBLIC_FOLDER_APPS, true)) {
            if (! collect($targets)->contains(fn (string $target): bool => (bool) preg_match('#^/?public/#i', $target))) {
                return 'No rule forwards requests into public/, so on an Apache host pointed at the project folder visitors would see (and could download) the project files.';
            }
        }

        return null;
    }

    /**
     * The file with each invalid line commented out and unclosed blocks
     * closed, or null when that still does not validate.
     */
    public static function repair(string $content): ?string
    {
        for ($pass = 0; $pass < self::REPAIR_PASSES; $pass++) {
            $errors = HtaccessResolver::validate($content);
            if ($errors === []) {
                return $content;
            }
            if (collect($errors)->contains('line', 0)) {
                return null;
            }

            $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
            $closing = [];
            $handled = [];
            foreach ($errors as $error) {
                if (preg_match('/^<(\w+)> is never closed\.$/', $error['message'], $match)) {
                    $closing[] = "</{$match[1]}>";

                    continue;
                }
                $index = $error['line'] - 1;
                if (isset($handled[$index]) || ! isset($lines[$index])) {
                    continue;
                }
                $handled[$index] = true;
                // A directive continued with "\" spans several physical lines.
                do {
                    $continues = str_ends_with(rtrim($lines[$index]), '\\');
                    $lines[$index] = $index === $error['line'] - 1
                        ? "# [dPanel: {$error['message']}] {$lines[$index]}"
                        : "# {$lines[$index]}";
                    $index++;
                } while ($continues && isset($lines[$index]));
            }
            $content = rtrim(implode("\n", $lines))."\n".($closing === [] ? '' : implode("\n", array_reverse($closing))."\n");
        }

        return HtaccessResolver::validate($content) === [] ? $content : null;
    }
}
