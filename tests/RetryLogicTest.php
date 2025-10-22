<?php

namespace KennyMgn\ProfitbaseClient\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use KennyMgn\ProfitbaseClient\Exceptions\HttpClientRuntimeException;
use KennyMgn\ProfitbaseClient\Tests\Traits\CreatesMockClientTrait;
use PHPUnit\Framework\TestCase;

class RetryLogicTest extends TestCase
{
    use CreatesMockClientTrait;

    public function testRetryRequestOnceRefreshesTokenAndRetries(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(403, [], json_encode(['error' => 'Token expired'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['access_token' => 'refreshed-token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'success after retry'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $response = $client->request('GET', 'test-endpoint');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            json_encode(['data' => 'success after retry'], JSON_THROW_ON_ERROR),
            $response->getBody()->getContents()
        );
    }

    public function testRetryRequestOnceThrowsExceptionWhenRefreshFails(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(403, [], json_encode(['error' => 'Token expired'], JSON_THROW_ON_ERROR)),
            new Response(401, [], json_encode(['error' => 'Invalid API key'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $this->expectException(HttpClientRuntimeException::class);
        $this->expectExceptionMessage('Failed to refresh access token');
        $client->request('GET', 'test-endpoint');
    }

    public function testMultipleTokenExpirationsResultInException(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(403, [], json_encode(['error' => 'Token expired'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['access_token' => 'refreshed-token'], JSON_THROW_ON_ERROR)),
            new Response(403, [], json_encode(['error' => 'Token expired again'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $this->expectException(HttpClientRuntimeException::class);
        $this->expectExceptionMessage('Access token expired and refresh failed');
        $client->request('GET', 'test-endpoint', retry: true);
    }
}
