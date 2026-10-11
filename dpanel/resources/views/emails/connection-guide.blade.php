{{-- Mail client settings sent from "Connect device"; the plain-text part is built alongside in EmailController. --}}
<div style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;">
        <tr>
            <td style="padding:24px 28px 8px;">
                <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#2563eb;">dPanel Mail</p>
                <h1 style="margin:0;font-size:20px;line-height:1.3;color:#0f172a;">Your email client configuration</h1>
                <p style="margin:8px 0 0;font-size:14px;color:#475569;">Use these settings to add <strong style="color:#0f172a;">{{ $email }}</strong> to Outlook, Apple Mail, Thunderbird or your phone.</p>
            </td>
        </tr>
        @foreach ($sections as $section)
            <tr>
                <td style="padding:16px 28px 0;">
                    <h2 style="margin:0 0 8px;font-size:15px;color:#0f172a;">{{ $section['title'] }}</h2>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0;border-radius:8px;border-collapse:separate;font-size:14px;">
                        @foreach ($section['rows'] as $label => $value)
                            <tr>
                                <td style="padding:9px 12px;width:42%;color:#64748b;{{ $loop->last ? '' : 'border-bottom:1px solid #f1f5f9;' }}">{{ $label }}</td>
                                <td style="padding:9px 12px;font-weight:600;color:#0f172a;word-break:break-all;{{ $loop->last ? '' : 'border-bottom:1px solid #f1f5f9;' }}">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="padding:20px 28px 0;">
                <p style="margin:0;padding:12px 14px;background:#eff6ff;border-radius:8px;font-size:13px;line-height:1.5;color:#1e3a8a;">{{ $passwordNote }}</p>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 28px 24px;">
                <p style="margin:0;font-size:12px;line-height:1.5;color:#64748b;">{{ $deliveryNote }}</p>
            </td>
        </tr>
    </table>
</div>
