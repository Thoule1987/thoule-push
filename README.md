# thoule/push

Web-Push für die Thoule-Laravel-Apps: Transport-Contract, Minishlink-Transport,
Test-Transport, Abo-Model mit Ablaufbehandlung und Aufräum-Kommando.

Alle drei Apps versendeten Web Push und saßen letztlich auf derselben Bibliothek —
drei Wrappings, drei Abo-Schemata, und jede hatte einen anderen Teil richtig.
**Nicht** im Paket: die Nachrichten selbst. Was gesendet wird, an wen und wann,
bleibt Sache der App.

## Installation

```bash
composer require thoule/push
php artisan migrate
```

```dotenv
VAPID_SUBJECT=mailto:info@example.org
VAPID_PUBLIC_KEY=…
VAPID_PRIVATE_KEY=…
```

> Der **private** VAPID-Schlüssel gehört nirgendwo hin außer in die `.env` — nicht
> in eine Ausgabe, nicht in ein Log, nicht in ein Ereignis. Je Umgebung ein eigenes
> Paar: Wird es getauscht, sind alle bestehenden Abos ungültig und die Nutzer müssen
> erneut zustimmen.

## Benutzung

```php
use Thoule\Push\Models\PushAbo;
use Thoule\Push\PushVersand;

// Registrierung aus dem Browser entgegennehmen
$abo = PushAbo::registrieren($endpoint, $publicKey, $authToken, abonnent: $user);

if ($abo === null) {
    // Dieser Endpunkt war bereits als abgelaufen markiert — s. unten.
    return response()->noContent(410);
}

// Versenden
$zahlen = app(PushVersand::class)->an(
    PushAbo::query()->aktiv()->get(),
    ['title' => 'Neue Nachricht', 'body' => '…'],
);
// ['zugestellt' => 12, 'abgelaufen' => 1, 'fehler' => 0]
```

Der Besitzer ist **polymorph und optional**: Die Apps binden Abos an
unterschiedliche Modelle, und eine erlaubt bewusst anonyme Abos, weil ihr Frontend
ohne Anmeldung nutzbar ist. `abonnent_id` ist deshalb eine Zeichenkette und kein
`nullableMorphs()`-BIGINT — sonst wäre jede App mit UUID-Schlüsseln ausgeschlossen.

## Was bei einem Fehlschlag passiert — die eigentliche Entscheidung

| Antwort | Bedeutung | Folge |
|---|---|---|
| 201 | zugestellt | – |
| **404 / 410** | Der Dienst hat das Abo endgültig verworfen | `abgelaufen_at` wird gesetzt |
| 429, 5xx, Netzwerkfehler | vorübergehend gestört | Abo bleibt **unangetastet** |

Ein Abo wegen einer vorübergehenden Störung zu entfernen, kostet die Nutzerin eine
erneute Zustimmung — die sie vielleicht kein zweites Mal gibt.

### Warum markieren statt löschen

Zwei der drei Apps löschten bei 410 hart. Das erzeugt eine Schleife: Der Browser —
Firefox besonders zuverlässig — liefert `pushManager.getSubscription()` beim
nächsten Seitenaufruf weiterhin **dasselbe, längst verworfene Abonnement**, die App
registriert es brav neu, und der nächste Versand scheitert erneut mit 410. In einer
der Apps real beobachtet: derselbe Endpunkt, über anderthalb Stunden, bei jedem
Cron-Lauf.

Die markierte Zeile ist der einzige Beleg dafür, dass dieser Endpunkt tot ist. Ohne
sie kann `registrieren()` „neuer Browser" nicht von „derselbe tote Endpunkt schon
wieder" unterscheiden. Endgültig entfernt wird sie vom Aufräum-Kommando:

```bash
php artisan push:abos-aufraeumen
```

Löscht abgelaufene Abos nach `PUSH_ABGELAUFEN_TAGE` (Standard 7 — so lange braucht
die Markierung ihre Schutzwirkung) und verwaiste nach `PUSH_VERWAIST_TAGE`
(Standard 365). Bei einer Frist unter einem Tag bricht das Kommando ab, statt auch
die Markierung von eben zu löschen. Gehört in den Scheduler.

## Fehler beobachten

Jeder Fehlschlag löst `Events\PushZustellungFehlgeschlagen` aus, mit Abo-ID, Grund
und `abgelaufen`-Flag.

> **Ohne ein solches Ereignis verpufft ein fehlgeschlagener Push spurlos** — der
> Versand wirft nicht, und der Status der auslösenden Nachricht bliebe fälschlich
> „versendet".

