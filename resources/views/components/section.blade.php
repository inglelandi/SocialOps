{{-- A titled card: the title (with an icon in a tinted tile when `icon` names one) and one line on what it is for, then the rows (x-field) or any content. --}}
@props(['title' => null, 'description' => null, 'flush' => false, 'icon' => null])
<section {{ $attributes->class(['rounded-xl border border-gray-300 bg-white']) }}>
    @if ($title)
        <header class="flex items-start gap-3 border-b border-gray-300 px-6 py-4">
            @if ($icon)
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-icon :name="$icon" class="h-5 w-5" /></span>
            @endif
            <div class="min-w-0">
                <h3 class="text-lg font-semibold text-gray-900">{{ $title }}</h3>
                @if ($description)<p class="mt-0.5 text-sm text-gray-600">{{ $description }}</p>@endif
            </div>
        </header>
    @endif
    <div class="{{ $flush ? '' : 'px-6' }} divide-y divide-gray-200">{{ $slot }}</div>
</section>
