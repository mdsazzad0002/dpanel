<?php

namespace App\Jobs;

use RuntimeException;

/** Thrown inside RunPanelTransferJob when an admin cancelled the transfer mid-run. */
class PanelTransferCancelled extends RuntimeException {}