Der Grund ist bewusst **nicht** `MessageSentReport::getReason()`: Der enthält bei
einem HTTP-Fehler die Guzzle-Ausnahmemeldung samt vollständiger Request-URL, also
den Push-Endpunkt — ein pseudonymes personenbezogenes Datum, das über das Ereignis
in die Protokolltabellen der Apps und in jeden Log-Aggregator wandern würde. Weiter
gereicht wird der Statuscode; er trägt die Diagnose vollständig.

## Tests ohne Push-Dienst

Die echte Zustellung läuft über FCM, Mozilla Autopush bzw. APNs und braucht Geräte
mit erteilter Berechtigung — sie ist in keiner Pipeline prüfbar. Deshalb der
Test-Transport:

```php
use Thoule\Push\Contracts\PushTransport;
use Thoule\Push\PushErgebnis;
use Thoule\Push\Transport\TestTransport;

$transport = new TestTransport;
$transport->antwortFuer($abo->endpoint, PushErgebnis::abgelaufen());
app()->instance(PushTransport::class, $transport);

// danach:
$transport->versendet;                  // was hinausgegangen wäre
$transport->versucheAn($abo->endpoint); // wie oft
```

Antworten werden je Endpunkt gesetzt, nicht global — der interessante Fall ist
gerade der gemischte Versand, bei dem ein Abo abläuft und die anderen zugestellt
werden.

Die Tests des Pakets prüfen den echten Transport gegen **echte HTTP-Antworten**
(Guzzle-`MockHandler`). Ohne echten HTTP-Kanal liessen sich 410 und 500 — die
beiden Antworten, an denen die ganze Ablaufbehandlung hängt — nur behaupten.

## Kodierung: `aes128gcm`, ausdrücklich

Der Transport setzt `contentEncoding` fest auf `aes128gcm` (RFC 8291).
`Subscription::create()` fällt ohne diese Angabe auf `aesgcm` zurück – die historische
Entwurfs-Kodierung; die Bibliothek hält daran aus Rückwärtskompatibilität fest.

**Der Unterschied ist im Betrieb unsichtbar:** Der Push-Dienst prüft die Nutzlast nicht und
antwortet auch bei veralteter Kodierung mit **201**. Erst der Browser scheitert an der
Entschlüsselung und verwirft die Nachricht ohne Meldung. Der Server meldet „zugestellt", die
Nutzerin sieht nichts, das Fehlerprotokoll bleibt leer. Ein Test prüft deshalb den
tatsächlich hinausgehenden HTTP-Header, nicht die Rückgabe des Transports – die sähe in
beiden Fällen gleich aus.

## Was beim Ablösen des alten Wrappers verglichen wurde

Der abgelöste `laravel-notification-channels/webpush` setzte mehrere Bibliotheks-Vorgaben
still zurecht. Beim Nachbau ist das zweimal untergegangen (v0.2.1/v0.2.2). Der vollständige
Abgleich, damit niemand ihn erneut führen muss:

| Einstellung | Alter Wrapper | Hier |
|---|---|---|
| `contentEncoding` | `aes128gcm` ausdrücklich | ebenso (Vorgabe wäre `aesgcm`) |
| VAPID-Betreff leer | Rückfall auf `url('/')` | Rückfall auf `app.url`, sonst Abbruch |
| `setReuseVAPIDHeaders` | `true` | ebenso |
| `setAutomaticPadding` | `true` → 2820 Bytes | Bibliotheks-Vorgabe ist bereits 2820 |
| Guzzle-Optionen, Zeitlimit | `[]`, 30 s | ebenso |
| Nutzlast | `title`/`body`/`icon` ohne Leerwerte | ebenso |
| Abo bei 404/410 | löschen | **markieren** (bewusst anders, s. oben) |
| Ereignis bei Erfolg | `NotificationSent` | keins – ein erfolgreicher Versand hinterlässt nichts |

## DSGVO

Endpunkt und Schlüssel sind pseudonyme personenbezogene Daten: Wer sie hat, kann
dem Gerät Benachrichtigungen schicken. Sie werden beim Abmelden gelöscht, bei
endgültigem Ablauf markiert und vom Aufräum-Kommando entfernt. Die Bindung an ein
Konto sollte in der App per Kaskade gelöscht werden.

## Gates

```bash
composer lint && composer analyse && composer test
```

## Lizenz

MIT
