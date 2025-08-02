<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactFormSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public array $contactData,
        public string $submissionTime
    ) {
        $this->submissionTime = Carbon::now('Europe/Berlin')->format('d.m.Y H:i:s');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Neue Catering-Station Anfrage von ' . $this->contactData['Dein Unternehmen'],
            from: config('mail.from.address'),
            replyTo: $this->contactData['email']
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contact-form',
            with: [
                'contactData' => $this->contactData,
                'submissionTime' => $this->submissionTime,
                'companyName' => $this->contactData['Dein Unternehmen'],
                'employeeCount' => $this->contactData['Mitarbeiteranzahl'],
                'contactEmail' => $this->contactData['email'],
                'contactName' => $this->contactData['Dein Name'] ?? 'Nicht angegeben',
                'contactPhone' => $this->contactData['Deine Telefonnummer'] ?? 'Nicht angegeben',
                'cateringStation' => $this->contactData['Catering-Station groß'] ?? 'Standard'
            ]
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
