<?php

use Illuminate\Support\Facades\Event;
use Thoule\Push\Events\PushZustellungFehlgeschlagen;
use Thoule\Push\Models\PushAbo;
use Thoule\Push\PushErgebnis;
use Thoule\Push\PushVersand;
use Thoule\Push\Transport\TestTransport;

beforeEach(function () {
    $this->transport = new TestTransport;
    $this->versand = new PushVersand($this->transport);
});

it('zaehlt zugestellte Nachrichten', function () {
    $ergebnis = $this->versand->an([abo('https://push.example/a'), abo('https://push.example/b')], ['title' => 'Hallo']);

    expect($ergebnis)->toBe(['zugestellt' => 2, 'abgelaufen' => 0, 'fehler' => 0]);
});

it('markiert ein Abo nach 410 als abgelaufen, statt es zu loeschen', function () {
    // **Der Kern des Pakets.** Zwei der drei Thoule-Apps löschten hier hart – der
    // Browser liefert danach beim nächsten Aufruf dasselbe tote Abonnement zurück,
    // die App registriert es neu, und derselbe Endpunkt scheitert wieder mit 410.
    // In einer App real über anderthalb Stunden bei jedem Cron-Lauf beobachtet.
    $abo = abo();
    $this->transport->antwortFuer($abo->endpoint, PushErgebnis::abgelaufen('410 Gone'));

    $ergebnis = $this->versand->an([$abo], ['title' => 'Hallo']);

    expect($ergebnis['abgelaufen'])->toBe(1)
        ->and(PushAbo::query()->count())->toBe(1)
        ->and($abo->fresh()?->abgelaufen_at)->not->toBeNull();
});

it('laesst ein Abo nach 500 unangetastet', function () {
    // Ein Abo wegen einer vorübergehenden Störung zu entwerten, kostet die Nutzerin
    // eine erneute Zustimmung – die sie vielleicht nicht ein zweites Mal gibt.
    $abo = abo();
    $this->transport->antwortFuer($abo->endpoint, PushErgebnis::fehler('500 Internal Server Error'));

    $ergebnis = $this->versand->an([$abo], ['title' => 'Hallo']);

    expect($ergebnis['fehler'])->toBe(1)
        ->and($ergebnis['abgelaufen'])->toBe(0)
        ->and($abo->fresh()?->abgelaufen_at)->toBeNull();
});

it('beliefert ein abgelaufenes Abo gar nicht erst', function () {
    // Ohne diese Schranke erzeugt jeder Lauf denselben 410 erneut – genau die
    // Schleife, wegen der markiert statt gelöscht wird.
    $abo = abo();
    $abo->alsAbgelaufenMarkieren();

    $ergebnis = $this->versand->an([$abo], ['title' => 'Hallo']);

    expect($this->transport->versendet)->toBe([])
        ->and($ergebnis)->toBe(['zugestellt' => 0, 'abgelaufen' => 0, 'fehler' => 0]);
});

it('trennt im gemischten Versand die drei Ausgaenge sauber', function () {
    $gut = abo('https://push.example/gut');
    $tot = abo('https://push.example/tot');
    $gestoert = abo('https://push.example/gestoert');

    $this->transport
        ->antwortFuer($tot->endpoint, PushErgebnis::abgelaufen())
        ->antwortFuer($gestoert->endpoint, PushErgebnis::fehler());

    $ergebnis = $this->versand->an([$gut, $tot, $gestoert], ['title' => 'Hallo']);

    expect($ergebnis)->toBe(['zugestellt' => 1, 'abgelaufen' => 1, 'fehler' => 1])
        ->and($tot->fresh()?->abgelaufen_at)->not->toBeNull()
        ->and($gestoert->fresh()?->abgelaufen_at)->toBeNull();
});

it('meldet jeden Fehlschlag als Ereignis – ohne Endpunkt', function () {
    Event::fake([PushZustellungFehlgeschlagen::class]);

    $abo = abo();
    $this->transport->antwortFuer($abo->endpoint, PushErgebnis::abgelaufen('410 Gone'));

    $this->versand->an([$abo], ['title' => 'Hallo']);

    Event::assertDispatched(PushZustellungFehlgeschlagen::class, function (PushZustellungFehlgeschlagen $e) use ($abo) {
        $ausgabe = $e->nachricht().' '.json_encode($e->kontext());

        return $e->aboId === $abo->id
            && $e->abgelaufen === true
            && ! str_contains($ausgabe, 'push.example')
            && ! str_contains($ausgabe, $abo->auth_token);
    });
});

it('meldet bei Erfolg kein Ereignis', function () {
    // Ohne diese Gegenprobe wäre der Test oben auch dann grün, wenn das Ereignis
    // bei jedem Versand feuerte.
    Event::fake([PushZustellungFehlgeschlagen::class]);

    $this->versand->an([abo()], ['title' => 'Hallo']);

    Event::assertNotDispatched(PushZustellungFehlgeschlagen::class);
});

it('wertet ein fehlendes Transport-Ergebnis als Fehler, nicht als Erfolg', function () {
    $transport = new class extends TestTransport
    {
        public function sendenViele(iterable $abos, array $nutzlast): array
        {
            return [];
        }
    };

    expect((new PushVersand($transport))->an([abo()], [])['fehler'])->toBe(1);
});
