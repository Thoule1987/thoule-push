<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Thoule\Push\Models\PushAbo;
use Thoule\Push\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(__DIR__);

function abo(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): PushAbo
{
    return PushAbo::query()->create([
        'endpoint' => $endpoint,
        // Ein gültiger P-256-Punkt und ein 16-Byte-Auth-Secret, wie ein Browser sie
        // liefert. Erfundene Werte scheitern schon beim Ableiten des Shared Secrets,
        // also weit vor dem HTTP-Aufruf, um den es in den Transport-Tests geht.
        'public_key' => 'BLdPHTMFdt3UFP63dIltir-UcGxRf6C2aS9-89O2pda6HDZSWhYQWG3_wl_s79fd8z5qSc1XWhKl62TvJWe1sg0',
        'auth_token' => '8jSLQnOS_zsTwCKpcy2fJg',
    ]);
}
