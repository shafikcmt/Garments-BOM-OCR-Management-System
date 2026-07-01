@php
    $poNo = $alert['po_no'] ?? '—';
    $daysOverdue = $alert['days_overdue'] ?? null;
    $generatedAt = $alert['generated_at'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PI Missing Alert</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e9f2;">
                    <tr>
                        <td style="background:#b91c1c;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;">
                            ⚠ PI Missing Alert
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;font-size:15px;">
                                PI has <strong>not been received yet</strong> for the following PO. Please follow up.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;width:170px;">PO No.</td>
                                    <td style="padding:8px 0;font-weight:bold;">{{ $poNo }}</td>
                                </tr>
                                @if(!is_null($daysOverdue))
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Days Overdue</td>
                                    <td style="padding:8px 0;font-weight:bold;color:#b91c1c;">{{ $daysOverdue }} day(s)</td>
                                </tr>
                                @endif
                                @if($generatedAt)
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">PO Generated At</td>
                                    <td style="padding:8px 0;">{{ $generatedAt }}</td>
                                </tr>
                                @endif
                                @if(!empty($alert['buyer']))
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Buyer</td>
                                    <td style="padding:8px 0;">{{ $alert['buyer'] }}</td>
                                </tr>
                                @endif
                                @if(!empty($alert['vendor']))
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Vendor</td>
                                    <td style="padding:8px 0;">{{ $alert['vendor'] }}</td>
                                </tr>
                                @endif
                                @if(!empty($alert['style']))
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Style</td>
                                    <td style="padding:8px 0;">{{ $alert['style'] }}</td>
                                </tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#f9fafb;border-top:1px solid #eef1f6;font-size:12px;color:#9ca3af;">
                            This is an automated alert from Humana Apparels Operations Workspace.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
