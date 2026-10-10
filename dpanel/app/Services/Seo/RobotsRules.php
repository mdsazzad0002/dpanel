<?php

namespace App\Services\Seo;

/**
 * robots.txt rules as Google, Bing and Yandex apply them (RFC 9309): a
 * crawler obeys the group with the most specific matching user-agent, else
 * the "*" group; within it the longest matching Allow/Disallow path wins,
 * and Allow wins a tie. "*" and a trailing "$" are supported in paths.
 */
class RobotsRules
{
    /** @param array<int, array{agents: array<int, string>, rules: array<int, array{allow: bool, path: string}>}> $groups */
    private function __construct(private readonly array $groups) {}

    public static function parse(string $text): self
    {
        $groups = [];
        $current = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                // Consecutive user-agent lines share one group of rules.
                if ($current === null || $groups[$current]['rules'] !== []) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
            } elseif (in_array($field, ['allow', 'disallow'], true) && $current !== null) {
                $groups[$current]['rules'][] = ['allow' => $field === 'allow', 'path' => $value];
            }
        }

        return new self($groups);
    }

    /**
     * Whether a crawler (by its product token, e.g. "googlebot") may fetch
     * a path (with query), and the rule that decided it.
     *
     * @return array{allowed: bool, rule: string|null, agent: string|null}
     */
    public function check(string $token, string $path): array
    {
        $token = strtolower($token);
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.$path;
        }

        // The most specific group: the longest user-agent that the token starts with.
        $best = '';
        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent !== '*' && str_starts_with($token, $agent) && strlen($agent) > strlen($best)) {
                    $best = $agent;
                }
            }
        }
        $agent = $best !== '' ? $best : '*';
        $rules = [];
        foreach ($this->groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                array_push($rules, ...$group['rules']);
            }
        }
        if ($rules === [] && $best === '') {
            return ['allowed' => true, 'rule' => null, 'agent' => null];
        }

        $match = null;
        foreach ($rules as $rule) {
            // An empty Disallow allows everything; it never matches.
            if ($rule['path'] === '' || ! $this->matches($rule['path'], $path)) {
                continue;
            }
            $length = strlen($rule['path']);
            if ($match === null || $length > strlen($match['path']) || ($length === strlen($match['path']) && $rule['allow'])) {
                $match = $rule;
            }
        }
        if ($match === null) {
            return ['allowed' => true, 'rule' => null, 'agent' => $agent];
        }

        return ['allowed' => $match['allow'], 'rule' => ($match['allow'] ? 'Allow: ' : 'Disallow: ').$match['path'], 'agent' => $agent];
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote($anchored ? substr($pattern, 0, -1) : $pattern, '#'));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1
            || preg_match('#^'.$regex.($anchored ? '$' : '').'#', rawurldecode($path)) === 1;
    }
}
