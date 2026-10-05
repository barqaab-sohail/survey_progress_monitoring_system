<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLegalPagesTest extends TestCase
{
    public function test_legal_pages_are_public_and_link_to_each_other(): void
    {
        $this->get('/privacy-policy')->assertOk()
            ->assertSee('Privacy Policy')->assertSee('drive.file')
            ->assertSee('Limited Use')->assertSee('sohail.afzal08@gmail.com')
            ->assertSee(route('terms-of-service'));
        $this->get('/terms-of-service')->assertOk()
            ->assertSee('Terms of Service')->assertSee(route('privacy-policy'));
        $this->assertGuest();
    }
}
