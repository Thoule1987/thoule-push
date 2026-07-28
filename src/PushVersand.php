<?php

namespace Thoule\Push;

use Thoule\Push\Contracts\PushTransport;
use Thoule\Push\Events\PushZustellungFehlgeschlagen;
use Thoule\Push\Models\PushAbo;

/**
 * Versendet eine Nutzlast an eine Menge von Abos und zieht die Konsequenzen.
 *
 * Die eine Entscheidung, um die es hier geht: **Was passiert mit dem Abo, wenn
 * die Zustellung scheitert.** Bei 404/410 hat der Push-Dienst es endgültig
 * verworfen – es wird markiert und nie wieder beliefert. Bei allem anderen
 * (Dienst gestört, Netzwerk, Rate-Limit) bleibt es unangetastet: Ein Abo wegen
 * einer vorübergehenden Störung zu entfernen, kostet die Nutzerin eine erneute
 * Zustimmung, die sie vielleicht nicht ein zweites Mal gibt.
 */
class PushVersand
{
    public function __construct(private readonly PushTransport $transport) {}

    /**
     * @param  iterable<PushAbo>  $abos
     * @param  array<string, mixed>  $nutzlast
     * @return array{zugestellt: int, abgelaufen: int, fehler: int}
     */
    public function an(iterable $abos, array $nutzlast): array
    {
        // Abgelaufene Abos gar nicht erst anfassen. Sonst erzeugt jeder Lauf denselben
        // 410 erneut – genau die Schleife, wegen der markiert statt gelöscht wird.
        $empfaenger = [];

        foreach ($abos as $abo) {
            if (! $abo->istAbgelaufen()) {
                $empfaenger[$abo->id] = $abo;
            }
        }

        if ($empfaenger === []) {
            return ['zugestellt' => 0, 'abgelaufen' => 0, 'fehler' => 0];
        }

        $ergebnisse = $this->transport->sendenViele($empfaenger, $nutzlast);

        $zugestellt = 0;
        $abgelaufen = 0;
        $fehler = 0;

        foreach ($empfaenger as $id => $abo) {
            $ergebnis = $ergebnisse[$id] ?? PushErgebnis::fehler('kein Ergebnis vom Transport');

            if ($ergebnis->erfolg) {
                $zugestellt++;

                continue;
            }

            if ($ergebnis->abgelaufen) {
                $abo->alsAbgelaufenMarkieren();
                $abgelaufen++;
            } else {
                $fehler++;
            }

            PushZustellungFehlgeschlagen::dispatch($abo->id, $ergebnis->grund, $ergebnis->abgelaufen);
        }

        return ['zugestellt' => $zugestellt, 'abgelaufen' => $abgelaufen, 'fehler' => $fehler];
    }
}
