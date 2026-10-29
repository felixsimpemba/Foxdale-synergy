<?php

namespace App\Enums;

enum FixType: string
{
    case CodeChange = 'code_change';
    case ConfigChange = 'config_change';
    case Infrastructure = 'infrastructure';
    case DataFix = 'data_fix';
    case Vendor = 'vendor';
    case Rollback = 'rollback';
    case NoAction = 'no_action';
}
