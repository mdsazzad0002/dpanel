<?php

namespace App\Http\Controllers\Website\WebsiteManage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;


class AlisWebsiteController extends Controller
{
    public function create(Request $request): RedirectResponse
    {
        return redirect()
            ->route('websites.list', ['token' => $request->route('token')])
            ->with('info', 'Open a website and choose Alias API to manage its aliases.');
    }
}
