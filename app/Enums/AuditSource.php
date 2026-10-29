<?php

namespace App\Enums;

enum AuditSource: string
{
    case Web = 'web';
    case Api = 'api';
    case Mcp = 'mcp';
    case Webhook = 'webhook';
    case System = 'system';
}
