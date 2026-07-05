@php
    $requestNo = $data['request_no'] ?? '—';
    $statusLabel = $data['status_label'] ?? 'Updated';
    $isRejected = ($data['status'] ?? '') === 'rejected';
    $decisions = $data['decisions'] ?? [];
    $reviewUrl = $data['review_url'] ?? null;
    $headerBg = $isRejected ? '#b91c1c' : '#15803d';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PRA {{ $statusLabel }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e9f2;">
                    <tr>
                        <td style="background:{{ $headerBg }};color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;">
                            PRA {{ $statusLabel }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;font-size:15px;">
                                Your Payment Request Approval <strong>{{ $requestNo }}</strong> has been
                                <strong style="color:{{ $headerBg }};">{{ strtolower($statusLabel) }}</strong>.
                            </p>

                            @if(!empty($decisions))
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-top:8px;">
                                <tr>
                                    <td style="padding:8px 10px;background:#f3f6fb;color:#6b7280;font-weight:bold;border:1px solid #e5e9f2;">Approver</td>
                                    <td style="padding:8px 10px;background:#f3f6fb;color:#6b7280;font-weight:bold;border:1px solid #e5e9f2;">Decision</td>
                                    <td style="padding:8px 10px;background:#f3f6fb;color:#6b7280;font-weight:bold;border:1px solid #e5e9f2;">Comment</td>
                                </tr>
                                @foreach($decisions as $decision)
                                <tr>
                                    <td style="padding:8px 10px;border:1px solid #e5e9f2;">{{ $decision['name'] ?? '—' }}</td>
                                    <td style="padding:8px 10px;border:1px solid #e5e9f2;font-weight:bold;">{{ ucfirst($decision['status'] ?? 'pending') }}</td>
                                    <td style="padding:8px 10px;border:1px solid #e5e9f2;">{{ $decision['comment'] ?? '' }}</td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            @if($reviewUrl)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 4px;">
                                <tr>
                                    <td style="border-radius:8px;background:#000b6f;">
                                        <a href="{{ $reviewUrl }}" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">
                                            View PRA
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#f9fafb;border-top:1px solid #eef1f6;font-size:12px;color:#9ca3af;">
                            This is an automated notification from Humana Apparels Operations Workspace.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
