<?php

namespace App\Services\Catalog;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CatalogActor
{
    /** @param array<int, string> $permissions */
    private function __construct(
        private ?User $user,
        private ?string $externalUuid,
        private ?string $externalName,
        private array $permissions,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self($user, null, null, []);
    }

    public static function fromRemoteRequest(Request $request): self
    {
        $uuid = trim((string) $request->attributes->get('rivo_actor_uuid'));
        $name = str((string) $request->attributes->get('rivo_actor_name'))->squish()->toString();
        $permissions = $request->attributes->get('rivo_actor_permissions', []);

        if (! Str::isUuid($uuid) || $name === '') {
            throw ValidationException::withMessages([
                'actor' => 'L’identité du Super Administrateur est obligatoire.',
            ]);
        }

        return new self(
            null,
            $uuid,
            mb_substr($name, 0, 150),
            is_array($permissions) ? array_values($permissions) : [],
        );
    }

    public function can(string $permission): bool
    {
        return $this->user
            ? $this->user->can($permission)
            : in_array($permission, $this->permissions, true);
    }

    public function cannot(string $permission): bool
    {
        return ! $this->can($permission);
    }

    public function localUserId(): ?int
    {
        return $this->user?->getKey();
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** @return array<string, string|null> */
    public function externalAttribution(string $verb): array
    {
        return [
            "external_{$verb}_by_uuid" => $this->externalUuid,
            "external_{$verb}_by_name" => $this->externalName,
        ];
    }
}
