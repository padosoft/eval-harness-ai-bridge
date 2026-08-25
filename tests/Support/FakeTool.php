<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A real tool, so the recorder tests exercise the same name resolution
 * laravel/ai uses rather than a mock's generated class name.
 */
final class FakeTool implements Tool
{
    public function __construct(private readonly string $name = 'lookup_order') {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'Looks an order up by id.';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
