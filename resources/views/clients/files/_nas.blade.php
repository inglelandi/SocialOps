{{-- the client's folder on the office NAS: named in the profile, opened by Claude itself through the Inglelandi NAS connector; nothing from it is copied here (D29) --}}
<x-section icon="server-stack" x-show="tab === 'nas'" x-cloak :title="__('Office NAS folder')" :description="__('The client\'s folder on the office NAS. Claude opens it through the Inglelandi NAS connector when it plans or writes posts for this client: past posts, designs and documents. Nothing is copied here.')">
    <div class="py-5 text-sm">
        @if ($nasFolder = \App\Planning\NasFolder::path($client))
            <p class="font-mono text-gray-900">Clients/{{ $nasFolder }}</p>
            <p class="mt-2 text-gray-600">{{ __('For the hourly Claude worker, the Inglelandi NAS connector has to be ticked on its scheduled task.') }}</p>
        @else
            <p class="text-gray-600">{{ __('No folder named yet.') }}</p>
        @endif
        <a href="{{ route('clients.edit', $client) }}" class="mt-3 inline-flex items-center gap-1 font-medium text-gray-900 hover:text-brand-600"><x-icon name="pencil-square" class="h-4 w-4" />{{ __('Change it in the profile (Research)') }}</a>
    </div>
</x-section>
