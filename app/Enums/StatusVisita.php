<?php

namespace App\Enums;

enum StatusVisita: string
{
    case ABERTA = 'ABERTA';
    case FINALIZADA = 'FINALIZADA';
    case CANCELADA = 'CANCELADA';
}
