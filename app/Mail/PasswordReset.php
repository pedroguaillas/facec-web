<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(protected User $user, string $token)
    {
        $this->resetUrl = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'user' => $this->user->user,
        ]);
    }

    public function build(): self
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Recupera tu contraseña - FACEC')
            ->view('emails.password-reset', [
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
