<?php

namespace Mainstay\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
 | The mail with a link to choose a new password. Mainstay's own rather than
 | Laravel's ResetPassword, which builds its link from the host's
 | `password.reset` route or from a callback the host's own users share.
 */
class ResetPassword extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your Mainstay password')
            ->line('Someone asked to reset the password of your Mainstay account. If that was you, choose a new one:')
            ->action('Choose a new password', route('mainstay.password.reset', ['token' => $this->token, 'email' => $user->email]))
            ->line(sprintf('The link works for %d minutes. If you did not ask, ignore this mail and your password stays as it is.', config('auth.passwords.mainstay.expire', 60)));
    }
}
