<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Internal = 'internal';
    case ThirdParty = 'third_party';
}
