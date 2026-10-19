<?php

namespace App\Mail;

use App\Models\Personnel;
use App\Models\Reporter\SosEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Free SOS alert to one staff member's inbox; queued so the SOS answers at once. */
class SosAlert extends Mailable implements ShouldQueue
{
    // SerializesModels: the queued job holds the two records' IDs only (never a
    // password hash) and reloads them when the email is sent.
    use Queueable, SerializesModels;

    public function __construct(public SosEvent $sos, public Personnel $reporter, public string $consoleUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "ASCOT DRRMS SOS #{$this->sos->sos_id}"
            .($this->sos->type ? " ({$this->sos->type})" : '').": {$this->reporter->full_name} needs help");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sos-alert');
    }
}
