<?php

namespace KennyMgn\ProfitbaseClient\Tests\Support;

use KennyMgn\ProfitbaseClient\Support\QueryStringBuilder;
use PHPUnit\Framework\TestCase;

class QueryStringBuilderTest extends TestCase
{
    private QueryStringBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QueryStringBuilder();
    }

    public function testBuildWithSingleValueParams(): void
    {
        $query = $this->builder->build(['a' => 1, 'b' => 'test']);
        $this->assertSame('a=1&b=test', $query);
    }

    public function testBuildWithArrayParams(): void
    {
        $query = $this->builder->build(['ids[]' => [10, 20, 30]]);
        $this->assertSame('ids[]=10&ids[]=20&ids[]=30', urldecode($query));
    }

    public function testBuildWithMixedParams(): void
    {
        $query = $this->builder->build(['ids[]' => [1, 2], 'active' => true]);
        $this->assertSame('active=true&ids[]=1&ids[]=2', urldecode($query));
    }

    public function testBuildHandlesNullAndBooleanValues(): void
    {
        $query = $this->builder->build(['flag' => false, 'deleted' => null]);
        $this->assertSame('flag=false&deleted=null', $query);
    }

    public function testBuildReturnsEmptyStringForEmptyArray(): void
    {
        $query = $this->builder->build([]);
        $this->assertSame('', $query);
    }
}
