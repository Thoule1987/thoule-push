<?php

namespace Thoule\Push\Transport;

use Thoule\Push\Contracts\PushTransport;
use Thoule\Push\Models\PushAbo;
use Thoule\Push\PushErgebnis;

/**
 * Transport ohne Netzwerk: zeichnet auf, was versendet worden wäre, und liefert
 * programmierbare Antworten.
 *
 * **Wofür.** Die echte Zustellung braucht Geräte mit erteilter Berechtigung und
 * ist deshalb in keiner Pipeline prüfbar. Ohne diesen Transport bliebe alles,
 * was *um* den Versand herum passiert – Empfängerauswahl, Ablaufbehandlung,
 * Zählung, Statuswechsel –, in den Apps ungetestet.
 *
 * Antworten werden je Endpunkt gesetzt, nicht global: Der interessante Fall ist
 * gerade der gemischte Versand, bei dem ein Abo abläuft und die anderen zugestellt
 * werden.
 */
class TestTransport implements PushTransport
{
    /** @var list<array{abo_id: string, endpoint: string, nutzlast: array<string, mixed>}> */
    public array $versendet = [];

    /** @var array<string, PushErgebnis> Endpunkt => Antwort */
    private array $antworten = [];

    private PushErgebnis $standard;

    public function __construct()
    {
        $this->standard = PushErgebnis::erfolg();
    }

    /** Legt fest, was dieser Endpunkt beim nächsten Versand antwortet. */
    public function antwortFuer(string $endpoint, PushErgebnis $ergebnis): self
    {
        $this->antworten[$endpoint] = $ergebnis;

        return $this;
    }

    /** Antwort für alle Endpunkte ohne eigene Festlegung. */
    public function standardAntwort(PushErgebnis $ergebnis): self
    {
        $this->standard = $ergebnis;

        return $this;
    }

    public function senden(PushAbo $abo, array $nutzlast): PushErgebnis
    {
        $this->versendet[] = [
            'abo_id' => $abo->id,
            'endpoint' => $abo->endpoint,
            'nutzlast' => $nutzlast,
        ];

        return $this->antworten[$abo->endpoint] ?? $this->standard;
    }

    public function sendenViele(iterable $abos, array $nutzlast): array
    {
        $ergebnisse = [];

        foreach ($abos as $abo) {
            $ergebnisse[$abo->id] = $this->senden($abo, $nutzlast);
        }

        return $ergebnisse;
    }

    /** Anzahl der Versandversuche an diesen Endpunkt. */
    public function versucheAn(string $endpoint): int
    {
        return count(array_filter($this->versendet, fn (array $e) => $e['endpoint'] === $endpoint));
    }
}
