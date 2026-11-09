<?php

namespace App\Modules\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

final class NextSmsGateway
{
    public function requireConfiguration(): void
    {
        if (! config('services.nextsms.token') || ! config('services.nextsms.sender')) {
            throw new RuntimeException('NextSMS token and approved sender ID are required.');
        }
    }

    /** @return array{status:string,provider_message_id:?string,error_code:?string} */
    public function send(string $recipient, string $text, string $reference): array
    {
        $this->requireConfiguration();
        try {
            $response = Http::withToken(config('services.nextsms.token'))
                ->acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(15)
                ->post('https://messaging-service.co.tz/api/sms/v2/text/single', [
                    'from' => config('services.nextsms.sender'),
                    'to' => $recipient, 'text' => $text, 'reference' => $reference,
                ]);
        } catch (ConnectionException) {
            return $this->result('unknown', error: 'connection_uncertain');
        }
        if ($response->status() === 429) {
            return $this->result('queued', error: 'rate_limited');
        }
        if ($response->clientError() && $response->status() !== 408) {
            return $this->result('rejected', error: 'http_'.$response->status());
        }
        if (! $response->successful()) {
            return $this->result('unknown', error: 'http_'.$response->status());
        }
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return $this->result('unknown', error: 'invalid_response');
        }
        $messages = $data['messages'] ?? null;
        if (! is_array($messages) || ! array_is_list($messages) || count($messages) !== 1 || ! is_array($messages[0]) || (string) ($messages[0]['to'] ?? '') !== $recipient) {
            return $this->result('unknown', error: 'recipient_mismatch');
        }
        $message = $messages[0];
        $group = $message['status']['groupName'] ?? null;
        if ($group === 'REJECTED') {
            return $this->result('rejected', error: 'recipient_rejected');
        }
        $id = $message['messageId'] ?? null;
        if (! in_array($group, ['PENDING', 'ACCEPTED', 'DELIVERED'], true) || (! is_int($id) && ! is_string($id)) || ! preg_match('/^\d{1,100}$/', (string) $id)) {
            return $this->result('unknown', error: 'unrecognized_response');
        }

        return $this->result('accepted', (string) $id);
    }

    /** @return array{status:string,provider_message_id:?string,error_code:?string} */
    private function result(string $status, ?string $id = null, ?string $error = null): array
    {
        return ['status' => $status, 'provider_message_id' => $id, 'error_code' => $error];
    }
}
