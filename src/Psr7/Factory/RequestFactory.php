<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Factory;

use Kode\Http\Psr7\Message\Request;
use Kode\Http\Psr7\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriInterface;

class RequestFactory implements RequestFactoryInterface
{
    public function createRequest(string $method, $uri): \Psr\Http\Message\RequestInterface
    {
        if ($uri instanceof UriInterface) {
            return new Request($method, $uri);
        }
        return new Request($method, new Uri($uri));
    }
}