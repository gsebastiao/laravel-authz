<?php

namespace Gsebastiao\LaravelAuthz\Enums;

/**
 * Formato de retorno de Authorization::getEffectivePermissions().
 */
enum PermissionFormat: string
{
    case Id = 'id';
    case Permission = 'permission';
    case Both = 'both';
}
