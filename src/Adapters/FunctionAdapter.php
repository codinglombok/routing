<?php

declare(strict_types=1);

namespace LombokClarion\Routing\Adapters;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Http\RuntimeAdapter;
use LombokClarion\Routing\Kernel;

/**
 * Edge/serverless target (Lambda@Edge, Cloudflare Workers via PHP-WASM,
 * Vercel Functions, etc). The provider-specific event object is translated
 * into a Request by a thin, per-provider shim living in the app's
 * public/ entrypoint — this class deliberately knows nothing about any
 * specific provider's event format, keeping the framework provider-neutral.
 *
 * Per §5: no assumption of a persistent process, no reliance on a warm
 * APCu cache, no in-process DB connection pool. This adapter is invoked
 * fresh on every invocation.
 */
final class FunctionAdapter implements RuntimeAdapter
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
}
