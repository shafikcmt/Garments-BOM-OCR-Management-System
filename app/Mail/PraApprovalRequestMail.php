<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a selected approver when a PRA is submitted for their approval.
 */
class PraApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $data PRA details + review link.
     */
    public function __construct(public array $data)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'PRA Approval Request: ' . ($this->data['request_no'] ?? 'Payment Request'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pra-approval-request',
            with: ['data' => $this->data],
        );
    }
}
