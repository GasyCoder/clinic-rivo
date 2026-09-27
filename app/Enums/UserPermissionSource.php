<?php

namespace App\Enums;

enum UserPermissionSource: string
{
    case Manual = 'MANUAL';
    case Profile = 'PROFILE';
}
