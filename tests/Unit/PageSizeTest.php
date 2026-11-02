<?php

namespace Tests\Unit;

use App\Support\PageSize;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PageSizeTest extends TestCase
{
    public function test_it_resolves_supported_page_sizes(): void
    {
        $page = PageSize::resolve(Request::create('/', 'GET', ['per_page' => '50']), 20);
        $this->assertSame('50', $page['per_page']);
        $this->assertSame(50, $page['limit']);
    }

    public function test_it_resolves_all_as_a_large_limit(): void
    {
        $page = PageSize::resolve(Request::create('/', 'GET', ['per_page' => 'all']), 10);
        $this->assertSame('all', $page['per_page']);
        $this->assertSame(100000, $page['limit']);
    }

    public function test_it_falls_back_for_invalid_values(): void
    {
        $page = PageSize::resolve(Request::create('/', 'GET', ['per_page' => '999']), 20);
        $this->assertSame('20', $page['per_page']);
        $this->assertSame(20, $page['limit']);
    }
}
