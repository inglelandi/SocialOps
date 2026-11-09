<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** The client's approval page, reachable only through the review's signed link. */
class PortalController extends Controller
{
    public function show(Review $review): View
    {
        return view('portal.show', [
            'review' => $review,
            'posts' => $review->posts()->with(['variants', 'assets'])->get(),
            'name' => session('reviewer_name'),
        ]);
    }

    public function decide(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'post_id' => ['required', 'alpha_num'], // a post id or "all"
            'decision' => ['required', 'in:approved,changes_requested'],
            'comment' => ['nullable', 'string', 'max:2000', 'required_if:decision,changes_requested'],
            'name' => ['required', 'string', 'max:100'],
        ], ['comment.required_if' => __('Tell us what to change.')]);

        session(['reviewer_name' => $data['name']]);
        $pending = $review->pending()->get();
        $posts = $data['post_id'] === 'all' ? $pending : $pending->where('id', (int) $data['post_id']);
        if ($posts->isEmpty()) {
            // a page left open, a second approver, a double tap, a post sent again in a newer link or taken back: nothing to decide here
            return redirect($request->fullUrl())->with('notice', __('This post is no longer waiting for your answer on this page: it was answered already, or we sent it to you again in a newer link. Nothing was changed.'));
        }
        foreach ($posts as $post) {
            $review->decide($post, $data['decision'], $data['comment'] ?? null, $data['name']);
        }

        // GET and POST share the signed URL, so the redirect keeps the signature
        return redirect($request->fullUrl())->with('status', $data['decision']);
    }

    public function asset(Review $review, Asset $asset): Response
    {
        abort_unless($review->posts()->whereHas('assets', fn ($q) => $q->whereKey($asset->id))->exists(), 404);

        return $asset->inline();
    }
}
