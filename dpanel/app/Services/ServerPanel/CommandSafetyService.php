<?php

namespace App\Services\ServerPanel;

class CommandSafetyService
{
    /**
     * @return array{normalized_command:string,risk_level:string,risk_reason:string}
     */
    public function classify(string $command): array
    {
        $normalized = $this->normalize($command);

        if ($normalized === '') {
            return [
                'normalized_command' => '',
                'risk_level' => 'blocked',
                'risk_reason' => 'Empty command is not allowed.',
            ];
        }

        if ($this->isBlocked($normalized)) {
            return [
                'normalized_command' => $normalized,
                'risk_level' => 'blocked',
                'risk_reason' => 'Command matches a blocked dangerous pattern.',
            ];
        }

        if ($this->needsApproval($normalized)) {
            return [
                'normalized_command' => $normalized,
                'risk_level' => 'approval_required',
                'risk_reason' => 'Command can mutate system state and requires admin approval.',
            ];
        }

        if ($this->isSafe($normalized)) {
            return [
                'normalized_command' => $normalized,
                'risk_level' => 'safe',
                'risk_reason' => 'Command matches a read-only safe profile.',
            ];
        }

        return [
            'normalized_command' => $normalized,
            'risk_level' => 'approval_required',
            'risk_reason' => 'Command is unknown and requires explicit approval.',
        ];
    }

    public function normalize(string $command): string
    {
        $normalized = mb_strtolower(trim($command));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    private function isBlocked(string $normalized): bool
    {
        if (
            preg_match('/\becho\b.+\>\s*\/etc\/passwd/', $normalized) === 1 ||
            preg_match('/\b(curl|wget)\b.+\|\s*bash\b/', $normalized) === 1
        ) {
            return true;
        }

        foreach ((array) config('serverpanel.blocked_commands', []) as $pattern) {
            if ($pattern !== '' && str_contains($normalized, mb_strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    private function needsApproval(string $normalized): bool
    {
        foreach ((array) config('serverpanel.approval_required_patterns', []) as $pattern) {
            if ($pattern !== '' && str_contains($normalized, mb_strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    private function isSafe(string $normalized): bool
    {
        foreach ((array) config('serverpanel.safe_patterns', []) as $pattern) {
            if ($pattern !== '' && str_starts_with($normalized, mb_strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }
}
