<?php

namespace App\Services\Security;

/**
 * An upload that the antivirus check refused, or could not check while
 * upload scanning is switched on. The message is safe to show the user.
 */
class UploadRejectedException extends \RuntimeException {}
