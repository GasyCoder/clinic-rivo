<?php

namespace App\Enums;

enum IdentityDocumentType: string
{
    case Cin = 'CIN';
    case Passport = 'PASSPORT';
}
