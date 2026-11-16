@php($guide = $this->getGuide())

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-information-circle">
        <x-slot name="heading">{{ $guide['title'] }}</x-slot>
        <x-slot name="description">{{ $guide['description'] }}</x-slot>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">What to configure</div>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $guide['configure'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">What to monitor</div>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $guide['monitor'] }}</p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
