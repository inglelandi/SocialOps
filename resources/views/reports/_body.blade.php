    <style>
        .report h1 { font-size: 1.5rem; font-weight: 600; margin: 1.5rem 0 .5rem }
        .report h2 { font-size: 1.2rem; font-weight: 600; margin: 1.25rem 0 .5rem }
        .report h3 { font-weight: 600; margin: 1rem 0 .25rem }
        .report p, .report ul, .report ol, .report blockquote { margin: .5rem 0 }
        .report ul { list-style: disc; padding-left: 1.5rem }
        .report ol { list-style: decimal; padding-left: 1.5rem }
        .report a { color: #E12D00; text-decoration: underline }
        .report blockquote { border-left: 3px solid #d4d4d4; padding-left: .75rem; color: #737373 }
        .report code { font-family: ui-monospace, monospace; font-size: .85em }
    </style>
    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-3">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-sm text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @error('send') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <div class="bg-white p-6 rounded-xl border border-gray-300 text-sm text-gray-800 report">{!! $html !!}</div>
        </div>
    </div>
