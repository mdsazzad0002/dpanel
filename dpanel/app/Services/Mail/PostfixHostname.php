<?php

namespace App\Services\Mail;

use App\Support\Platform\LocalCommand;
/**
 * The name Postfix announces on this machine. A class of its own so that tests
 * do not depend on whether the machine running them has Postfix.
 */
class PostfixHostname
{
    public function current(): string
    {
        $local = app(LocalCommand::class);

        return $local->supports(LocalCommand::POSTFIX) ? strtolower(trim((string) $local->output(['postconf', '-h', 'myhostname']))) : '';
    }
}
