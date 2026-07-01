<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string      $subjectLine Final subject (placeholders already replaced).
     * @param string      $bodyHtml    Final HTML body (placeholders already replaced).
     * @param string|null $pdfData     Raw PDF bytes to attach, or null when unavailable.
     * @param string      $pdfName        File name for the PDF attachment.
     * @param string|null $replyToAddress Sender's email used as the Reply-To address.
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public ?string $pdfData = null,
        public string $pdfName = 'payment-request.pdf',
        public ?string $replyToAddress = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-request',
            with: ['bodyHtml' => $this->bodyHtml],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->pdfData === null || $this->pdfData === '') {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdfData, $this->pdfName)
                ->withMime('application/pdf'),
        ];
    }
}
