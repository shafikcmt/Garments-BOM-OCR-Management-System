<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PiMissingAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $alert Notification data (po_no, days_overdue, buyer, etc.)
     */
    public function __construct(public array $alert)
    {
    }

    public function envelope(): Envelope
    {
        $poNo = $this->alert['po_no'] ?? 'PO';

        return new Envelope(
            subject: 'PI Missing Alert: ' . $poNo,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pi-missing-alert',
            with: ['alert' => $this->alert],
        );
    }
}
