<?php

namespace App\Enums;

enum UserType: string
{
    case SUPERADMIN = 'SUPERADMIN';
    case ADMIN = 'ADMIN';
    case GESTOR = 'GESTOR';
    case PROMOTOR = 'PROMOTOR';
}
