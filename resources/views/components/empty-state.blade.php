{{-- An empty list says what would be here and offers the next step: a link from action + href, or any markup in the action slot. `icon` picks the picture above the text (an empty tray by default), `action-icon` one on the link. --}}
@props(['action' => null, 'href' => null, 'icon' => 'inbox', 'actionIcon' => null])
<div {{ $attributes->class(['rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600']) }} data-empty-state>
    @if ($icon)<x-icon :name="$icon" class="mx-auto mb-2 h-8 w-8 text-gray-500" />@endif
    <p>{{ $slot }}</p>
    @if ($href)
        <a href="{{ $href }}" class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-brand-500 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-600">@if ($actionIcon)<x-icon :name="$actionIcon" class="h-4 w-4" />@endif{{ $action }}</a>
    @elseif (isset($action) && ! is_string($action))
        <div class="mt-3">{{ $action }}</div>
    @endif
</div>
