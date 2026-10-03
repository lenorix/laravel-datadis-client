<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A transport that cannot send: the client of an import never reaches Datadis, whatever the HTTP settings or the
 * environment say, and nothing a test fakes can see it.
 *
 * @internal
 */
final class MutedTransport implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new LogicException('An import never sends a request.');
    }
}
