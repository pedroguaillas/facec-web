<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    /** Horas de validez del enlace de verificación. */
    const EXPIRES_IN_HOURS = 24;

    public string $verifyUrl;

    public function __construct(protected User $user)
    {
        $this->verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(self::EXPIRES_IN_HOURS),
            ['id' => $this->user->id, 'hash' => sha1($this->user->email)],
        );
    }

    public function build(): self
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Confirma tu correo - FACEC')
            ->view('emails.verify-email', [
                'user' => $this->user,
                'verifyUrl' => $this->verifyUrl,
                'expiresInHours' => self::EXPIRES_IN_HOURS,
            ]);
    }
}
