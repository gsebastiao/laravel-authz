<?php

namespace Gsebastiao\LaravelAuthz\Tests\Fixtures;

use Gsebastiao\LaravelAuthz\Traits\HasAuthz;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Usuário de teste com o trait "tudo em um". */
class AuthzUser extends Authenticatable
{
    use HasAuthz;

    protected $table = 'users';
    protected $guarded = [];
}
