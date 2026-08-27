<?php

declare(strict_types=1);

it('registers a user', function () {
    visit('http://gameworld.test/register')
        ->fill('[name="name"]', 'John Doe')
        ->fill('[name="phonenumber"]', '091365478797')
        ->fill('[name="password"]', 'password123')
        ->fill('[name="password_confirmation"]', 'password123')
        ->click('Register')
        ->assertPathIs('/');
});
