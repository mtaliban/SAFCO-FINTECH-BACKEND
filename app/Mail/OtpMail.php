<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $type,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SAFCO LMS - Your Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.otp',
            with: [
                'code' => $this->code,
                'type' => str_replace('_', ' ', $this->type),
                'expiresIn' => config('auth.otp.expiry_minutes', 5),
            ],
        );
    }
}
