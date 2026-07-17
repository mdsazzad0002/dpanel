<?php

namespace App\Http\Controllers\Website\WebsiteManage;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Website\WebsiteService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WebsitePreviewController  extends Controller{

    public function __construct(
        protected WebsiteService $websiteService,
    ) {
    }


    /**
     * Preview website files from dynamic base dir + normalized domain path.
     */
    public function preview(string $token, string $id, ?string $path = null): BinaryFileResponse|\Illuminate\Http\Response
    {
        $website = Website::query()
            ->visibleTo(request()->user())
            ->firstWhere('id', $id);
        if (! $website) {
            return response(
                "Preview not found\n".
                "Reason: website id does not exist\n".
                'Requested website id: '.$id."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $domain = $this->websiteService->normalizeDomain((string) ($website->domain ?? ''));
        if ($domain === '') {
            return response(
                "Preview not found\n".
                "Reason: website domain is empty\n".
                'Requested website id: '.$id."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $siteFolder = $this->websiteService->resolvePreviewRootPath($website);
        if ($siteFolder === '' || ! is_dir($siteFolder)) {
            return response(
                "Preview not found\n".
                "Reason: preview directory does not exist\n".
                'Requested website id: '.$id."\n".
                'Domain: '.$domain."\n".
                'Expected directory: '.$siteFolder."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $requestedRelative = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) ($path ?? '')), DIRECTORY_SEPARATOR);
        $requestedPath = $requestedRelative !== ''
            ? $siteFolder.DIRECTORY_SEPARATOR.$requestedRelative
            : $siteFolder;

        $file = $this->websiteService->resolvePreviewFile($requestedPath);
        if (! is_string($file)) {
            $missing = $requestedRelative !== '' ? str_replace(DIRECTORY_SEPARATOR, '/', $requestedRelative) : '/';
            $expectedPath = str_replace('\\', '/', $requestedPath);
            $isDirectoryRequest = $requestedRelative === '' || is_dir($requestedPath);

            $details = "Preview not found\n".
                "Reason: requested file is missing\n".
                'Requested URL path: '.$missing."\n".
                'Expected path: '.$expectedPath."\n";

            if ($isDirectoryRequest) {
                $details .= "Expected index files: index.html or index.php\n";
            }

            return response($details, 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if (! $this->websiteService->pathIsInside($file, $siteFolder)) {
            return response(
                "Preview not found\n".
                "Reason: requested file is outside the preview directory\n".
                'Requested website id: '.$id."\n".
                'Domain: '.$domain."\n".
                'Requested file: '.str_replace('\\', '/', $file)."\n".
                'Expected directory: '.str_replace('\\', '/', $siteFolder)."\n",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            ob_start();
            include $file;
            $content = ob_get_clean();

            return response($content, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response()->file($file);
    }
}
