<?php

namespace App\Services\Mail;

/**
 * The name Postfix announces on this machine. A class of its own so that tests
 * do not depend on whether the machine running them has Postfix.
 */
class PostfixHostname
{
    public function current(): string
    {
        return strtolower(trim((string) @shell_exec('postconf -h myhostname 2>/dev/null')));
    }
}
