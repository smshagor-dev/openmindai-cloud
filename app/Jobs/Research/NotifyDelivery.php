<?php

namespace App\Jobs\Research;

use App\Models\Research\Delivery;
use App\Models\User;
use App\Services\Research\Access;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotifyDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $lock = Cache::lock('research:delivery-push:'.$this->deliveryId, 100);
        if (! $lock->get()) {
            return;
        }
        try {
            $d = Delivery::find($this->deliveryId);
            if (! $d || $d->notified_at || ! in_array($d->status, ['completed', 'partial', 'failed'])) {
                return;
            }
            if (! config('webpush.public_key') || ! config('webpush.private_key')) {
                return;
            }
            $user = User::find($d->actor_id);
            if (! $user?->is_active) {
                return;
            }
            try {
                app(Access::class)->project($d->project_id, $user->id);
            } catch (\Throwable) {
                return;
            }
            $push = new WebPush(['VAPID' => ['subject' => config('webpush.subject'), 'publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key')]], ['TTL' => 86400, 'urgency' => 'normal'], 15, ['allow_redirects' => false]);
            $body = $d->status === 'completed' ? 'Your research files are ready to download.' : ($d->status === 'partial' ? 'Some research files are ready. Open downloads to retry the remaining formats.' : 'Research file generation needs attention. Open downloads to retry.');
            $receipts = $d->push_receipts ?? [];
            foreach (DB::table('web_push_subscriptions')->where('user_id', $user->id)->get() as $s) {
                if (in_array($s->endpoint_hash, $receipts, true)) {
                    continue;
                }
                $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => decrypt($s->public_key), 'authToken' => decrypt($s->auth_token), 'contentEncoding' => 'aes128gcm']), json_encode(['body' => $body, 'url' => '/research-studio?project='.$d->project_id.'&view=Export', 'tag' => 'research-delivery-'.$d->id]));
            }
            $failed = false;
            foreach ($push->flush() as $report) {
                $endpointHash = hash('sha256', (string) $report->getRequest()->getUri());
                if ($report->isSuccess() || $report->isSubscriptionExpired()) {
                    $receipts[] = $endpointHash;
                    $d->update(['push_receipts' => array_values(array_unique($receipts))]);
                }
                if ($report->isSubscriptionExpired()) {
                    DB::table('web_push_subscriptions')->where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $report->getRequest()->getUri()->__toString()))->delete();
                } elseif (! $report->isSuccess()) {
                    $failed = true;
                }
            }
            if ($failed) {
                $this->release(60);

                return;
            }
            if (! $failed) {
                $d->update(['notified_at' => now()]);
            }
        } finally {
            $lock->release();
        }
    }
}
