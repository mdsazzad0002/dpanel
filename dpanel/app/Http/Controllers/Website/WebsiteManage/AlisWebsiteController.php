<?php

namespace App\Http\Controllers\Website\WebsiteManage;
use App\Http\Controllers\Controller;
use App\Services\PathService;
use App\Services\Php\PhpService;
use Inertia\Inertia;
use Inertia\Response;


class AlisWebsiteController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Websites/Create', [
            'serverBaseDir' => PathService::websiteBaseDirectory(),
            'phpVersions' => PhpService::getPhpVersions(),
            'aliasMode' => true,
        ]);
    }
}
