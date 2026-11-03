<?php

namespace App\Domain\Jobs\Enums;

enum PrintJobState: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Printing = 'printing';
    case Printed = 'printed';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
