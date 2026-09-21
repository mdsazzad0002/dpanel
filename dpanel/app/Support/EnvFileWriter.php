<?php

namespace App\Support;

class EnvFileWriter
{
    public function __construct(private readonly string $envPath = '')
    {
        if ($this->envPath === '') {
            $this->envPath = base_path('.env');
        }
    }

    /**
     * Returns new .env contents with only the given keys patched in place
     * (preserving every other line, comment, and blank line as-is), appending
     * any requested keys that don't already exist. Does not write anything.
     *
     * @param array<string, string> $keyValues
     */
    public function patch(array $keyValues): string
    {
        $contents = file_get_contents($this->envPath);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the .env file.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $remaining = $keyValues;

        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Za-z0-9_]+)=/', $line, $m) && array_key_exists($m[1], $remaining)) {
                $lines[$i] = $m[1].'='.$this->quoteIfNeeded($remaining[$m[1]]);
                unset($remaining[$m[1]]);
            }
        }

        foreach ($remaining as $key => $value) {
            $lines[] = $key.'='.$this->quoteIfNeeded($value);
        }

        return implode("\n", $lines)."\n";
    }

    public function writeAtomic(string $contents): void
    {
        $dir = dirname($this->envPath);
        $tmp = $dir.'/.env.tmp.'.bin2hex(random_bytes(6));

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary env file.');
        }

        chmod($tmp, 0640);

        if (! rename($tmp, $this->envPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to activate the new env file.');
        }
    }

    public function backup(string $backupDir): string
    {
        if (! is_dir($backupDir) && ! mkdir($backupDir, 0750, true) && ! is_dir($backupDir)) {
            throw new \RuntimeException('Unable to create the env backup directory.');
        }

        $path = rtrim($backupDir, '/').'/.env.'.now()->format('Ymd_His').'.bak';

        if (! copy($this->envPath, $path)) {
            throw new \RuntimeException('Unable to create an env backup before applying changes.');
        }

        chmod($path, 0640);

        return $path;
    }

    public function restore(string $backupPath): void
    {
        if (! is_readable($backupPath)) {
            throw new \RuntimeException('Backup file is missing; cannot roll back automatically.');
        }

        $contents = file_get_contents($backupPath);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the backup file.');
        }

        $this->writeAtomic($contents);
    }

    private function quoteIfNeeded(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'$]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
