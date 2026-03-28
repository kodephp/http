<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Factory;

use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Uri;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\UriInterface;

class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        if ($uri instanceof UriInterface) {
            return new ServerRequest($method, $uri, $serverParams);
        }
        return new ServerRequest($method, new Uri($uri), $serverParams);
    }
}