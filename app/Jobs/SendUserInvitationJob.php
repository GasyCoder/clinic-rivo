<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

/**
 * Sends the "set your password" link of an invited account.
 *
 * Never sent inside the request that creates the account. A mail server that
 * is slow or unreachable used to hold that request past the portal's 5-second
 * timeout: the portal reported "Le site ne répond pas", and the SMTP failure
 * rolled the whole creation back. Creating an account is a local, audited
 * write; delivering an email is a distributed side effect, and gets the
 * queue, retry and backoff ADR-017 asks for such operations.
 */
class SendUserInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** An account removed before delivery simply has no invitation left to send. */
    public bool $deleteWhenMissingModels = true;

    /** @var array<int, int> seconds */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly User $user) {}

    public function handle(): void
    {
        // A deactivated account keeps no pending invitation.
        if (! $this->user->fresh()?->isActive()) {
            return;
        }

        // Its own broker and table (config/auth.php "invitations"), and a
        // welcome email instead of Laravel's "Reset your password".
        Password::broker('invitations')->sendResetLink(
            ['email' => $this->user->email],
            fn (User $user, string $token) => $user->notify(new AccountInvitationNotification($token)),
        );
    }
}
