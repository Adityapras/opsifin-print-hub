<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

final class TenantWorkspaceGuide extends Widget
{
    protected string $view = 'filament.widgets.tenant-workspace-guide';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * @return array{title: string, description: string, configure: string, monitor: string}
     */
    public function getGuide(): array
    {
        $route = (string) request()->route()?->getName();

        return match (true) {
            str_contains($route, 'source-connections') => [
                'title' => 'Source Connections',
                'description' => 'Securely connect this tenant to its matching Opsifin Main instance. Identity verification must pass before the connection can pull jobs or receive status updates.',
                'configure' => 'Base URL, Basic Auth credentials, API version, pull interval, and active hours.',
                'monitor' => 'Identity status, last successful request, connection errors, and whether the source is active.',
            ],
            str_contains($route, '.sites.') => [
                'title' => 'Sites',
                'description' => 'Represent the physical offices or branches where Print Agent PCs and printers operate.',
                'configure' => 'Site name, address, PIC, phone number, and local timezone.',
                'monitor' => 'Which Agent PCs and printers belong to each physical location.',
            ],
            str_contains($route, '.agents.') => [
                'title' => 'Agents',
                'description' => 'Pair and monitor the Windows PCs that securely execute print jobs for this tenant.',
                'configure' => 'Create a one-time pairing code for the correct site, then enroll the Agent from that PC.',
                'monitor' => 'Heartbeat presence, Agent version, simulation or production mode, commands, and updates.',
            ],
            str_contains($route, '.printers.') => [
                'title' => 'Printers',
                'description' => 'Review printers discovered by each Agent and explicitly enable only destinations approved for production printing.',
                'configure' => 'Alias, active state, pause state, and print settings for the selected Windows printer.',
                'monitor' => 'Effective availability, raw spooler report, queue length, Agent presence, and last heartbeat.',
            ],
            str_contains($route, 'printer-bindings') => [
                'title' => 'Printer Bindings',
                'description' => 'Create the safety-critical route from one Opsifin printer identity to one exact Windows printer.',
                'configure' => 'Source connection, PrinterName, Identifier, version, destination printer, and active state.',
                'monitor' => 'Last pull, pull errors, status sent to Opsifin, and status-sync failures.',
            ],
            str_contains($route, 'print-jobs') => [
                'title' => 'Print Jobs',
                'description' => 'Trace every invoice from source ingestion to Agent claim, spool, completion, and controlled recovery.',
                'configure' => 'No routine setup. Operators may retry or refetch a failed job with a mandatory reason.',
                'monitor' => 'Current state, destination, document, attempt history, event timeline, and failure reason.',
            ],
            str_contains($route, 'callback-outboxes') => [
                'title' => 'Callbacks',
                'description' => 'Track delivery of terminal print results from the Hub back to Opsifin Main.',
                'configure' => 'No routine setup; the Hub creates callbacks automatically for completed job runs.',
                'monitor' => 'Pending, sent, failed, retry schedule, HTTP response, and delivery expiry.',
            ],
            str_contains($route, 'audit-logs') => [
                'title' => 'Audit Log',
                'description' => 'Review the append-only record of configuration changes and sensitive administrative operations.',
                'configure' => 'No setup. Entries are written automatically with actor, time, subject, and redacted changes.',
                'monitor' => 'Who changed tenant routing or configuration, what changed, and when it happened.',
            ],
            str_ends_with($route, '.identity') => [
                'title' => 'Integration Identity',
                'description' => 'This permanent Hub-issued UUID prevents a Source Connection from being activated against the wrong client instance.',
                'configure' => 'Copy the Integration ID into the matching Opsifin Main environment, then verify it from Source Connections.',
                'monitor' => 'The Hub identity remains unchanged through reconfiguration and tenant reinitialization.',
            ],
            default => [
                'title' => 'Tenant Overview',
                'description' => 'Review this tenant as one isolated operational boundary before changing routing, connectivity, or fleet configuration.',
                'configure' => 'Use Tenant Setup Guide for the required end-to-end sequence and readiness checks.',
                'monitor' => 'Tenant status, estate totals, topology, and the relationships between sites, Agents, and printers.',
            ],
        };
    }
}
