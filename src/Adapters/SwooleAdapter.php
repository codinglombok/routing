<?php

declare(strict_types=1);

namespace LombokClarion\Routing\Adapters;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Http\RuntimeAdapter;
use LombokClarion\Routing\Kernel;
use RuntimeException;

/**
 * Optional, opt-in warm-worker mode (§4.6: built last, not required).
 *
 * IMPORTANT: because the worker process is long-lived, the Kernel/Container
 * instance is reused across requests. Application code must still avoid
 * request-scoped state on any singleton — that's what RequestContext
 * (bound fresh per request, see Kernel) exists for. This adapter does not
 * change that contract; it only changes how often the process itself
 * restarts.
 */
final class SwooleAdapter implements RuntimeAdapter
{
    public function __construct(private readonly Kernel $kernel)
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'SwooleAdapter requires the swoole extension. This adapter is optional — ' .
                'use FpmAdapter or FunctionAdapter if swoole is not installed.'
            );
        }
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
}
