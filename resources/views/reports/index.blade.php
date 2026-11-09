<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('reports') }}</h2>
    </x-slot>

    @php($about = __('Every trend brief, content plan and monthly report for this client, newest first.').(config('features.publishing') ? ' '.__('Monthly reports are built on the 1st for last month; ask for another month here.') : ''))
    @php($noReports = __('No reports yet. "Monthly report" above writes the first one from last month\'s numbers; "Plan with Claude" on the client page files its trend brief and content plan here.'))
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-section icon="chart-bar" :title="__('Reports')" :description="$about" flush class="text-sm">
                @if (session('status') === 'report-queued')
                    <p class="px-6 py-3 text-green-600">{{ __('Report queued. It appears below in a few minutes.') }}</p>
                @endif
                @if (config('features.publishing'))
                <div class="space-y-3 px-6 py-4">
                    <form method="POST" action="{{ route('reports.monthly', $client) }}" class="flex items-end gap-3">
                        @csrf
                        <div>
                            <x-input-label for="month" :value="__('Month')" />
                            <x-text-input id="month" name="month" type="month" max="{{ now()->subMonth()->format('Y-m') }}" class="mt-1 block" :value="old('month', now()->subMonth()->format('Y-m'))" />
                        </div>
                        <x-primary-button><x-icon name="chart-bar" class="h-4 w-4" />{{ __('Monthly report') }}</x-primary-button>
                        <x-input-error :messages="$errors->get('month')" />
                    </form>
                    @if (\App\Reporting\ListeningReport::watches($client))
                        <form method="POST" action="{{ route('reports.listening', $client) }}">
                            @csrf
                            <x-secondary-button type="submit"><x-icon name="megaphone" class="h-4 w-4" />{{ __('Listening report now') }}</x-secondary-button>
                            <span class="ml-2 text-gray-600">{{ __('Runs every Monday for the hashtags and competitors in the profile.') }}</span>
                        </form>
                    @else
                        <p class="text-gray-600">{{ __('Add hashtags_to_watch or competitors under research in the profile to get a weekly listening report.') }}</p>
                    @endif
                </div>
                @endif
                <table class="w-full">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600"><tr><th class="px-6 py-2 font-medium">{{ __('Date') }}</th><th class="px-6 py-2 font-medium">{{ __('Kind') }}</th><th class="px-6 py-2 font-medium">{{ __('Title') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($reports as $report)
                            <tr>
                                <td class="whitespace-nowrap px-6 py-2.5 text-gray-600">{{ $report->created_at->format('d-m-Y H:i') }}</td>
                                <td class="px-6 py-2.5">{{ __(\App\Models\Report::KINDS[$report->kind] ?? $report->kind) }}</td>
                                <td class="px-6 py-2.5"><a href="{{ route('reports.show', $report) }}" class="font-medium text-gray-900 hover:text-brand-600">{{ $report->title }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-4"><x-empty-state icon="chart-bar" :action="__('Plan with Claude')" :href="route('clients.edit', $client)">{{ $noReports }}</x-empty-state></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-section>
        </div>
    </div>
</x-app-layout>
