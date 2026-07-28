<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Thoule\Push\Transport\MinishlinkTransport;

/**
 * Prüft den Transport gegen **echte HTTP-Antworten** der Push-Dienste, eingespeist
 * über einen Guzzle-MockHandler. Ohne echten HTTP-Kanal liessen sich 410 und 500 –
 * die beiden Antworten, an denen die ganze Ablaufbehandlung hängt – nur behaupten.
 */
function transportMit(Response ...$antworten): MinishlinkTransport
{
    $handler = HandlerStack::create(new MockHandler($antworten));

    return new MinishlinkTransport(fn (array $auth) => new WebPush($auth, [], 30, ['handler' => $handler]));
}

it('sendet mit der Standard-Kodierung aes128gcm, nicht mit dem veralteten aesgcm', function () {
    // **Der Fehler, der von aussen wie Erfolg aussieht.** `Subscription::create()` fällt ohne
    // Angabe auf `aesgcm` zurück – die historische Entwurfs-Kodierung von vor RFC 8291. Der
    // Push-Dienst prüft die Nutzlast nicht und antwortet auch damit mit 201; erst der Browser
    // scheitert an der Entschlüsselung und verwirft die Nachricht **stumm**. Server meldet
    // „zugestellt", Nutzerin sieht nichts, Protokoll bleibt leer.
    //
    // Geprüft wird deshalb der tatsächlich hinausgehende Request, nicht die Rückgabe des
    // Transports: Das Ergebnisobjekt sähe in beiden Fällen identisch aus.
    $gesendet = [];
    $handler = HandlerStack::create(new MockHandler([new Response(201)]));
    $handler->push(Middleware::history($gesendet));

    $transport = new MinishlinkTransport(fn (array $auth) => new WebPush($auth, [], 30, ['handler' => $handler]));
    $transport->senden(abo(), ['title' => 'Hallo']);

    $anfrage = $gesendet[0]['request'];

    expect($anfrage->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        // Gegenprobe: Der VAPID-Header muss zur selben Kodierung passen – bei `aesgcm`
        // wandert der öffentliche Schlüssel in `Crypto-Key`, bei `aes128gcm` ins
        // `Authorization`-Feld nach dem Schema `vapid k=…,t=…`.
        ->and($anfrage->getHeaderLine('Authorization'))->toStartWith('vapid ');
});

it('meldet Erfolg bei 201 Created', function () {
    $abo = abo();

    $ergebnis = transportMit(new Response(201))->senden($abo, ['title' => 'Hallo']);

    expect($ergebnis->erfolg)->toBeTrue()
        ->and($ergebnis->abgelaufen)->toBeFalse();
});

it('erkennt 410 Gone als abgelaufenes Abo', function () {
    $ergebnis = transportMit(new Response(410))->senden(abo(), ['title' => 'Hallo']);

    expect($ergebnis->erfolg)->toBeFalse()
        ->and($ergebnis->abgelaufen)->toBeTrue();
});

it('erkennt 404 Not Found ebenfalls als abgelaufen', function () {
    // Beide Codes bedeuten dasselbe: Der Dienst kennt diesen Endpunkt nicht mehr.
    expect(transportMit(new Response(404))->senden(abo(), [])->abgelaufen)->toBeTrue();
});

it('behandelt 500 als voruebergehenden Fehler, NICHT als Ablauf', function () {
    // Der entscheidende Unterschied: Ein Abo wegen einer Störung des Dienstes zu
    // entwerten, kostet die Nutzerin eine erneute Zustimmung.
    $ergebnis = transportMit(new Response(500))->senden(abo(), []);

    expect($ergebnis->erfolg)->toBeFalse()
        ->and($ergebnis->abgelaufen)->toBeFalse();
});

it('behandelt 429 (Rate-Limit) als voruebergehenden Fehler', function () {
    expect(transportMit(new Response(429))->senden(abo(), [])->abgelaufen)->toBeFalse();
});

it('ordnet die Berichte den richtigen Abos zu', function () {
    // Der Reihenfolge des Transports nicht zu trauen ist kein Übereifer: Der Versand
    // läuft über einen Guzzle-Pool, die Berichte kommen nach Antwortzeit zurück. Eine
    // Zuordnung über die Position würde bei gemischtem Ausgang das falsche Abo
    // entwerten.
    $eins = abo('https://fcm.googleapis.com/fcm/send/eins');
    $zwei = abo('https://fcm.googleapis.com/fcm/send/zwei');

    $ergebnisse = transportMit(new Response(201), new Response(410))
        ->sendenViele([$eins, $zwei], ['title' => 'Hallo']);

    expect($ergebnisse[$eins->id]->erfolg)->toBeTrue()
        ->and($ergebnisse[$zwei->id]->abgelaufen)->toBeTrue();
});

it('behandelt einen nicht erreichbaren Push-Dienst als voruebergehenden Fehler', function () {
    // Netzwerk weg: Es gibt einen Bericht, aber keine Antwort. Das darf das Abo
    // nicht entwerten – der Dienst ist gleich vielleicht wieder da.
    $abo = abo();
    $handler = HandlerStack::create(new MockHandler([
        new ConnectException('Connection refused', new Request('POST', $abo->endpoint)),
    ]));
    $transport = new MinishlinkTransport(fn (array $auth) => new WebPush($auth, [], 30, ['handler' => $handler]));

    $ergebnis = $transport->senden($abo, []);

    expect($ergebnis->erfolg)->toBeFalse()
        ->and($ergebnis->abgelaufen)->toBeFalse()
        ->and($ergebnis->grund)->toBe('keine Antwort vom Push-Dienst');
});

it('wertet ein Abo ohne Bericht als Fehler, nicht als Erfolg', function () {
    // Sonst meldete der Versand "zugestellt" für etwas, das nie hinausging.
    $abo = abo();
    $stumm = new class(['VAPID' => ['subject' => 'mailto:t@e.example', 'publicKey' => 'x', 'privateKey' => 'y']]) extends WebPush
    {
        public function __construct(array $auth) {}

        public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void {}

        public function flush(?int $batchSize = null): Generator
        {
            yield from [];
        }
    };

    $ergebnisse = (new MinishlinkTransport(fn () => $stumm))->sendenViele([$abo], []);

    expect($ergebnisse)->toHaveKey($abo->id)
        ->and($ergebnisse[$abo->id]->erfolg)->toBeFalse();
});

it('versendet nichts bei leerer Empfaengerliste', function () {
    expect(transportMit()->sendenViele([], ['title' => 'Hallo']))->toBe([]);
});

it('verschickt keine leere Benachrichtigung, wenn die Nutzlast nicht kodierbar ist', function () {
    // json_encode liefert bei ungültigem UTF-8 `false`. Ungeprüft durchgereicht
    // ginge eine Push ohne Inhalt hinaus – die Nutzerin sähe eine leere Meldung.
    $abo = abo();

    $ergebnisse = transportMit(new Response(201))->sendenViele([$abo], ['title' => "\xB1\x31"]);

    expect($ergebnisse[$abo->id]->erfolg)->toBeFalse()
        ->and($ergebnisse[$abo->id]->grund)->toContain('JSON');
});

it('nennt im Grund weder Endpunkt noch Schluessel', function () {
    // Der Grund wandert in Log und Protokolltabelle der Apps. Der Endpunkt ist ein
    // pseudonymes personenbezogenes Datum und gehört dort nicht hin.
    // Der Auth-Token kommt aus dem Helfer `abo()` und wird hier bewusst nicht
    // abgetippt: Ein hartkodierter Wert wuerde beim naechsten Schluesselwechsel
    // still auf etwas pruefen, das gar nicht mehr vorkommt.
    $abo = abo('https://fcm.googleapis.com/fcm/send/geprueft');

    $ergebnis = transportMit(new Response(410))->senden($abo, []);

    expect((string) $ergebnis->grund)->not->toContain('fcm.googleapis.com')
        ->and((string) $ergebnis->grund)->not->toContain($abo->auth_token)
        ->and((string) $ergebnis->grund)->not->toContain($abo->public_key)
        // Gegenprobe: Der Grund ist nicht einfach leer, er traegt die Diagnose.
        ->and((string) $ergebnis->grund)->toContain('410');
});
