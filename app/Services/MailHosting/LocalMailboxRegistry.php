<?php

namespace App\Services\MailHosting;

use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Models\User;
use App\Services\Administration\ProfessionalMailboxPresenter;
use App\Services\Administration\ProfessionalMailboxWorkflow;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * ADR-190 — le site : l'état des adresses s'écrit dans sa propre base, par le
 * même service que l'API servie au portail. Les droits et les transitions sont
 * donc vérifiés au même endroit, quel que soit l'écran d'où vient le geste.
 */
final class LocalMailboxRegistry implements MailboxRegistry
{
    public function __construct(
        private readonly ProfessionalMailboxWorkflow $workflow,
        private readonly ProfessionalMailboxPresenter $presenter,
    ) {}

    public function find(string $site, string $mailboxUuid, User $actor): array
    {
        return $this->attempt(fn () => ProfessionalMailbox::query()->where('uuid', $mailboxUuid)->firstOrFail());
    }

    public function request(string $site, array $payload, User $actor): array
    {
        return $this->attempt(fn () => $this->workflow->request(
            Employee::query()->where('uuid', $payload['employee_uuid'])->firstOrFail(),
            $payload,
            CatalogActor::fromUser($actor),
        ));
    }

    public function command(string $site, string $mailboxUuid, string $command, array $payload, User $actor): array
    {
        return $this->attempt(function () use ($mailboxUuid, $command, $payload, $actor) {
            $mailbox = ProfessionalMailbox::query()->where('uuid', $mailboxUuid)->firstOrFail();
            $catalogActor = CatalogActor::fromUser($actor);

            return match ($command) {
                'activate' => $this->workflow->activate($mailbox, (string) $payload['address'], $catalogActor),
                'reject' => $this->workflow->reject($mailbox, (string) $payload['reason'], $catalogActor),
                'suspend' => $this->workflow->suspend($mailbox, (string) $payload['reason'], $catalogActor),
                'reactivate' => $this->workflow->reactivate($mailbox, $catalogActor),
            };
        });
    }

    /** @return array<string, mixed> */
    private function attempt(callable $action): array
    {
        try {
            return ['ok' => true, 'data' => $this->presenter->present($action()), 'message' => null, 'errors' => []];
        } catch (ValidationException $exception) {
            return ['ok' => false, 'data' => null, 'message' => collect($exception->errors())->flatten()->first(), 'errors' => $exception->errors(), 'http_status' => 422];
        } catch (AuthorizationException) {
            return ['ok' => false, 'data' => null, 'message' => 'Cette action n’est pas autorisée.', 'errors' => [], 'http_status' => 403];
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'data' => null, 'message' => 'Adresse ou employé introuvable.', 'errors' => [], 'http_status' => 404];
        }
    }
}
