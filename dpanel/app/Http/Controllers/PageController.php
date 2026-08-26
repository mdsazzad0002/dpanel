<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function privacyPolicy(): Response
    {
        return Inertia::render('Legal/PrivacyPolicy', [
            'contactEmail' => config('mail.from.address'),
        ]);
    }

    public function termsAndConditions(): Response
    {
        return Inertia::render('Legal/TermsAndConditions', [
            'contactEmail' => config('mail.from.address'),
        ]);
    }
}
