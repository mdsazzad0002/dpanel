<?php

namespace App\Services\ServerPanel;

use App\Services\ServerPanel\Contracts\AiSuggestionProvider;

class HeuristicAiSuggestionProvider implements AiSuggestionProvider
{
    public function suggest(array $context): array
    {
        $signature = (string) ($context['error_signature'] ?? 'unknown');
        $command = trim((string) ($context['command'] ?? ''));

        return match ($signature) {
            'command_not_found' => [
                'problem_title' => 'Command not found',
                'problem_summary' => 'The executable does not exist in PATH or package is missing.',
                'detected_cause' => 'Missing package or wrong command name.',
                'suggested_fix' => 'Install the package or use full binary path.',
                'fix_commands' => ['which '.strtok($command, ' '), 'apt install -y <required-package>'],
                'risk_level' => 'approval_required',
                'tags' => ['missing-binary', 'package-install'],
            ],
            'disk_full' => [
                'problem_title' => 'Disk is full',
                'problem_summary' => 'No free space left on the target filesystem.',
                'detected_cause' => 'Storage usage reached limit.',
                'suggested_fix' => 'Inspect large files and clean caches/logs safely.',
                'fix_commands' => ['df -h', 'du -sh /var/log/* | sort -h'],
                'risk_level' => 'safe',
                'tags' => ['storage'],
            ],
            'permission_denied' => [
                'problem_title' => 'Permission denied',
                'problem_summary' => 'The current SSH user lacks permission for this action.',
                'detected_cause' => 'File/dir ownership or sudo requirement mismatch.',
                'suggested_fix' => 'Check ownership and run with least-privilege adjustments.',
                'fix_commands' => ['ls -la', 'whoami'],
                'risk_level' => 'safe',
                'tags' => ['permissions'],
            ],
            default => [
                'problem_title' => 'Command failed',
                'problem_summary' => 'The command returned an error and requires investigation.',
                'detected_cause' => 'See stderr output and server logs.',
                'suggested_fix' => 'Collect diagnostic output first, then apply a minimal change.',
                'fix_commands' => ['journalctl -n 100 --no-pager', 'tail -n 100 /var/log/syslog'],
                'risk_level' => 'safe',
                'tags' => ['diagnostics', 'generic'],
            ],
        };
    }
}
