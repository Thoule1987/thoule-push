<?php

namespace Thoule\Push\Transport;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Thoule\Push\Contracts\PushTransport;
use Thoule\Push\Models\PushAbo;
use Thoule\Push\PushErgebnis;

/**
 * Echter Versand über `minishlink/web-push` mit dem VAPID-Schlüsselpaar aus der
 * Konfiguration.
 */
class MinishlinkTransport implements PushTransport
{
    /**
     * @param  (callable(array<string, mixed>): WebPush)|null  $fabrik  Nur für Tests: erlaubt es,
     *                                                                  einen WebPush mit gemocktem Guzzle-Handler einzusetzen. Ohne echten
     *                                                                  HTTP-Kanal liessen sich 410 und 500 – die beiden Antworten, auf die es hier
     *                                                                  ankommt – nur behaupten, nicht prüfen.
     */
    public function __construct(private readonly mixed $fabrik = null) {}

    public function senden(PushAbo $abo, array $nutzlast): PushErgebnis
    {
        return $this->sendenViele([$abo], $nutzlast)[$abo->id]
            ?? PushErgebnis::fehler('kein Bericht vom Push-Dienst');
    }

    public function sendenViele(iterable $abos, array $nutzlast): array
    {
        $nutzlastJson = $this->alsJson($nutzlast);
        $nachId = [];

        foreach ($abos as $abo) {
            $nachId[$abo->endpoint] = $abo;
        }

        if ($nachId === []) {
            return [];
        }

        $ergebnisse = [];

        if ($nutzlastJson === null) {
            // Eine nicht kodierbare Nutzlast betrifft alle Empfänger gleichermassen
            // und ist ein Fehler im eigenen Code, kein Problem des Push-Dienstes.
            foreach ($nachId as $abo) {
                $ergebnisse[$abo->id] = PushErgebnis::fehler('Nutzlast nicht als JSON kodierbar');
            }

            return $ergebnisse;
        }

        $webPush = $this->webPush();

        foreach ($nachId as $abo) {
            // queueNotification + flush statt sendOneNotification je Abo: Der Versand
            // an alle Empfänger läuft dann parallel über einen Guzzle-Pool statt als
            // Kette einzelner Requests.
            $webPush->queueNotification($this->subscription($abo), $nutzlastJson);
        }

        foreach ($webPush->flush() as $bericht) {
            $endpoint = $bericht->getEndpoint();
            $abo = $nachId[$endpoint] ?? null;

            if ($abo === null) {
                continue;
            }

            $ergebnisse[$abo->id] = $this->ausBericht($bericht);
        }

        // Ein Abo ohne Bericht darf nicht als Erfolg durchgehen – sonst meldet der
        // Versand „zugestellt" für etwas, das nie hinausging.
        foreach ($nachId as $abo) {
            $ergebnisse[$abo->id] ??= PushErgebnis::fehler('kein Bericht vom Push-Dienst');
        }

        return $ergebnisse;
    }

    private function ausBericht(MessageSentReport $bericht): PushErgebnis
    {
        if ($bericht->isSuccess()) {
            return PushErgebnis::erfolg();
        }

        // **Bewusst NICHT `$bericht->getReason()`.** Der Grund ist bei einem HTTP-Fehler
        // die Guzzle-Ausnahmemeldung, und die enthält die vollständige Request-URL –
        // also den Push-Endpunkt, ein pseudonymes personenbezogenes Datum. Er wandert
        // über das Ereignis in die Protokolltabellen der Apps und in jeden
        // Log-Aggregator. Aufgefallen ist das einem eigenen Test, nicht im Betrieb.
        //
        // Der Statuscode trägt die Diagnose vollständig: 410 tot, 429 gedrosselt,
        // 5xx Dienst gestört.
        $grund = $this->grundOhnePersonenbezug($bericht);

        // `isSubscriptionExpired()` prüft auf 404/410 – die einzigen Antworten, die
        // ein Abo endgültig entwerten. Alles andere ist vorübergehend.
        return $bericht->isSubscriptionExpired()
            ? PushErgebnis::abgelaufen($grund)
            : PushErgebnis::fehler($grund);
    }

    private function grundOhnePersonenbezug(MessageSentReport $bericht): string
    {
        $status = $bericht->getResponse()?->getStatusCode();

        return $status === null
            ? 'keine Antwort vom Push-Dienst'
            : 'Push-Dienst antwortete mit HTTP '.$status;
    }

    private function subscription(PushAbo $abo): Subscription
    {
        return Subscription::create([
            'endpoint' => $abo->endpoint,
            'publicKey' => $abo->public_key,
            'authToken' => $abo->auth_token,
            // **Ausdrücklich `aes128gcm` (RFC 8291), nicht die Voreinstellung der
            // Bibliothek.** `Subscription::create()` fällt auf `aesgcm` zurück – die
            // historische Entwurfs-Kodierung von vor der Standardisierung; die Bibliothek
            // hält daran aus Rückwärtskompatibilität fest und kündigt den Wechsel erst für
            // die nächste Hauptversion an.
            //
            // Der Unterschied ist im Betrieb **nicht sichtbar**: Der Push-Dienst prüft die
            // Nutzlast nicht und antwortet auch bei veralteter Kodierung mit 201. Erst der
            // Browser scheitert an der Entschlüsselung – und verwirft die Nachricht ohne
            // Meldung, ohne Ereignis, ohne Protokolleintrag. Der Server meldet „zugestellt",
            // die Nutzerin sieht nichts. Genau dieses Bild in Firefox und Edge war der
            // Anlass (28.07.2026).
            'contentEncoding' => ContentEncoding::aes128gcm,
        ]);
    }

    private function webPush(): WebPush
    {
        $auth = ['VAPID' => [
            'subject' => (string) config('push.vapid.subject'),
            'publicKey' => (string) config('push.vapid.public_key'),
            'privateKey' => (string) config('push.vapid.private_key'),
        ]];

        if (is_callable($this->fabrik)) {
            return ($this->fabrik)($auth);
        }

        return new WebPush($auth, [], (int) config('push.timeout', 30));
    }

    /**
     * @param  array<string, mixed>  $nutzlast
     */
    private function alsJson(array $nutzlast): ?string
    {
        // `json_encode` liefert bei einer ungültigen UTF-8-Sequenz im Nachrichtentext
        // `false`. Ungeprüft durchgereicht verschickt der Aufruf eine Push ohne Inhalt –
        // die Nutzerin sieht eine leere Benachrichtigung. Der Grund gehört ins Log,
        // die Nutzlast selbst nicht: Sie enthält den Nachrichtentext.
        $json = json_encode($nutzlast);

        return $json === false ? null : $json;
    }
}
