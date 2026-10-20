<?php

namespace Mainstay\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
 | The mail an account made in the admin gets, with a link to choose its
 | first password: the reset page, through a token of the same broker, so
 | an expired link is answered as a reset's is, by asking for another.
 */
class Invitation extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token, public readonly ?string $from = null) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Mainstay account')
            ->line(($this->from === null ? 'An account was made for you' : "{$this->from} made you an account").' in the Mainstay admin at '.route('mainstay.admin').'. Choose a password to sign in with:')
            ->action('Choose a password', route('mainstay.password.reset', ['token' => $this->token, 'email' => $user->email]))
            ->line(sprintf('The link works for %d minutes. After that, ask for another with "Forgot your password?" on the sign-in page.', config('auth.passwords.mainstay.expire', 60)));
    }
}
