<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Services\Mail\BimiLogoConverter;
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
    /**
     * Room for an ordinary logo export. What is stored is always the
     * converted SVG, which BimiLogoConverter keeps under BIMI's 32 KB.
     */
    private const MAX_KB = 4096;

    public function __construct(private readonly BimiLogoConverter $converter)
    {
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'logo' => ['required', 'file', 'max:'.self::MAX_KB],
        ]);
        $domain = strtolower(trim((string) $validated['domain']));
        abort_unless(Mailbox::query()->where('domain', $domain)->exists() || MailDomain::query()->where('domain', $domain)->exists(), 404);

        // Any image is accepted and traced into SVG Tiny PS here, so nobody
        // has to produce that format by hand.
        try {
            $logo = $this->converter->convert((string) file_get_contents($validated['logo']->getRealPath()), $domain);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        if ($error = $this->svgProblem($logo['svg'])) {
            return back()->with('error', $error);
        }

        $path = 'bimi/'.$domain.'.svg';
        Storage::disk('local')->put($path, $logo['svg']);
        MailDomain::query()->updateOrCreate(['domain' => $domain], ['bimi_logo_path' => $path]);

        $message = $logo['converted']
            ? "Logo converted to SVG and saved for {$domain} (".ceil(strlen($logo['svg']) / 1024)." KB)."
            : "BIMI logo saved for {$domain}.";

        return back()->with('success', trim($message.' Add the BIMI record below, and set DMARC to quarantine or reject. '.implode(' ', $logo['notes'])));
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
        return $this->converter->problem($svg);
    }
}
