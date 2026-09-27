<?php

namespace App\Http\Controllers\SuperAdmin\Concerns;

use Illuminate\Http\RedirectResponse;

/**
 * Portal controllers that forward a command to one site's API: the site is
 * checked against the configured list, and the site's refusal is shown back
 * on the form instead of being hidden.
 */
trait RespondsToSiteApi
{
    private function assertSite(string $site): void
    {
        abort_unless(in_array(mb_strtoupper($site), $this->siteCodes(), true), 404);
    }

    /** @return array<int, string> */
    private function siteCodes(): array
    {
        return collect(config('rivo.clinics', []))->pluck('code')->all();
    }

    /** @param array<string, mixed> $result */
    private function respond(array $result, string $successMessage): RedirectResponse
    {
        if (! $result['ok']) {
            return back()->withErrors($this->siteErrors($result));
        }

        return back()->with('status', $result['message'] ?: $successMessage);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private function siteErrors(array $result): array
    {
        $errors = collect($result['errors'] ?? [])->mapWithKeys(
            fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
        )->all();

        return $errors ?: ['site' => $result['message']];
    }
}
