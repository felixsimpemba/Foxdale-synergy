<?php

namespace App\Enums;

enum DocumentType: string
{
    case Evidence = 'evidence';
    case Runbook = 'runbook';
    case FixDoc = 'fix_doc';
    case ConfigChange = 'config_change';
    case Postmortem = 'postmortem';
    case Other = 'other';
}
