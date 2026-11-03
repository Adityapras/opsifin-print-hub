<?php

namespace App\Domain\Jobs\Enums;

enum AgentJobEventType: string
{
    case DownloadStarted = 'download_started';
    case DownloadCompleted = 'download_completed';
    case Spooled = 'spooled';
    case SpoolerStatus = 'spooler_status';
    case Completed = 'completed';
    case PrintedUnverified = 'printed_unverified';
    case PrintError = 'print_error';
}
