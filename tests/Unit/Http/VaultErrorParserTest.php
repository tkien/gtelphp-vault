<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Http;

use GtelPhp\Vault\Http\VaultErrorParser;
use PHPUnit\Framework\TestCase;

final class VaultErrorParserTest extends TestCase
{
    public function test_parses_errors_array_from_vault_json_body(): void
    {
        $errors = VaultErrorParser::parse('{"errors":["permission denied"]}');

        self::assertSame(['permission denied'], $errors);
    }

    public function test_parses_multiple_errors(): void
    {
        $errors = VaultErrorParser::parse('{"errors":["error one","error two"]}');

        self::assertSame(['error one', 'error two'], $errors);
    }

    public function test_returns_empty_array_for_non_json_body(): void
    {
        self::assertSame([], VaultErrorParser::parse(''));
    }

    public function test_returns_raw_body_when_not_a_vault_error_shape(): void
    {
        self::assertSame(['plain text error'], VaultErrorParser::parse('plain text error'));
    }

    public function test_message_includes_status_code_and_errors(): void
    {
        $message = VaultErrorParser::message('{"errors":["permission denied"]}', 403);

        self::assertSame('Vault request failed with HTTP status 403: permission denied', $message);
    }

    public function test_message_falls_back_to_generic_text_without_errors(): void
    {
        $message = VaultErrorParser::message('', 500);

        self::assertSame('Vault request failed with HTTP status 500.', $message);
    }
}
