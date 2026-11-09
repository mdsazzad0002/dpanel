<?php

namespace App\Services\Platform;

/** drust answered 501: this server cannot limit CPU and memory (no systemd or cgroup v2, or not Linux). */
class UnsupportedLimit extends \RuntimeException {}
