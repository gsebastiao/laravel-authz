<?php

namespace Gsebastiao\LaravelAuthz\Traits;

/**
 * Tudo em um: adicione `use HasAuthz;` no seu model User e ganhe
 * os métodos de grupos (HasRoles) e de permissões (HasPermissions).
 */
trait HasAuthz
{
    use HasRoles;
    use HasPermissions;
}
