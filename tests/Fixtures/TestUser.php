<?php

namespace Gsebastiao\LaravelAuthz\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Model mínimo só para os testes — representa a tabela 'users' (ou o que
 * authz.tables.user apontar) o suficiente para actingAs() funcionar e
 * Auth::id() resolver de verdade, sem depender de um model de aplicação
 * real (o pacote não assume nenhum).
 */
class TestUser extends Authenticatable
{
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = true;
}
