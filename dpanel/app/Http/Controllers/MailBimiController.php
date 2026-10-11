<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\MailDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * BIMI for the domains this server sends for: the panel hosts each domain's
 * logo and the Mail DNS Guide shows the default._bimi record pointing at it,
 * so Gmail, Yahoo and Apple Mail can show the logo next to its mail.
 */
class MailBimiController extends Controller
{
    /** BIMI's recommended maximum logo size. */
    private const MAX_KB = 32;

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'logo' => ['required', 'file', 'max:'.self::MAX_KB],
        ]);
        $domain = strtolower(trim((string) $validated['domain']));
        abort_unless(Mailbox::query()->where('domain', $domain)->exists() || MailDomain::query()->where('domain', $domain)->exists(), 404);

        $svg = (string) file_get_contents($validated['logo']->getRealPath());
        if ($error = $this->svgProblem($svg)) {
            return back()->with('error', $error);
        }

        $path = 'bimi/'.$domain.'.svg';
        Storage::disk('local')->put($path, $svg);
        MailDomain::query()->updateOrCreate(['domain' => $domain], ['bimi_logo_path' => $path]);

        return back()->with('success', "BIMI logo saved for {$domain}. Add the BIMI record below, and set DMARC to quarantine or reject.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $domain = strtolower(trim((string) $request->input('domain', '')));
        $mailDomain = MailDomain::query()->where('domain', $domain)->first();
        if ($mailDomain?->bimi_logo_path) {
            Storage::disk('local')->delete($mailDomain->bimi_logo_path);
            $mailDomain->forceFill(['bimi_logo_path' => null])->save();
        }

        return back()->with('success', "BIMI logo removed for {$domain}. Delete its default._bimi record too.");
    }

    /**
     * Public: mail providers fetch the logo from here without a login.
     */
    public function show(string $domain): Response
    {
        $path = MailDomain::query()->where('domain', strtolower($domain))->value('bimi_logo_path');
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return response((string) Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * The checks a BIMI validator applies first: an SVG document with no
     * scripts, links or external references (SVG Tiny PS forbids them).
     */
    public function svgProblem(string $svg): ?string
    {
        if (! preg_match('/<svg\b/i', $svg)) {
            return 'The logo must be an SVG file.';
        }
        if (preg_match('/<script\b|<foreignObject\b|\son\w+\s*=|javascript:|<image\b|xlink:href\s*=\s*["\']https?:|<!ENTITY/i', $svg)) {
            return 'The SVG contains scripts, embedded images or external links, which BIMI does not allow. Export it as SVG Tiny PS.';
        }

        return null;
    }
}
