<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Thoule\Push\Models\PushAbo;

it('nimmt ein neues Abonnement entgegen', function () {
    $abo = PushAbo::registrieren('https://push.example/neu', 'oeffentlich', 'geheim');

    expect($abo)->not->toBeNull()
        ->and(PushAbo::query()->count())->toBe(1);
});

it('aktualisiert statt zu duplizieren, wenn derselbe Browser sich erneut meldet', function () {
    PushAbo::registrieren('https://push.example/gleich', 'alt', 'alt-auth');
    PushAbo::registrieren('https://push.example/gleich', 'neu', 'neu-auth');

    expect(PushAbo::query()->count())->toBe(1)
        ->and(PushAbo::query()->first()?->public_key)->toBe('neu');
});

it('laesst ein abgelaufenes Abonnement NICHT wieder auferstehen', function () {
    // Der Fall, für den `abgelaufen_at` überhaupt existiert: Nach einem 410 liefert
    // der Browser dasselbe tote Abonnement weiterhin zurück. Ein gewöhnliches
    // updateOrCreate liesse es als gültig erscheinen, und der nächste Versand
    // scheitert erneut mit 410 – in einer der Apps real über anderthalb Stunden.
    $abo = abo('https://push.example/tot');
    $abo->alsAbgelaufenMarkieren();

    $neu = PushAbo::registrieren('https://push.example/tot', 'oeffentlich', 'geheim');

    expect($neu)->toBeNull('Der Aufrufer muss den Browser zum Neu-Abonnieren auffordern.')
        ->and(PushAbo::query()->count())->toBe(0, 'Die Markierung hat ihren Zweck erfüllt und wird entfernt.');
});

it('nimmt denselben Endpunkt nach dem Aufraeumen wieder an', function () {
    // Die Sperre ist einmalig, keine dauerhafte Ausgrenzung: Nach dem Neu-Abonnieren
    // erzeugt der Browser meist ohnehin einen anderen Endpunkt – trifft es doch
    // denselben, muss er wieder funktionieren.
    $abo = abo('https://push.example/tot');
    $abo->alsAbgelaufenMarkieren();
    PushAbo::registrieren('https://push.example/tot', 'x', 'y');

    expect(PushAbo::registrieren('https://push.example/tot', 'x', 'y'))->not->toBeNull();
});

it('bindet ein Abo optional an einen Besitzer', function () {
    // Polymorph und optional, weil die Apps unterschiedliche Besitzer-Modelle haben
    // und eine bewusst anonyme Abos erlaubt (Frontend ohne Anmeldepflicht).
    //
    // **Der nicht-numerische Schluessel ist der Punkt dieses Tests**, nicht Beiwerk: Die
    // Migration legte `abonnent_id` zunaechst per `nullableMorphs()` als BIGINT an. Auf
    // der SQLite-Datenbank dieser Tests fiel das nie auf – SQLite speichert 'abc-123'
    // klaglos in einer INTEGER-Spalte. Erst auf MySQL brach es mit "Data truncated for
    // column 'abonnent_id'", also in der App und nicht hier. Der Test war gruen und hat
    // nichts bewiesen; belastbar ist er erst mit einer Spalte, die Zeichenketten haelt.
    $besitzer = new class extends Model
    {
        protected $table = 'besitzer';

        public function getKey()
        {
            return 'abc-123';
        }

        public function getMorphClass(): string
        {
            return 'konto';
        }
    };

    $abo = PushAbo::registrieren('https://push.example/mit-besitzer', 'x', 'y', $besitzer);

    expect($abo?->abonnent_type)->toBe('konto')
        ->and($abo?->abonnent_id)->toBe('abc-123');
});

it('erlaubt anonyme Abos ohne Besitzer', function () {
    $abo = PushAbo::registrieren('https://push.example/anonym', 'x', 'y');

    expect($abo?->abonnent_id)->toBeNull();
});

it('blendet abgelaufene Abos aus der aktiv-Abfrage aus', function () {
    abo('https://push.example/lebt');
    abo('https://push.example/tot')->alsAbgelaufenMarkieren();

    expect(PushAbo::query()->aktiv()->count())->toBe(1);
});

it('erzwingt eindeutige Endpunkte', function () {
    // Ohne Unique-Index legt jede Neuregistrierung eine weitere Zeile an – und
    // dieselbe Nachricht ginge ein zweites Mal an dasselbe Gerät.
    abo('https://push.example/doppelt');

    PushAbo::query()->create([
        'endpoint' => 'https://push.example/doppelt',
        'public_key' => 'x',
        'auth_token' => 'y',
    ]);
})->throws(QueryException::class);
