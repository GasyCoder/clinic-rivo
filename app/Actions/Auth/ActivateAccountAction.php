<?php

namespace App\Actions\Auth;

use App\Actions\StaffAccess\SendStaffAccessHandoverAction;
use App\Models\StaffAccessHandoverItem;
use App\Models\User;
use App\Notifications\AccountActivatedMail;
use App\Notifications\StaffAccessActivated;
use App\Notifications\WelcomeToPlatform;
use App\Services\Audit\Auditor;
use App\Services\Auth\AccountActivation;
use App\Services\MailHosting\CpanelMailboxClient;
use App\Services\MailHosting\MailHostingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADR-202 — la première connexion : l'employé choisit son mot de passe.
 *
 *  1. le compte est relu sous verrou : il doit attendre encore sa première
 *     connexion (deux onglets, deux clics : un seul l'active) ;
 *  2. le mot de passe choisi devient celui du compte, qui est dit « activé » ;
 *  3. le même mot de passe est posé sur sa boîte professionnelle chez
 *     l'hébergeur, si ce serveur y a accès — après la transaction, pour ne pas
 *     garder le compte verrouillé pendant l'appel ;
 *  4. il est accueilli (cloche), reçoit « Votre compte est validé » dans sa boîte,
 *     et le RH est prévenu — c'est aussi ce qui fait voir une première connexion
 *     que l'employé n'aurait pas faite lui-même.
 *
 * Le mot de passe n'est écrit nulle part ailleurs : ni en clair, ni dans l'audit.
 */
final class ActivateAccountAction
{
    public function __construct(
        private readonly AccountActivation $activation,
        private readonly CpanelMailboxClient $hosting,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{ip?: ?string, user_agent?: ?string}  $context
     * @return array{user: User, mailbox: ?string, mailbox_ready: bool}
     */
    public function execute(string $email, #[\SensitiveParameter] string $password, array $context = []): array
    {
        $user = DB::transaction(function () use ($email, $password): User {
            $user = User::query()->where('email', mb_strtolower(trim($email)))->lockForUpdate()->first();

            if (! $this->activation->opensFor($user)) {
                throw ValidationException::withMessages([
                    'email' => 'Ce compte n’attend plus sa première connexion : connectez-vous avec votre mot de passe.',
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'activated_at' => now(),
                'activation_open_until' => null,
            ])->save();

            return $user;
        });

        $mailbox = $this->activation->mailbox($user)?->address;
        [$ready, $error] = $mailbox === null ? [false, null] : $this->syncMailbox($mailbox, $password);

        $this->auditor->record('user.activate', entity: $user, newValues: array_filter([
            'email' => $user->email,
            'ip' => $context['ip'] ?? null,
            'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 255) : null,
            'mailbox' => $mailbox,
            'mailbox_synced' => $mailbox === null ? null : $ready,
            'mailbox_error' => $error,
        ], fn ($value) => $value !== null), module: 'auth', actor: $user);

        $this->notify($user, $mailbox, $ready);

        return ['user' => $user, 'mailbox' => $mailbox, 'mailbox_ready' => $ready];
    }

    /** @return array{0: bool, 1: ?string} prête ou non, et pourquoi (jamais le mot de passe) */
    private function syncMailbox(string $address, #[\SensitiveParameter] string $password): array
    {
        if (! $this->hosting->configured()) {
            return [false, 'L’accès à l’hébergeur n’est pas configuré sur ce site.'];
        }

        try {
            $this->hosting->changePassword($address, $password);

            return [true, null];
        } catch (MailHostingException $exception) {
            return [false, $exception->getMessage()];
        } catch (Throwable) {
            return [false, 'L’hébergeur n’a pas répondu.'];
        }
    }

    private function notify(User $user, ?string $mailbox, bool $ready): void
    {
        // La plateforme ne doit jamais refuser l'entrée parce qu'une notification échoue.
        try {
            $user->notify(new WelcomeToPlatform((string) config('app.name'), $mailbox, $ready));
            $user->notify(new AccountActivatedMail(now()->format('d/m/Y à H:i')));

            $item = StaffAccessHandoverItem::query()->where('user_id', $user->getKey())->latest('id')->with('handover:id,uuid,sent_at')->first();
            $recipients = SendStaffAccessHandoverAction::recipients()->reject(fn (User $recipient) => $recipient->is($user));

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new StaffAccessActivated(
                    $user->name,
                    now(),
                    $item?->handover?->sent_at !== null ? $item->handover->uuid : null,
                    $mailbox === null || $ready,
                ));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
