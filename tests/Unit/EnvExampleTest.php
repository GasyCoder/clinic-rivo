<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * Guards against exactly the mistake that shipped once already: an
 * unquoted multi-word value (`RIVO_SITE_NAME=Super Administration`) is
 * invalid dotenv syntax and makes the framework fail to boot at all
 * (blank 500, nothing in Laravel's own log — the crash happens before
 * the exception handler exists). Config-override tests never catch this
 * because they set config() directly, bypassing .env parsing entirely.
 */
class EnvExampleTest extends TestCase
{
    public function test_every_commented_example_line_in_env_example_is_valid_dotenv_syntax(): void
    {
        $lines = file(__DIR__.'/../../.env.example', FILE_IGNORE_NEW_LINES);

        $invalid = [];

        foreach ($lines as $line) {
            if (! preg_match('/^#\s*([A-Z0-9_]+=.*)$/', trim($line), $matches)) {
                continue;
            }

            $body = $matches[1];

            try {
                Dotenv::parse($body);
            } catch (\Throwable $e) {
                $invalid[] = "{$line} — {$e->getMessage()}";
            }
        }

        $this->assertSame([], $invalid, "Invalid dotenv syntax in commented .env.example examples:\n".implode("\n", $invalid));
    }
}
