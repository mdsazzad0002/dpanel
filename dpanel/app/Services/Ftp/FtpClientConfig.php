<?php

namespace App\Services\Ftp;

use App\Models\FtpAccount;
use App\Models\Website;

/**
 * Ready-made client settings for one FTP account. Passwords are never
 * stored in dPanel, so none of these files contain one.
 */
class FtpClientConfig
{
    /**
     * A FileZilla Site Manager entry (File > Import). FileZilla asks for the
     * password on connect.
     *
     * @param  array{host: string, port: int, tls: bool}  $connection
     */
    public function filezilla(FtpAccount $account, Website $website, array $connection): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><FileZilla3/>');
        $server = $xml->addChild('Servers')->addChild('Server');
        $server->addChild('Host', htmlspecialchars($connection['host'], ENT_XML1));
        $server->addChild('Port', (string) $connection['port']);
        // 4 = FTP with required explicit TLS, 0 = plain FTP.
        $server->addChild('Protocol', $connection['tls'] ? '4' : '0');
        $server->addChild('Type', '0');
        $server->addChild('User', htmlspecialchars((string) $account->username, ENT_XML1));
        // 2 = ask for password.
        $server->addChild('Logontype', '2');
        $server->addChild('PasvMode', 'MODE_PASSIVE');
        $server->addChild('EncodingType', 'Auto');
        $server->addChild('BypassProxy', '0');
        $server->addChild('Name', htmlspecialchars("{$website->domain} ({$account->username})", ENT_XML1));

        return (string) $xml->asXML();
    }

    /**
     * .vscode/sftp.json for the VS Code "SFTP" extension (Natizyskunk.sftp),
     * which also speaks FTP over TLS. The account is jailed to its directory,
     * so "/" on the server is the account's folder.
     *
     * @param  array{host: string, port: int, tls: bool}  $connection
     */
    public function vscode(FtpAccount $account, Website $website, array $connection, bool $selfSigned): string
    {
        $config = [
            'name' => "{$website->domain} ({$account->username})",
            'host' => $connection['host'],
            'protocol' => 'ftp',
            'port' => $connection['port'],
            'username' => (string) $account->username,
            'password' => '',
            'remotePath' => '/',
            'uploadOnSave' => false,
            'ignore' => ['.vscode', '.git', '.DS_Store', 'node_modules'],
        ];
        if ($connection['tls']) {
            // true = explicit TLS (AUTH TLS) on the normal port.
            $config['secure'] = true;
            if ($selfSigned) {
                $config['secureOptions'] = ['rejectUnauthorized' => false];
            }
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
