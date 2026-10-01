<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Throwable;

/**
 * ADR-222 — ce qu'une panne du fournisseur devient à l'écran.
 *
 * Le message du fournisseur n'est jamais repris : il peut citer le modèle, la
 * requête ou un fragment de la clé. Seule sa catégorie sort, avec une phrase qui
 * dit quoi faire — et, pour le Super Administrateur, le code HTTP.
 */
class AssistantErrors
{
    /**
     * @return array{reason: string, message: string, status: ?int}
     */
    public static function describe(Throwable $exception): array
    {
        $status = self::status($exception);

        [$reason, $message] = match (true) {
            $exception instanceof RateLimitedException => ['provider_rate_limited', 'Le fournisseur d’IA reçoit trop de demandes. Réessayez dans une minute.'],
            $exception instanceof InsufficientCreditsException => ['credits', 'Le compte du fournisseur d’IA n’a plus de crédit. Prévenez l’administrateur.'],
            $exception instanceof ProviderOverloadedException => ['overloaded', 'Le fournisseur d’IA est momentanément surchargé. Réessayez dans quelques instants.'],
            $exception instanceof ProviderConnectionException, $exception instanceof ConnectionException => ['connection', 'Le fournisseur d’IA ne répond pas (délai dépassé ou réseau). Réessayez dans quelques instants.'],
            $status === 401, $status === 403 => ['auth', 'La clé d’API du fournisseur est refusée. Prévenez l’administrateur : elle doit être remplacée.'],
            $status === 404 => ['model', 'Le modèle réglé n’existe pas chez ce fournisseur. Prévenez l’administrateur.'],
            $status !== null && $status >= 400 && $status < 500 => ['request', 'Le fournisseur d’IA a refusé la demande. Prévenez l’administrateur si cela se reproduit.'],
            $exception instanceof StreamErrorException => ['stream', 'La réponse a été interrompue par le fournisseur d’IA. Réessayez.'],
            default => ['unknown', 'L’assistant n’a pas pu répondre. Réessayez dans quelques instants.'],
        };

        return ['reason' => $reason, 'message' => $message, 'status' => $status];
    }

    /**
     * Une ligne au journal du serveur : la catégorie, le code HTTP et la classe de
     * l'exception — jamais son message, qui peut citer la requête ou le corps de la
     * réponse du fournisseur. Rien n'est envoyé au rapporteur d'erreurs (Sentry…).
     *
     * @param  array{reason: string, message: string, status: ?int}  $error
     */
    public static function log(Throwable $exception, array $error, string $provider, string $model): void
    {
        Log::warning('Assistant IA : le fournisseur n’a pas répondu.', [
            'reason' => $error['reason'],
            'status' => $error['status'],
            'exception' => $exception::class,
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    private static function status(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && $current->response !== null) {
                return $current->response->status();
            }
        }

        return null;
    }
}
