<?php

namespace App\Jobs;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Listings\PublishListing;
use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Http\Requests\Api\Listings\ListingRequest;
use App\Indexing\IndexSync;
use App\Jobs\Concerns\WaitsOnce;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Actor;
use App\Support\ArabicText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * The AI wording of a description (clean_listing, queue `ai`). The suggestion waits for the advertiser's decision
 * («بانتظار مراجعتك»). If the AI cannot answer, the listing is published as written (text_review = auto): the AI
 * never stands between an advertiser and his publication. An identical suggestion needs no decision.
 *
 * One waiting per listing, until it starts (WaitsOnce): the queue has two workers, and two saves close together
 * would otherwise ask the AI twice about the same text.
 */
class CleanListingText implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, WaitsOnce;

    public function __construct(public readonly int $listingId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->listingId;
    }

    public function handle(AiGateway $ai, PublishListing $publish, ChangeListingStatus $status, IndexSync $index): void
    {
        $listing = Listing::query()->with('customer')->find($this->listingId);
        if ($listing === null || $listing->text_review !== TextReview::Pending) {
            return;                                                   // deleted, or already decided meanwhile
        }

        $sent = self::asked($listing);
        $result = $ai->call(AiAgent::CleanListing, $sent,
            check: function (array $output) use ($listing): ?array {
                $text = trim((string) ($output['desc_clean'] ?? ''));
                $features = array_values(array_filter((array) ($output['features'] ?? []), 'is_string'));

                return $text === '' ? null : ['desc_clean' => $text, 'features' => self::said($features, (string) $listing->desc_raw)];
            }, customer: $listing->customer, cache: false, known: $listing->knownNames());

        DB::transaction(function () use ($sent, $result, $publish, $status, $index) {
            // the listing again, held: an edit while the AI answered makes this answer another text's — its own
            // wording (sent by that edit) answers it
            $listing = Listing::query()->with('customer')->lockForUpdate()->find($this->listingId);
            if ($listing === null || $listing->text_review !== TextReview::Pending || self::asked($listing) !== $sent) {
                return;
            }
            $suggested = $result->output['desc_clean'] ?? null;
            $same = $suggested !== null && ArabicText::normalize($suggested) === ArabicText::normalize($listing->desc_raw);

            if ($suggested === null || $same) {
                $listing->forceFill(['text_review' => $same ? TextReview::Approved : TextReview::Auto, 'text_reviewed_at' => now()])->save();
                $listing->status === ListingStatus::Indexing ? $publish($listing, Actor::system()) : $index->listing($listing);

                return;
            }

            $listing->forceFill([
                'desc_suggested' => $suggested,
                'text_review' => TextReview::Ready,
                'features' => $listing->features ?: ($result->output['features'] ?: null),
            ])->save();
            if ($listing->status === ListingStatus::Indexing) {
                $status($listing, ListingStatus::PendingReview, Actor::system());
            }
            $listing->customer->notify(Notices::wordingReady($listing));
        });
    }

    /**
     * The features the advertiser's own text states: each word of the feature is in it (Arabic letters folded, «ال»
     * aside), and not right after a negation («بدون مصعد» is no «مصعد»). One the model added is never kept: the
     * advertiser's approval of the wording is not a check of its features.
     *
     * @param  list<string>  $features
     * @return list<string>
     */
    public static function said(array $features, string $text): array
    {
        $bare = fn (string $word) => (string) preg_replace('/^(?:و?ب?ال|و)(?=\p{L}{2})/u', '', $word);
        $words = array_map($bare, explode(' ', ArabicText::normalize((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text))));
        $negated = '/(?:بدون|بلا|ما ?في(?:ه|ها)?|لا يوجد|ليس فيه|غير) +(?:\p{L}+ +)?$/u';

        return array_values(array_filter($features, function (string $feature) use ($bare, $words, $negated) {
            $wanted = array_map($bare, array_filter(explode(' ', ArabicText::normalize((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $feature)))));
            $wanted = array_values($wanted);
            if ($wanted === [] || array_diff($wanted, $words) !== []) {
                return false;
            }
            // every place the feature is said — its first word with the rest within three words — and one said
            // without a negation before it: «مجلس رجال ولا يوجد مجلس نساء» says the first and denies the second
            // (review of 21 Nov: the first «مجلس» was read for both)
            foreach (array_keys($words, $wanted[0], true) as $at) {
                $near = array_slice($words, $at + 1, 3);
                if (array_diff(array_slice($wanted, 1), $near) === []
                    && preg_match($negated, implode(' ', array_slice($words, max(0, $at - 3), min(3, $at))).' ') !== 1) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * What the AI is asked about [listing]: its text, type, place and specs.
     *
     * @return array<string, mixed>
     */
    private static function asked(Listing $listing): array
    {
        // The specs, and what the advertiser stated outside his text — the price, the deed and the bank: the text
        // is written around them, and «الأرقام الواردة في الخانات هي المعتمدة» (the agent's prompt; the lab's cases).
        $specs = array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, array_filter([
            'price' => $listing->price !== null ? (float) $listing->price : null,
            ...$listing->only(ListingRequest::SPEC_FIELDS),
            'deed' => $listing->has_deed,
            'bank' => $listing->bank_accepted,
        ], fn ($v) => $v !== null));

        return [
            'type' => $listing->property_type->value,
            'city' => $listing->city,
            'neighborhood' => $listing->neighborhood,
            'text' => $listing->desc_raw,
        ] + ($specs === [] ? [] : ['fields' => $specs]);    // an empty PHP array goes out as a JSON list; the agents expect an object
    }
}
