<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyRecord;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || mb_strlen($key) > 100) {
            return $this->error('Un en-tête Idempotency-Key valide est obligatoire.', 422);
        }

        $hash = hash('sha256', implode('|', [
            $request->method(),
            '/'.$request->path(),
            (string) $request->getContent(),
        ]));

        try {
            $record = ApiIdempotencyRecord::query()->create([
                'key' => $key,
                'request_hash' => $hash,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
            ]);
        } catch (QueryException) {
            $record = ApiIdempotencyRecord::query()->where('key', $key)->firstOrFail();

            if (! hash_equals($record->request_hash, $hash)) {
                return $this->error("Cette clé d'idempotence a déjà été utilisée pour une autre requête.", 409);
            }

            if (! $record->completed_at) {
                return $this->error('Une requête identique est déjà en cours.', 409);
            }

            return response($record->response_body, $record->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'completed_at' => now(),
        ]);

        return $response;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
