<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Single write path for CDC §22 audit entries. Every future Action/Service
 * that performs a sensitive operation (create, update, delete, restore,
 * validate, approve, reject, cancel, payment.*, stock.*, ...) should record
 * through this instead of writing to `audit_logs` directly, so the schema
 * and request/actor capture logic live in exactly one place.
 */
class Auditor
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $oldValues
     */
    public function record(
        string $action,
        ?Model $entity = null,
        array $newValues = [],
        array $oldValues = [],
        ?string $reason = null,
        ?string $module = null,
        ?Authenticatable $actor = null,
    ): AuditLog {
        // Auth::user() rather than $this->request->user(): the latter only
        // resolves correctly once a real HTTP request has passed through
        // the kernel's auth middleware. Called from a queued job, a console
        // command, or a test that sets the actor via actingAs() without
        // dispatching a real request, the request's own resolver was never
        // wired — the auth guard is the one source that's always correct.
        $actor ??= Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'module' => $module,
            'site_code' => config('rivo.site.code'),
            'site_name' => config('rivo.site.name'),
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'entity_uuid' => $entity?->uuid ?? null,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'request_uuid' => $this->requestUuid(),
        ]);
    }

    /**
     * One UUID per HTTP request, generated lazily on first use and reused
     * by every audit entry recorded during that same request — so a
     * request touching several entities produces correlated entries.
     */
    private function requestUuid(): string
    {
        if (! $this->request->attributes->has('audit_request_uuid')) {
            $this->request->attributes->set('audit_request_uuid', (string) Str::uuid());
        }

        return $this->request->attributes->get('audit_request_uuid');
    }
}
