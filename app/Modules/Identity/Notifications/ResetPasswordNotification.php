<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<string, mixed>
     */
    protected function resetUrl(mixed $notifiable): string
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');

        return $frontend.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
