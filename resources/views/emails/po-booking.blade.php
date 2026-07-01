@php
    $logoPath = public_path('images/humana-logo.png');
    $logoData = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Booking</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:28px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4eaf2;">
                    <!-- Header: logo + title on light band -->
                    <tr>
                        <td style="background:#ffffff;padding:26px 32px 18px;border-bottom:3px solid #0f3a5f;" align="center">
                            @if($logoData)
                                <img src="{{ $logoData }}" width="180" alt="Humana Apparels Pvt. Ltd." style="display:block;margin:0 auto 10px;width:180px;max-width:70%;height:auto;">
                            @else
                                <div style="font-size:22px;font-weight:bold;color:#0f3a5f;letter-spacing:.08em;margin-bottom:6px;">HUMANA APPARELS PVT. LTD.</div>
                            @endif
                            <div style="font-size:16px;font-weight:bold;color:#0f3a5f;letter-spacing:.02em;">PO Booking</div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:26px 32px;font-size:14px;line-height:1.65;color:#1f2937;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    <!-- Attachment note -->
                    <tr>
                        <td style="padding:0 32px 22px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fb;border:1px solid #e4eaf2;border-radius:8px;">
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#0f3a5f;">
                                        &#128206;&nbsp; The PO Booking document is attached as a PDF.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:16px 32px 20px;background:#f4f7fb;border-top:1px solid #e4eaf2;font-size:12px;line-height:1.6;color:#8a98a8;" align="center">
                            <span style="color:#0f3a5f;font-weight:bold;">Humana Apparels Pvt. Ltd.</span><br>
                            Momin Nagar, Gorai, Mirzapur, Tangail - 1941, Bangladesh
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
