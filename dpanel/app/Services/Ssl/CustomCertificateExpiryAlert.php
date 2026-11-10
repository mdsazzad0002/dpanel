<?php

namespace App\Services\Ssl;

use App\Models\SslCertificate;
use App\Models\Website;
use App\Services\NotificationService;
use Carbon\CarbonInterface;

/**
 * Nothing renews a certificate the customer uploaded, so its owner hears
 * about it before visitors do: 30, 7 and 1 days ahead, and once it has ended.
 */
class CustomCertificateExpiryAlert
{
    /** Days before the end at which a warning goes out; 0 is "it has ended". */
    public const THRESHOLDS = [30, 7, 1, 0];

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return int how many notifications were sent */
    public function run(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $sent = 0;
        SslCertificate::query()
            ->where('type', 'custom')
            ->whereNotNull('expires_at')
            ->orderBy('id')
            ->each(function (SslCertificate $certificate) use ($now, &$sent): void {
                $level = self::level($certificate->expires_at, $now);
                $last = $certificate->expiry_alert_days;
                if ($level === null || ($last !== null && $level >= (int) $last)) {
                    return;
                }
                $website = Website::query()->where('domain', $certificate->domain)->first();
                if ($website === null || ! $website->enable_ssl) {
                    return;
                }
                $this->notify($certificate, $website, $level, $now);
                $certificate->forceFill(['expiry_alert_days' => $level])->save();
                $sent++;
            });

        return $sent;
    }

    /** The tightest threshold the certificate is inside, or null while it has more than 30 days. */
    public static function level(CarbonInterface $expiresAt, CarbonInterface $now): ?int
    {
        if ($expiresAt->lessThanOrEqualTo($now)) {
            return 0;
        }
        $days = $now->diffInSeconds($expiresAt) / 86400;
        foreach ([1, 7, 30] as $threshold) {
            if ($days <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    private function notify(SslCertificate $certificate, Website $website, int $level, CarbonInterface $now): void
    {
        $ended = $level === 0;
        $date = $certificate->expires_at->toDateString();
        $days = (int) ceil($now->diffInSeconds($certificate->expires_at) / 86400);
        $this->notifications->create(
            type: 'ssl_expiry',
            status: $ended ? 'failed' : 'warning',
            title: $ended
                ? "SSL certificate for {$certificate->domain} has expired"
                : "SSL certificate for {$certificate->domain} expires in {$days} ".($days === 1 ? 'day' : 'days'),
            message: $ended
                ? "The uploaded certificate ended on {$date}. Visitors now see a browser warning. Upload a new certificate, or reinstall SSL to get a free one."
                : "The uploaded certificate ends on {$date} and is not renewed automatically. Upload a new one before then.",
            assignedUserId: $website->assigned_user_id,
            assignedResellerId: $website->assigned_reseller_id,
            subject: $website,
            data: ['domain' => $certificate->domain, 'level' => $level, 'expires_at' => $certificate->expires_at->toIso8601String()],
        );
    }
}
