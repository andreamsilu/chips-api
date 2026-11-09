<?php

namespace App\Jobs;

use App\Modules\Notifications\NextSmsGateway;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class SendOrderSms implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 25;

    public int $uniqueFor = 120;

    public array $backoff = [60, 120];

    public function __construct(public string $id) {}

    public function uniqueId(): string
    {
        return $this->id;
    }

    /**
     * Execute the job.
     */
    public function handle(NextSmsGateway $gateway): void
    {
        if (! config('services.nextsms.enabled')) {
            return;
        }
        $delivery = DB::table('sms_deliveries')->where('id', $this->id)->first();
        if (! $delivery || $delivery->status !== 'queued') {
            return;
        }
        $gateway->requireConfiguration();
        $recipient = Crypt::decryptString($delivery->recipient);
        $body = Crypt::decryptString($delivery->body);
        $claimed = DB::table('sms_deliveries')->where('id', $this->id)->where('status', 'queued')
            ->where('attempts', $delivery->attempts)
            ->update(['status' => 'submitting', 'submitted_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $result = $gateway->send($recipient, $body, $this->id);
        if ($result['status'] === 'queued' && $delivery->attempts >= 4) {
            $result['status'] = 'rejected';
        }
        DB::table('sms_deliveries')->where('id', $this->id)->update($result + ['updated_at' => now()]);
    }
}
