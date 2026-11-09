<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ $heading }}</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 0;">
    <tr><td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e9f2;">
            <tr><td style="background:#1d4ed8;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;">{{ $heading }}</td></tr>
            <tr><td style="padding:24px;">
                <p style="margin:0 0 14px;font-size:15px;">{{ $intro }}</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                    <tr><th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e9f2;">Task</th><th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e9f2;">Due</th><th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e9f2;">Late</th></tr>
                    @foreach($lines as $l)
                        <tr>
                            <td style="padding:6px 8px;border-bottom:1px solid #f0f2f7;">{{ $l['title'] }}@if($l['who'])<br><span style="color:#6b7280;font-size:12px;">{{ $l['who'] }}</span>@endif</td>
                            <td style="padding:6px 8px;border-bottom:1px solid #f0f2f7;white-space:nowrap;">{{ $l['due'] }}</td>
                            <td style="padding:6px 8px;border-bottom:1px solid #f0f2f7;white-space:nowrap;">{{ $l['days_late'] > 0 ? $l['days_late'] . ' day(s)' : 'Today' }}</td>
                        </tr>
                    @endforeach
                </table>
                <p style="margin:18px 0 0;"><a href="{{ $url }}" style="background:#1d4ed8;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;">Open my follow-ups</a></p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
