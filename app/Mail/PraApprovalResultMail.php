<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the PRA creator when the approval cycle finishes — either fully
 * approved by every selected approver, or rejected by one of them.
 */
class PraApprovalResultMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $data PRA details, final status and the
     *                                    approver-wise decision breakdown.
     */
    public function __construct(public array $data)
    {
    }

    public function envelope(): Envelope
    {
        $status = strtoupper((string) ($this->data['status_label'] ?? 'Updated'));

        return new Envelope(
            subject: 'PRA ' . $status . ': ' . ($this->data['request_no'] ?? 'Payment Request'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pra-approval-result',
            with: ['data' => $this->data],
        );
    }
}
