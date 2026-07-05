@php
    $requestNo = $data['request_no'] ?? '—';
    $requestedBy = $data['requested_by'] ?? '—';
    $buyer = $data['buyer'] ?? null;
    $supplier = $data['supplier'] ?? null;
    $season = $data['season'] ?? null;
    $totalAmount = $data['total_amount'] ?? null;
    $requiredDate = $data['payment_required_date'] ?? null;
    $reviewUrl = $data['review_url'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PRA Approval Request</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e9f2;">
                    <tr>
                        <td style="background:#000b6f;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;">
                            Payment Request Approval — Action Required
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px;font-size:15px;">
                                A Payment Request Approval (PRA) has been submitted and requires your approval.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;width:170px;">PRA Number</td>
                                    <td style="padding:8px 0;font-weight:bold;">{{ $requestNo }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Requested By</td>
                                    <td style="padding:8px 0;">{{ $requestedBy }}</td>
                                </tr>
                                @if($buyer)
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Buyer</td>
                                    <td style="padding:8px 0;">{{ $buyer }}</td>
                                </tr>
                                @endif
                                @if($supplier)
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Supplier / Vendor</td>
                                    <td style="padding:8px 0;">{{ $supplier }}</td>
                                </tr>
                                @endif
                                @if($season)
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Season</td>
                                    <td style="padding:8px 0;">{{ $season }}</td>
                                </tr>
                                @endif
                                @if($requiredDate)
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Payment Required Date</td>
                                    <td style="padding:8px 0;">{{ $requiredDate }}</td>
                                </tr>
                                @endif
                                @if($totalAmount)
                                <tr>
                                    <td style="padding:8px 0;color:#6b7280;">Total PI Amount</td>
                                    <td style="padding:8px 0;font-weight:bold;color:#000b6f;">{{ $totalAmount }}</td>
                                </tr>
                                @endif
                            </table>

                            @if($reviewUrl)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 4px;">
                                <tr>
                                    <td style="border-radius:8px;background:#000b6f;">
                                        <a href="{{ $reviewUrl }}" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">
                                            Review &amp; Approve
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
