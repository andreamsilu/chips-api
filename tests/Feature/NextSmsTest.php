<?php

namespace Tests\Feature;

use App\Jobs\SendOrderSms;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Modules\Notifications\NextSmsGateway;
use App\Modules\Notifications\OrderChanged;
use App\Modules\Notifications\PublishOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NextSmsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = 'https://messaging-service.co.tz/api/sms/v2/text/single';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nextsms.enabled' => true, 'services.nextsms.token' => 'test-nextsms-token', 'services.nextsms.sender' => 'MLOEXPRESS']);
    }

    private function staged(): OutboxMessage
    {
        $customer = User::factory()->create(['phone' => '+255712345678']);
        $customer->roles()->attach(Role::where('name', 'customer')->value('id'));
        $product = Product::factory()->create();
        Queue::fake();
        Sanctum::actingAs($customer);
        $version = $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->json('data.version');
        $this->withHeader('Idempotency-Key', 'nextsms-order-key')->postJson('/api/v1/checkout', ['cart_version' => $version, 'fulfilment' => 'pickup', 'payment_method' => 'cash'])->assertCreated();
        Event::fake([OrderChanged::class]);
        $message = OutboxMessage::firstOrFail();
        (new PublishOutbox($message->id))->handle();

        return $message;
    }

    private function accepted(): array
    {
        return ['messages' => [['to' => '255712345678', 'messageId' => '7391827364512378881', 'status' => ['groupName' => 'PENDING']]]];
    }

    public function test_order_alert_is_encrypted_queued_and_sent_only_once(): void
    {
        $message = $this->staged();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response($this->accepted())]);
        Queue::assertPushedOn('notifications', SendOrderSms::class, fn ($job) => $job->id === $message->id);
        (new PublishOutbox($message->id))->handle();
        $this->assertDatabaseCount('sms_deliveries', 1);
        $row = DB::table('sms_deliveries')->first();
        $this->assertSame('255712345678', Crypt::decryptString($row->recipient));
        $this->assertStringNotContainsString('255712345678', $row->recipient);
        $this->assertStringContainsString('Cash payable at pickup.', Crypt::decryptString($row->body));
        $job = new SendOrderSms($message->id);
        $job->handle(new NextSmsGateway);
        $job->handle(new NextSmsGateway);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT && $request->hasHeader('Authorization', 'Bearer test-nextsms-token') && $request['from'] === 'MLOEXPRESS' && $request['to'] === '255712345678' && $request['reference'] === $message->id);
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'accepted', 'provider_message_id' => '7391827364512378881', 'attempts' => 1]);
    }

    public function test_disabled_sms_preserves_in_app_notifications_without_sending(): void
    {
        config(['services.nextsms.enabled' => false]);
        $this->staged();
        $this->assertDatabaseCount('sms_deliveries', 0);
        $this->assertDatabaseCount('notifications', 1);
        Queue::assertNotPushed(SendOrderSms::class);
    }

    public function test_connection_failure_is_uncertain_and_is_not_resent(): void
    {
        $message = $this->staged();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::failedConnection()]);
        $job = new SendOrderSms($message->id);
        $job->handle(new NextSmsGateway);
        $job->handle(new NextSmsGateway);
        $this->assertDatabaseHas('sms_deliveries', ['id' => $message->id, 'status' => 'unknown', 'attempts' => 1, 'error_code' => 'connection_uncertain']);
    }

    public function test_http_200_rejected_recipient_is_not_success(): void
    {
        $message = $this->staged();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response(['messages' => [['to' => '255712345678', 'status' => ['groupName' => 'REJECTED']]]])]);
        (new SendOrderSms($message->id))->handle(new NextSmsGateway);
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'rejected', 'error_code' => 'recipient_rejected', 'provider_message_id' => null]);
    }

    public function test_rate_limited_message_is_recovered_by_scheduler(): void
    {
        $this->freezeTime();
        $message = $this->staged();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::sequence()->push([], 429)->push($this->accepted())]);
        $job = new SendOrderSms($message->id);
        $job->handle(new NextSmsGateway);
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'queued', 'error_code' => 'rate_limited']);
        $this->travel(2)->minutes();
        Queue::fake([SendOrderSms::class]);
        $this->artisan('marketplace:tick')->assertSuccessful();
        Queue::assertPushed(SendOrderSms::class, fn ($job) => $job->id === $message->id);
        $job->handle(new NextSmsGateway);
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'accepted', 'attempts' => 2]);
        Http::assertSentCount(2);
    }

    public function test_crashed_in_flight_job_is_never_automatically_resent(): void
    {
        $message = $this->staged();
        DB::table('sms_deliveries')->where('id', $message->id)->update(['status' => 'submitting']);
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response($this->accepted())]);
        (new SendOrderSms($message->id))->handle(new NextSmsGateway);
        Http::assertNothingSent();
    }

    public function test_missing_token_does_not_claim_or_send_message(): void
    {
        $message = $this->staged();
        config(['services.nextsms.token' => null]);
        Http::preventStrayRequests();
        try {
            (new SendOrderSms($message->id))->handle(new NextSmsGateway);
            $this->fail('Missing configuration must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('NextSMS token and approved sender ID are required.', $exception->getMessage());
        }
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'queued', 'attempts' => 0]);
        Http::assertNothingSent();
    }

    public function test_malformed_response_is_held_for_review(): void
    {
        $message = $this->staged();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response('not-json', 200)]);
        (new SendOrderSms($message->id))->handle(new NextSmsGateway);
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'unknown', 'error_code' => 'invalid_response']);
    }

    public function test_event_snapshot_does_not_claim_a_later_payment_was_received(): void
    {
        $message = $this->staged();
        $order = Order::findOrFail($message->order_id);
        $order->forceFill(['payment_status' => 'verified'])->save();
        $row = DB::table('sms_deliveries')->where('id', $message->id)->first();
        $body = Crypt::decryptString($row->body);
        $this->assertStringNotContainsString('Payment received.', $body);
        $this->assertStringContainsString('Cash payable at pickup.', $body);
    }

    public function test_obsolete_unpublished_event_does_not_prompt_payment(): void
    {
        $message = $this->staged();
        DB::table('sms_deliveries')->delete();
        $message->forceFill(['published_at' => null])->save();
        Order::findOrFail($message->order_id)->forceFill(['status' => 'cancelled'])->save();
        (new PublishOutbox($message->id))->handle();
        $this->assertDatabaseCount('sms_deliveries', 0);
        $this->assertNotNull($message->fresh()->published_at);
    }

    public function test_invalid_customer_phone_is_not_sent_to_provider(): void
    {
        $message = $this->staged();
        DB::table('sms_deliveries')->delete();
        $message->forceFill(['published_at' => null])->save();
        Order::findOrFail($message->order_id)->customer->forceFill(['phone' => 'not-a-phone'])->save();
        (new PublishOutbox($message->id))->handle();
        $this->assertDatabaseCount('sms_deliveries', 0);
    }

    public function test_provider_id_larger_than_signed_64_bits_is_preserved(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response('{"messages":[{"to":"255712345678","messageId":9223372036854775809,"status":{"groupName":"PENDING"}}]}', 200)]);
        $result = (new NextSmsGateway)->send('255712345678', 'Order ready.', 'event-reference');
        $this->assertSame('9223372036854775809', $result['provider_message_id']);
        $this->assertSame('accepted', $result['status']);
    }

    public function test_provider_http_failure_states_are_classified_without_response_leaks(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['message' => 'sensitive-provider-detail'], 401)
            ->push(['message' => 'sensitive-provider-detail'], 503)
            ->push(['messages' => ['unexpected' => []]], 200)
            ->push(['messages' => [['to' => '255799999999', 'messageId' => '123', 'status' => ['groupName' => 'PENDING']]]], 200)]);
        $gateway = new NextSmsGateway;
        $this->assertSame(['status' => 'rejected', 'provider_message_id' => null, 'error_code' => 'http_401'], $gateway->send('255712345678', 'Order ready.', 'event-1'));
        $this->assertSame(['status' => 'unknown', 'provider_message_id' => null, 'error_code' => 'http_503'], $gateway->send('255712345678', 'Order ready.', 'event-2'));
        $this->assertSame('recipient_mismatch', $gateway->send('255712345678', 'Order ready.', 'event-3')['error_code']);
        $this->assertSame('recipient_mismatch', $gateway->send('255712345678', 'Order ready.', 'event-4')['error_code']);
    }

    public function test_rate_limit_retries_stop_after_five_submissions(): void
    {
        $message = $this->staged();
        DB::table('sms_deliveries')->where('id', $message->id)->update(['attempts' => 4]);
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response([], 429)]);
        $job = new SendOrderSms($message->id);
        $job->handle(new NextSmsGateway);
        $job->handle(new NextSmsGateway);
        $this->assertDatabaseHas('sms_deliveries', ['status' => 'rejected', 'attempts' => 5, 'error_code' => 'rate_limited']);
        Http::assertSentCount(1);
    }
}
