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
        public string $kind = 'signup',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->kind === 'reset'
                ? 'Your Daleachious reset code'
                : 'Your Daleachious one-time password',
        );
    }

    public function content(): Content
    {
        $isReset = $this->kind === 'reset';

        return new Content(
            html: 'emails.otp',
            text: 'emails.otp-text',
            with: [
                'code' => $this->code,
                'kind' => $this->kind,
                'preheader' => $isReset
                    ? "Your reset code is {$this->code}. It expires in 15 minutes."
                    : "Your one-time password is {$this->code}. It expires in 15 minutes.",
                'eyebrow' => $isReset ? 'Password' : 'Membership',
                'headline' => $isReset ? 'Reset your password' : 'Confirm your email',
                'lede' => $isReset
                    ? 'Enter this code in the app to choose a new password.'
                    : 'Enter this code in the app to continue creating your account.',
            ],
        );
    }
}
