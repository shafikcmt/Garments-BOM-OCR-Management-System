<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Daily list of a person's overdue / due-today follow-ups, or of tasks escalated to a supervisor. */
class FollowUpDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{title: string, due: string, who: ?string, days_late: int}>  $lines
     */
    public function __construct(public string $heading, public string $intro, public array $lines, public string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.follow-up-digest', with: ['heading' => $this->heading, 'intro' => $this->intro, 'lines' => $this->lines, 'url' => $this->url]);
    }
}
