<?php

namespace App\Enums;

enum RootCauseCategory: string
{
    case Code = 'code';
    case Configuration = 'configuration';
    case Infrastructure = 'infrastructure';
    case Capacity = 'capacity';
    case Process = 'process';
    case ThirdParty = 'third_party';
    case HumanError = 'human_error';
    case Security = 'security';
}
