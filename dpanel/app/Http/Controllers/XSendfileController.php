<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Guide to X-Sendfile: the gateway sends a file a PHP app names in an
 * X-Sendfile header, so nothing here needs installing or turning on.
 */
class XSendfileController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Php/XSendfile');
    }
}
