<?php

namespace KennyMgn\ProfitbaseClient\Tests\Traits;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use KennyMgn\ProfitbaseClient\ProfitbaseClient;

trait CreatesMockClientTrait
{
    private function createClientWithMockHandler(
        MockHandler $mockHandler,
        string $apiKey = 'test-key'
    ): ProfitbaseClient {
        $handlerStack = HandlerStack::create($mockHandler);
        $config = ['handler' => $handlerStack];

        return ProfitbaseClient::create($apiKey, 'https://api.profitbase.test/v4/', $config);
    }
}
