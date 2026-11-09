@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-sm text-red-600 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li class="flex items-start gap-1.5"><x-icon name="exclamation-circle" class="mt-0.5 h-4 w-4" /><span>{{ $message }}</span></li>
        @endforeach
    </ul>
@endif
