<?php
namespace App\Services;

class ScriptPathResolver
{

   public static function resolveScriptPath(string $group, string $need): array|string
    {
        switch ($group) {
            case 'php':
                $scriptName = 'repository/modules/php/php.json';
                break;

            case 'sync-vhost':
                $scriptName = 'sync-vhost.sh';
                break;

            case 'issue-ssl':
                $scriptName = 'issue-ssl.sh';
                break;

            default:
                throw new \RuntimeException("Unknown script group: {$group}");
        }

        $scriptPath = base_path('../discript/' . $scriptName);

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Script not found: {$scriptName}");
        }

        $content = file_get_contents($scriptPath);

        $json = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $need ? ($json[$need] ?? '') : $json;
        }

        return $scriptPath;
    }
}