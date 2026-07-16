<?php

declare(strict_types=1);

namespace LombokClarion\Routing\Adapters;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Http\RuntimeAdapter;
use LombokClarion\Routing\Kernel;

/**
 * Classic PHP-FPM adapter: one process per request, superglobals available.
 * This is the first adapter implemented (§4.6) because it has no warm-state
 * assumptions to get wrong.
 */
final class FpmAdapter implements RuntimeAdapter
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }

    /**
     * Convenience entry point for public/index.php under real FPM: reads
     * superglobals, runs the kernel, and emits the response.
     */
    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
