<?php

namespace App\Services\Mail;

/**
 * Sorts Inbox mail into Gmail-style categories from its headers alone, so
 * no body fetch is needed: Social (network notifications), Promotions
 * (marketing / bulk campaigns), Updates (receipts, alerts, automated
 * notices) and Primary (everything else, i.e. mail from people).
 */
class MailCategoryClassifier
{
    public const PRIMARY = 'primary';

    public const PROMOTIONS = 'promotions';

    public const UPDATES = 'updates';

    public const SOCIAL = 'social';

    public const ALL = [self::PRIMARY, self::PROMOTIONS, self::UPDATES, self::SOCIAL];

    private const SOCIAL_DOMAINS = [
        'facebookmail.com', 'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
        'pinterest.com', 'tiktok.com', 'reddit.com', 'redditmail.com', 'quora.com', 'youtube.com',
        'discord.com', 'snapchat.com', 'threads.net', 'tumblr.com', 'medium.com', 'whatsapp.com',
    ];

    /** Headers only bulk-mail / marketing platforms add. */
    private const CAMPAIGN_HEADERS = [
        'x-mailchimp-campaign', 'x-mc-user', 'x-campaign', 'x-campaign-id', 'x-campaignid',
        'x-mailgun-tag', 'x-sendinblue-campaign', 'x-mailjet-campaign', 'x-marketing',
        'x-newsletter', 'x-klaviyo', 'x-hubspot', 'x-ems-campaign', 'x-cmail-campaign',
    ];

    private const PROMO_SUBJECT = '/\b(\d{1,2}\s?% off|sale|deals?|discount|coupon|promo(tion)?s?|offer|save \d|free shipping|limited time|exclusive|newsletter|webinar|black friday|cyber monday|flash|clearance|shop now|ছাড়|অফার)\b/iu';

    private const UPDATE_SUBJECT = '/\b(receipt|invoice|order|payment|paid|shipped|shipping|delivered|delivery|tracking|statement|bill|subscription|renewal|confirm(ation|ed)?|verify|verification|password|sign[- ]?in|login|security alert|alert|otp|code|account|reminder|booking|reservation|ticket|expir(es|ing|ed)|backup|report|notification)\b/iu';

    private const AUTOMATED_SENDER = '/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|notifications?|notify|alerts?|updates?|mailer-daemon|postmaster|billing|invoice|receipts?|orders?|accounts?|security|support|info|system|automated|bounce)[@+]/i';

    private const MARKETING_SENDER = '/^(news(letter)?|marketing|promo(tions?)?|offers?|deals?|sales|hello|team|digest)[@+]/i';

    public function __construct(private readonly MailMessageAnalyzer $analyzer = new MailMessageAnalyzer) {}

    public function classify(string $rawHeaders): string
    {
        $headers = [];
        foreach ($this->analyzer->parseHeaders($rawHeaders) as $entry) {
            $headers[$entry['key']] ??= $entry['value'];
        }
        $header = static fn (string $name): string => strtolower(trim((string) ($headers[$name] ?? '')));

        $from = $this->address($header('from'));
        $local = strstr($from, '@', true) ?: '';
        $domain = substr((string) strrchr($from, '@'), 1);
        $subject = (string) ($headers['subject'] ?? '');

        foreach (self::SOCIAL_DOMAINS as $social) {
            if ($domain === $social || str_ends_with($domain, '.'.$social)) {
                return self::SOCIAL;
            }
        }

        $isBulk = $header('list-unsubscribe') !== ''
            || $header('list-id') !== ''
            || in_array($header('precedence'), ['bulk', 'list', 'junk'], true);

        foreach (self::CAMPAIGN_HEADERS as $name) {
            if ($header($name) !== '') {
                return self::PROMOTIONS;
            }
        }

        $automated = preg_match(self::AUTOMATED_SENDER, $local.'@') === 1
            || ($header('auto-submitted') !== '' && $header('auto-submitted') !== 'no');
        $updateSubject = preg_match(self::UPDATE_SUBJECT, $subject) === 1;
        $promoSubject = preg_match(self::PROMO_SUBJECT, $subject) === 1;

        if ($promoSubject && ($isBulk || preg_match(self::MARKETING_SENDER, $local.'@') === 1)) {
            return self::PROMOTIONS;
        }
        if ($updateSubject && ($automated || $isBulk)) {
            return self::UPDATES;
        }
        if (preg_match(self::MARKETING_SENDER, $local.'@') === 1 && $isBulk) {
            return self::PROMOTIONS;
        }
        if ($automated) {
            return self::UPDATES;
        }
        if ($isBulk) {
            // Bulk mail with neither signal: mailing lists are usually campaigns.
            return $promoSubject || ! $updateSubject ? self::PROMOTIONS : self::UPDATES;
        }

        return self::PRIMARY;
    }

    private function address(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $match) === 1) {
            return strtolower(trim($match[1]));
        }

        return strtolower(trim($value));
    }
}
