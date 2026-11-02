<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        $this->session([
            'password_hash_'.config('auth.defaults.guard', 'web') => $user->getAuthPassword(),
        ]);

        return $this;
    }
}
