<x-filament-panels::page>
    @php
        $steps = $this->getSetupSteps();
        $ready = collect($steps)->where('ready', true)->count();
        $percentage = (int) round(($ready / count($steps)) * 100);
    @endphp

    <x-filament::section icon="heroicon-o-map">
        <x-slot name="heading">End-to-end tenant setup</x-slot>
        <x-slot name="description">
            Follow this sequence from top to bottom. Each step creates one safety boundary required before production printing.
        </x-slot>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div>
                <div class="mb-2 flex items-center justify-between gap-4 text-sm">
                    <span class="font-semibold text-gray-950 dark:text-white">{{ $ready }} of {{ count($steps) }} checks ready</span>
                    <span class="font-medium text-primary-600 dark:text-primary-400">{{ $percentage }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $percentage }}%"></div>
                </div>
            </div>
            <div class="rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm leading-5 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200">
                Keep the tenant Suspended until steps 1–7 are verified. Activate production only after the controlled E2E test succeeds.
            </div>
        </div>
    </x-filament::section>

    <div class="tenant-setup-flow" role="list" aria-label="Tenant setup flow">
        @foreach ($steps as $step)
            <article class="tenant-setup-step" role="listitem">
                <div class="tenant-setup-rail" aria-hidden="true">
                    <div class="tenant-setup-number {{ $step['ready'] ? 'is-ready' : '' }}">{{ $step['number'] }}</div>
                    @if (! $loop->last)
                        <div class="tenant-setup-line {{ $step['ready'] ? 'is-ready' : '' }}"></div>
                    @endif
                </div>

                <div class="tenant-setup-card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $step['title'] }}</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $step['description'] }}</p>
                        </div>
                        <x-filament::badge :color="$step['ready'] ? 'success' : 'warning'">
                            {{ $step['ready'] ? 'Ready' : 'Action required' }}
                        </x-filament::badge>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-primary-500" />
                            <span>{{ $step['result'] }}</span>
                        </div>
                        <x-filament::button tag="a" :href="$step['url']" size="sm" color="gray" icon="heroicon-o-arrow-right">
                            Open step
                        </x-filament::button>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <style>
        .tenant-setup-flow { display: grid; gap: 0; }
        .tenant-setup-step { display: grid; grid-template-columns: 2.75rem minmax(0, 1fr); gap: 1rem; }
        .tenant-setup-rail { display: flex; min-height: 100%; flex-direction: column; align-items: center; }
        .tenant-setup-number { display: grid; width: 2.5rem; height: 2.5rem; flex: 0 0 auto; place-items: center; border: 2px solid rgb(209 213 219); border-radius: 999px; background: white; color: rgb(75 85 99); font-weight: 700; }
        .dark .tenant-setup-number { border-color: rgb(75 85 99); background: rgb(17 24 39); color: rgb(209 213 219); }
        .tenant-setup-number.is-ready { border-color: rgb(34 197 94); background: rgb(240 253 244); color: rgb(21 128 61); }
        .dark .tenant-setup-number.is-ready { border-color: rgb(34 197 94); background: rgb(20 83 45 / .35); color: rgb(134 239 172); }
        .tenant-setup-line { width: 2px; min-height: 2rem; flex: 1 1 auto; background: rgb(229 231 235); }
        .dark .tenant-setup-line { background: rgb(55 65 81); }
        .tenant-setup-line.is-ready { background: rgb(34 197 94); }
        .tenant-setup-card { margin-bottom: 1rem; border: 1px solid rgb(229 231 235); border-radius: .875rem; background: white; padding: 1.25rem; box-shadow: 0 1px 2px rgb(0 0 0 / .04); }
        .dark .tenant-setup-card { border-color: rgb(255 255 255 / .1); background: rgb(255 255 255 / .04); }
        @media (max-width: 640px) {
            .tenant-setup-step { grid-template-columns: 2.25rem minmax(0, 1fr); gap: .75rem; }
            .tenant-setup-number { width: 2.1rem; height: 2.1rem; }
            .tenant-setup-card { padding: 1rem; }
        }
    </style>
</x-filament-panels::page>
