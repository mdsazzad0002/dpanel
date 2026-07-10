<?php

/**
 * ServerPanel Roundcube SSO plugin placeholder.
 *
 * The panel performs the actual authentication handoff. This plugin is shipped
 * so the Roundcube deployment can keep a consistent plugin directory layout.
 */
class serverpanel_sso extends rcube_plugin
{
    public $task = 'login';

    public function init(): void
    {
        $this->add_hook('startup', [$this, 'startup']);
    }

    public function startup(array $args): array
    {
        return $args;
    }
}
