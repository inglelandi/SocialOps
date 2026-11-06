<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateListeningReport;
use App\Jobs\GenerateMonthlyReport;
use App\Models\Client;
use App\Models\Report;
use App\Reporting\ListeningReport;
use App\Reporting\MonthlyReport;
use App\Reporting\TopicsDocx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Client $client): View
    {
        return view('reports.index', ['client' => $client, 'reports' => $client->reports()->latest()->get()]);
    }

    public function monthly(Request $request, Client $client): RedirectResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m', 'before:'.now()->format('Y-m')]])['month'];
        GenerateMonthlyReport::dispatch($client, $month);

        return redirect()->route('clients.reports', $client)->with('status', 'report-queued');
    }

    public function listening(Client $client): RedirectResponse
    {
        abort_unless(ListeningReport::watches($client), 422, 'Add hashtags_to_watch or competitors to the research section of the profile first.');
        GenerateListeningReport::dispatch($client);

        return redirect()->route('clients.reports', $client)->with('status', 'report-queued');
    }

    public function show(Report $report): View
    {
        return view('reports.show', [
            'report' => $report,
            'html' => Str::markdown($report->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }

    /** "Email to client": the report goes to the approvers with a signed link and the numbers as CSV. */
    public function send(Report $report, MonthlyReport $reports): RedirectResponse
    {
        abort_unless($report->kind === 'monthly', 404);
        try {
            $n = $reports->mail($report);
        } catch (\Throwable $e) { // a mail server problem: said on the page, not an error page
            report($e);

            return redirect()->route('reports.show', $report)->withErrors(['send' => __('The e-mail could not be sent (:error). Check the mail settings on the Settings page.', ['error' => $e->getMessage()])]);
        }

        return $n
            ? redirect()->route('reports.show', $report)->with('status', __('Report mailed to :n approvers', ['n' => $n]))
            : redirect()->route('reports.show', $report)->withErrors(['send' => __('The profile has no approver with an email address.')]);
    }

    /** The report as the client sees it through the signed link in the mail. */
    public function client(Report $report): View
    {
        return view('reports.client', [
            'report' => $report,
            'html' => Str::markdown($report->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }

    public function download(Report $report): Response
    {
        return response($report->body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($report->title).'.md"',
        ]);
    }

    /** "Download Word": the θεματολογία (topic plan) of a content plan, for the client. */
    public function word(Report $report): StreamedResponse
    {
        abort_unless($report->kind === 'plan' && isset($report->meta['posts']), 404);

        return response()->streamDownload(fn () => print TopicsDocx::build($report), TopicsDocx::filename($report), ['Content-Type' => TopicsDocx::MIME]);
    }
}
