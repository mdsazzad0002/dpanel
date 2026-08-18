<?php

namespace App\Http\Controllers;

use App\Services\Mail\MailDeliveryDiagnosticsService;
use Inertia\Inertia;
use Inertia\Response;

class MailHealthController extends Controller
{
    public function index(MailDeliveryDiagnosticsService $diagnostics): Response
    {
        return Inertia::render('Email/Health/Index', [
            'mailHealth' => $diagnostics->snapshot(),
        ]);
    }
}
