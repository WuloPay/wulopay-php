<?php

namespace WuloPay\Tests;

use WuloPay\HttpClient\HttpClientInterface;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array{status:int, headers:array<string,string>, body:string}> */
    public $responses = [];
    /** @var array<int, array{method:string, url:string, headers:array<string,string>, body:?string}> */
    public $requests = [];

    public function queue(int $status, $body, array $headers = []): self
    {
        $this->responses[] = ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];
        return $this;
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new \LogicException('No response queued for ' . $method . ' ' . $url);
        }
        return array_shift($this->responses);
    }
}
