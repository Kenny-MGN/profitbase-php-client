<?php

namespace KennyMgn\ProfitbaseClient\Support;

class QueryStringBuilder
{
    /**
     * @param array<string, scalar|null|array<scalar|null>> $params
     */
    public function build(array $params): string
    {
        [$singleValueParams, $multiValueParams] = $this->splitParams($params);

        $singleValueQuery = $this->buildSingleValueQuery($singleValueParams);
        $multiValueQuery = $this->buildMultiValueQuery($multiValueParams);

        return $this->assembleQueryString($singleValueQuery, $multiValueQuery);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $params
     * @return array{0: array<string, scalar|null>, 1: array<string, array<scalar|null>>}
     */
    protected function splitParams(array $params): array
    {
        $singleValueParams = array_filter($params, fn($value) => !is_array($value));
        $multiValueParams = array_filter($params, fn($value) => is_array($value));

        return [$singleValueParams, $multiValueParams];
    }

    protected function assembleQueryString(string ...$queries): string
    {
        $parts = array_filter($queries);
        return empty($parts) ? '' : implode('&', $parts);
    }

    /**
     * @param array<string, scalar|null> $singleValueParams
     */
    protected function buildSingleValueQuery(array $singleValueParams): string
    {
        $normalizedParams  = array_map([$this, 'convertValueToString'], $singleValueParams);
        return http_build_query($normalizedParams);
    }

    /**
     * @param array<string, array<scalar|null>> $multiValueParams
     */
    protected function buildMultiValueQuery(array $multiValueParams): string
    {
        $parts = [];

        foreach ($multiValueParams as $paramName => $paramValues) {
            foreach ($paramValues as $paramValue) {
                $parts[] = urlencode($paramName) . '=' . urlencode($this->convertValueToString($paramValue));
            }
        }

        return implode('&', $parts);
    }

    protected function convertValueToString(mixed $value): string
    {
        return match (gettype($value)) {
            'boolean' => $value ? 'true' : 'false',
            'NULL'    => 'null',
            'string'  => $value,
            default   => strval($value)
        };
    }
}
