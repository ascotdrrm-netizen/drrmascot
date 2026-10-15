<?php

namespace App\Notifications;

use App\Models\Personnel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Replaces Laravel's default password-reset notification (which Personnel
 * would otherwise send via the CanResetPassword trait it inherits from
 * Illuminate\Foundation\Auth\User) with ASCOT DRRMS-branded copy. Wired up
 * in Personnel::sendPasswordResetNotification().
 *
 * Deliberately NOT queued (no ShouldQueue) — this app's QUEUE_CONNECTION is
 * database, and sending synchronously means a request in MAIL_MAILER=log
 * mode is immediately readable in storage/logs/laravel.log without needing
 * a queue worker running.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $token)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Personnel $notifiable): MailMessage
    {
        // route(..., false) returns a relative path; wrapping it in url()
        // prepends the app's configured APP_URL — so this link is correct
        // on localhost now and on the live domain later with no code change.
        $resetUrl = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage())
            ->subject('Reset Your ASCOT DRRMS Password')
            ->greeting('Hello ' . $notifiable->full_name . ',')
            ->line('We received a request to reset the password for your ASCOT DRRMS account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in 60 minutes.')
            ->line("If you didn't request a password reset, no action is needed — you can safely ignore this email.")
            ->salutation('— ASCOT DRRMS, Aurora State College of Technology');
    }
}
