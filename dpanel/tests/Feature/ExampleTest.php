<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_home_page_sends_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
