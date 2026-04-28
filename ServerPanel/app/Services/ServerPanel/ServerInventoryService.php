<?php

namespace App\Services\ServerPanel;

use App\Models\Server;

class ServerInventoryService
{
    public function __construct(private readonly SshClientService $sshClient)
    {
    }

    public function scan(Server $server): Server
    {
        $ssh = $this->sshClient->connect($server);

        $whoami = $this->sshClient->runCommand($ssh, 'whoami')['output'];
        $hostnamectl = $this->sshClient->runCommand($ssh, 'hostnamectl')['output'];
        $kernel = $this->sshClient->runCommand($ssh, 'uname -r')['output'];
        $architecture = $this->sshClient->runCommand($ssh, 'uname -m')['output'];
        $cpu = $this->sshClient->runCommand($ssh, 'nproc')['output'];
        $ram = $this->sshClient->runCommand($ssh, 'free -m | awk \"/Mem:/ {print \\$2}\"')['output'];
        $disk = $this->sshClient->runCommand($ssh, 'df -BG / | awk \"NR==2 {gsub(\"G\",\"\",\\$2); print \\$2}\"')['output'];

        $osName = null;
        $osVersion = null;

        foreach (preg_split('/\r\n|\r|\n/', $hostnamectl) ?: [] as $line) {
            $line = trim((string) $line);
            if (str_starts_with($line, 'Operating System:')) {
                $os = trim((string) str_replace('Operating System:', '', $line));
                $osName = $os;
                $osVersion = $os;
            }
        }

        $server->forceFill([
            'os_name' => $osName,
            'os_version' => $osVersion,
            'kernel' => trim((string) $kernel),
            'architecture' => trim((string) $architecture),
            'cpu_cores' => (int) trim((string) $cpu),
            'ram_total_mb' => (int) trim((string) $ram),
            'disk_total_gb' => (int) trim((string) $disk),
            'last_scan_at' => now(),
            'status' => 'online',
            'error_message' => null,
        ])->save();

        return $server;
    }
}
