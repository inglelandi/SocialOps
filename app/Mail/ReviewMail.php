<?php

namespace App\Mail;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** One template for the whole review loop: request and reminder go to the client, overdue and completed to the agency. */
class ReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Review $review, public string $kind)
    {
        // completed and overdue go to the colleague who sent the review, the rest to the client's approvers; the app's own default,
        // not config('app.locale'): the client's answer that completes a review runs in the client's language (SetLocale)
        $this->locale(in_array($kind, ['completed', 'overdue']) ? ($review->creator?->locale ?? config('app.default_locale', config('app.locale'))) : $review->client->uiLocale());
    }

    /** How many posts the mail is about: the request and the reminder ask only for the ones still waiting for an answer. */
    public function postCount(): int
    {
        return in_array($this->kind, ['request', 'reminder'], true) ? $this->review->pending()->count() : $this->review->posts()->count();
    }

    public function envelope(): Envelope
    {
        $vars = ['client' => $this->review->client->name, 'n' => $this->postCount()];

        return new Envelope(subject: match ($this->kind) {
            'request' => __(':client: :n posts to approve', $vars),
            'reminder' => __('Reminder: :n posts for :client are waiting for your approval', $vars),
            'overdue' => __(':client: approval overdue', $vars),
            'completed' => __(':client: review finished', $vars),
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.review');
    }
}
