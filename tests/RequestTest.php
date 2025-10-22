<?php

namespace KennyMgn\ProfitbaseClient\Tests;

use Exception;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use KennyMgn\ProfitbaseClient\Exceptions\HttpClientRuntimeException;
use KennyMgn\ProfitbaseClient\Tests\Traits\CreatesMockClientTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

class RequestTest extends TestCase
{
    use CreatesMockClientTrait;

    public function testRequestReturnsResponseOnSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'success'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);
        $response = $client->request('GET', 'test-endpoint');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            json_encode(['data' => 'success'], JSON_THROW_ON_ERROR),
            $response->getBody()->getContents()
        );
    }

    public function testRequestThrowsHttpClientRuntimeExceptionOn403WhenTokenRefreshFails(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(403, [], json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR)),
            // Token refresh fails
            new Response(401, [], json_encode(['error' => 'Invalid API key'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $this->expectException(HttpClientRuntimeException::class);
        $this->expectExceptionMessage('Failed to refresh access token');
        $client->request('GET', 'test-endpoint');
    }

    public function testRequestRetriesOn403WithSuccessfulTokenRefresh(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(403, [], json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR)),
            // Successful token refresh
            new Response(200, [], json_encode(['access_token' => 'refreshed-token'], JSON_THROW_ON_ERROR)),
            // Successful retry
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

    public function testRequestReturnsResponseOn500(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(500, [], json_encode(['error' => 'Server Error'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $response = $client->request('GET', 'test-endpoint');
        $this->assertSame(500, $response->getStatusCode());
    }

    public function testRequestThrowsHttpClientRuntimeExceptionOnNetworkError(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Exception('Network error'),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $this->expectException(HttpClientRuntimeException::class);
        $this->expectExceptionMessage('Unexpected error occurred during HTTP request');
        $client->request('GET', 'test-endpoint');
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildRequestOptionsIncludesAccessTokenAndBody(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'test-token'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('buildRequestOptions');
        $method->setAccessible(true);

        $queryParams = ['filter' => 'active'];
        $body = ['name' => 'test'];
        $result = $method->invoke($client, $queryParams, $body);

        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('json', $result);
        $this->assertStringContainsString('access_token=test-token', $result['query']);
        $this->assertStringContainsString('filter=active', $result['query']);
        $this->assertSame(['name' => 'test'], $result['json']);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildRequestOptionsHandlesEmptyParameters(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'test-token'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('buildRequestOptions');
        $method->setAccessible(true);

        $result = $method->invoke($client, [], []);

        $this->assertArrayHasKey('query', $result);
        $this->assertStringContainsString('access_token=test-token', $result['query']);
        $this->assertArrayNotHasKey('json', $result);
    }
}
