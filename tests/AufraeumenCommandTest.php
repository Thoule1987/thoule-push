<?php

use Thoule\Push\Models\PushAbo;

it('entfernt abgelaufene Abos nach Ablauf der Frist', function () {
    $alt = abo('https://push.example/alt');
    $alt->forceFill(['abgelaufen_at' => now()->subDays(8)])->save();

    $this->artisan('push:abos-aufraeumen')->assertSuccessful();

    expect(PushAbo::query()->count())->toBe(0);
});

it('laesst frisch abgelaufene Abos stehen – die Markierung wird noch gebraucht', function () {
    // Solange der Browser das tote Abonnement noch vorhält, ist die Zeile der
    // einzige Schutz gegen die Wiederauferstehung.
    $frisch = abo('https://push.example/frisch');
    $frisch->alsAbgelaufenMarkieren();

    $this->artisan('push:abos-aufraeumen')->assertSuccessful();

    expect(PushAbo::query()->count())->toBe(1);
});

it('entfernt verwaiste Abos, die lange nicht mehr aktualisiert wurden', function () {
    $verwaist = abo('https://push.example/verwaist');
    $verwaist->forceFill(['updated_at' => now()->subDays(400)])->save();

    $this->artisan('push:abos-aufraeumen')->assertSuccessful();

    expect(PushAbo::query()->count())->toBe(0);
});

it('laesst aktive Abos unberuehrt', function () {
    // Die Gegenprobe: Ohne sie wäre ein zu weit gefasstes Aufräumen unbemerkt
    // geblieben – und ein gelöschtes Abo heisst, dass der Nutzer erneut zustimmen muss.
    abo('https://push.example/aktiv');

    $this->artisan('push:abos-aufraeumen')->assertSuccessful();

    expect(PushAbo::query()->count())->toBe(1);
});

it('bricht bei einer Frist unter einem Tag ab, statt alles zu loeschen', function () {
    // Eine 0 in der .env würde sonst auch die Markierung von eben entfernen und
    // damit genau den Schutz aushebeln, für den sie existiert.
    config()->set('push.aufbewahrung.abgelaufen_tage', 0);

    $abo = abo();
    $abo->alsAbgelaufenMarkieren();

    $this->artisan('push:abos-aufraeumen')->assertFailed();

    expect(PushAbo::query()->count())->toBe(1);
});

it('nennt die Zahlen in der Ausgabe', function () {
    abo('https://push.example/alt')->forceFill(['abgelaufen_at' => now()->subDays(30)])->save();

    $this->artisan('push:abos-aufraeumen')
        ->expectsOutputToContain('1 abgelaufen, 0 verwaist')
        ->assertSuccessful();
});
