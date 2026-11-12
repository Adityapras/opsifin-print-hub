<?php

namespace App\Domain\Sources\Enums;

enum SourceIdentityStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Mismatch = 'mismatch';
    case Unavailable = 'unavailable';
}
