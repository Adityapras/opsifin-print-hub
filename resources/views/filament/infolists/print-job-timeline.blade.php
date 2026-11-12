@php
    $eventCount = $events->count();
    $latestEvent = $events->first();
@endphp

<div class="job-history" data-testid="job-timeline-scroll">
    <style>
        .job-history {
            --history-line: rgba(120, 113, 108, .22);
            --history-muted: #78716c;
            --history-surface: #fafaf9;
            --history-ink: #292524;
        }
        .dark .job-history {
            --history-line: rgba(214, 211, 209, .16);
            --history-muted: #a8a29e;
            --history-surface: #1c1917;
            --history-ink: #f5f5f4;
        }
        .job-history-summary {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: .8rem;
            padding: .75rem .85rem;
            border: 1px solid var(--history-line);
            border-radius: .8rem;
            background: var(--history-surface);
        }
        .job-history-summary strong { color: var(--history-ink); font-size: .86rem; }
        .job-history-summary span { color: var(--history-muted); font-size: .75rem; }
        .job-history-scroll {
            max-height: 34rem;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: .15rem .55rem .15rem .2rem;
            scrollbar-width: thin;
            scrollbar-color: #d97706 transparent;
        }
        .job-event {
            position: relative;
            display: grid;
            grid-template-columns: 1.1rem minmax(0, 1fr);
            gap: .75rem;
            padding-bottom: .9rem;
        }
        .job-event:not(:last-child)::before {
            position: absolute;
            left: .48rem;
            top: 1rem;
            bottom: -.1rem;
            width: 1px;
            background: var(--history-line);
            content: "";
        }
        .job-event-dot {
            position: relative;
            z-index: 1;
            width: 1rem;
            height: 1rem;
            margin-top: .23rem;
            border: 3px solid var(--history-surface);
            border-radius: 999px;
            background: #78716c;
            box-shadow: 0 0 0 1px var(--history-line);
        }
        .job-event-dot.is-success { background: #16a34a; }
        .job-event-dot.is-danger { background: #dc2626; }
        .job-event-dot.is-warning { background: #d97706; }
        .job-event-dot.is-info { background: #0284c7; }
        .job-event-card {
            min-width: 0;
            padding: .72rem .8rem;
            border: 1px solid var(--history-line);
            border-radius: .72rem;
            background: var(--history-surface);
        }
        .job-event-head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: .35rem .8rem;
        }
        .job-event-title {
            color: var(--history-ink);
            font-size: .82rem;
            font-weight: 800;
            text-transform: capitalize;
        }
        .job-event-time {
            color: var(--history-muted);
            font: 600 .69rem/1.3 ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .job-event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
            margin-top: .42rem;
        }
        .job-event-chip {
            padding: .2rem .42rem;
            border: 1px solid var(--history-line);
            border-radius: 999px;
            color: var(--history-muted);
            font-size: .66rem;
            font-weight: 700;
        }
        .job-event-reason {
            margin-top: .55rem;
            padding: .5rem .6rem;
            border-left: 3px solid #d97706;
            color: var(--history-muted);
            font-size: .76rem;
            line-height: 1.5;
        }
        .job-event-details { margin-top: .5rem; }
        .job-event-details summary {
            cursor: pointer;
            color: var(--history-muted);
            font-size: .7rem;
            font-weight: 700;
        }
        .job-event-details pre {
            margin-top: .45rem;
            overflow-x: auto;
            padding: .6rem;
            border-radius: .5rem;
            background: color-mix(in srgb, var(--history-ink) 6%, transparent);
            color: var(--history-muted);
            font: .68rem/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .job-history-empty {
            padding: 2rem;
            border: 1px dashed var(--history-line);
            border-radius: .8rem;
            color: var(--history-muted);
            text-align: center;
            font-size: .8rem;
        }
    </style>

    <div class="job-history-summary">
        <div>
            <strong>{{ number_format($eventCount) }} timeline {{ str('event')->plural($eventCount) }}</strong>
            <span> · newest first</span>
        </div>
        @if ($latestEvent)
            <span>Latest {{ $latestEvent->recorded_at->timezone($timezone)->format('d M Y, H:i:s') }}</span>
        @endif
    </div>

    @if ($events->isEmpty())
        <div class="job-history-empty">No timeline events have been recorded.</div>
    @else
        <div class="job-history-scroll" tabindex="0" aria-label="Scrollable print job timeline">
            @foreach ($events as $event)
                @php
                    $tone = match (true) {
                        in_array($event->type, ['completed', 'printed_unverified', 'callback_succeeded'], true) => 'is-success',
                        in_array($event->type, ['failed', 'print_error', 'callback_failed'], true) => 'is-danger',
                        in_array($event->type, ['queued', 'duplicate_ignored', 'reprint_requested'], true) => 'is-warning',
                        in_array($event->type, ['claimed', 'spooled', 'download_started'], true) => 'is-info',
                        default => '',
                    };
                    $data = is_array($event->data) ? $event->data : [];
                    $hasDetails = $data !== [];
                @endphp
                <article class="job-event">
                    <span class="job-event-dot {{ $tone }}" aria-hidden="true"></span>
                    <div class="job-event-card">
                        <div class="job-event-head">
                            <div class="job-event-title">{{ str($event->type)->replace('_', ' ') }}</div>
                            <time class="job-event-time" datetime="{{ $event->recorded_at->toIso8601String() }}">
                                {{ $event->recorded_at->timezone($timezone)->format('d M Y, H:i:s') }}
                            </time>
                        </div>
                        <div class="job-event-meta">
                            <span class="job-event-chip">Actor: {{ $event->actor_type }}</span>
                            @if ($event->from_state || $event->to_state)
                                <span class="job-event-chip">{{ $event->from_state ?: 'start' }} → {{ $event->to_state ?: 'unchanged' }}</span>
                            @endif
                            @if (isset($data['mode']))
                                <span class="job-event-chip">{{ str($data['mode'])->replace('_', ' ') }}</span>
                            @endif
                        </div>
                        @if (isset($data['reason']))
                            <div class="job-event-reason"><strong>Reason:</strong> {{ $data['reason'] }}</div>
                        @endif
                        @if ($hasDetails)
                            <details class="job-event-details">
                                <summary>Technical details</summary>
                                <pre>{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
