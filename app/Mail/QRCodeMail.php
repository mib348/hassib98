<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QRCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public $order;

    /**
     * Create a new message instance.
     */
    public function __construct($order)
    {
        $this->order = $order;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'QR-Code Mail',
            metadata: [
                'order_id' => $this->order['id'],
            ]
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $qrcode = (string) QrCode::format('svg')->size(200)->generate($this->order['order_number']);
        return new Content(
            null, null, null, null, [], $qrcode,
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
