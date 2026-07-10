<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Registration is closed: single-user app, accounts created via seeder/artisan
// (DEPLOY.md C9). These tests pin the routes shut.
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_gone()
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_submission_is_gone()
    {
        $this->post('/register', [
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }
}
