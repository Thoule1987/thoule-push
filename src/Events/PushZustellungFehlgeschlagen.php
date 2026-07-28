<?php

namespace Thoule\Push\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Eine Zustellung ist fehlgeschlagen.
 *
 * **Was hier bewusst NICHT drinsteht: Endpunkt und Schlüssel.** Der Push-Endpunkt
 * ist ein pseudonymes personenbezogenes Datum – wer ihn hat, kann dem Gerät
 * Benachrichtigungen schicken. Die Apps schreiben dieses Ereignis in ihre
 * Protokolltabellen; dort gehört er nicht hin, und in einem Log-Aggregator erst
 * recht nicht. Zur Nachverfolgung genügt die Abo-ID.
 *
 * Ohne ein solches Ereignis verpufft ein fehlgeschlagener Push spurlos: Der
 * Versand wirft nicht, und der Status der auslösenden Nachricht bliebe
 * fälschlich „versendet".
 */
class PushZustellungFehlgeschlagen
{
    use Dispatchable;

    public function __construct(
        public readonly string $aboId,
        /** Grund, wie ihn der Push-Dienst meldet. */
        public readonly ?string $grund,
        /** true = 404/410, das Abo ist endgültig verworfen. */
        public readonly bool $abgelaufen,
    ) {}

    /**
     * @return array<string, string|bool|null>
     */
    public function kontext(): array
    {
        return [
            'push_abo_id' => $this->aboId,
            'grund' => $this->grund,
            'abgelaufen' => $this->abgelaufen,
        ];
    }

    public function nachricht(): string
    {
        return 'Push-Zustellung fehlgeschlagen'
            .($this->abgelaufen ? ' (Abo abgelaufen)' : '')
            .($this->grund !== null ? ': '.$this->grund : '');
    }
}
