<?php

namespace Thoule\Push;

/**
 * Ergebnis eines einzelnen Push-Versands.
 *
 * Bewusst kein `bool`: Ein Fehlschlag hat zwei fachlich völlig verschiedene
 * Ausprägungen. `abgelaufen` heißt, der Push-Dienst hat das Abo endgültig
 * verworfen (404/410) – dann darf es nie wieder beliefert werden. Jeder andere
 * Fehler ist vorübergehend (Dienst gestört, Netzwerk, Rate-Limit); dort wäre
 * das Entfernen des Abos ein Datenverlust, für den sich der Nutzer neu
 * registrieren müsste.
 */
final readonly class PushErgebnis
{
    private function __construct(
        public bool $erfolg,
        public bool $abgelaufen = false,
        /** Grund im Klartext, wie ihn der Push-Dienst meldet – nie der Endpunkt. */
        public ?string $grund = null,
    ) {}

    public static function erfolg(): self
    {
        return new self(erfolg: true);
    }

    /** Der Push-Dienst hat das Abo verworfen (404/410) – es ist endgültig tot. */
    public static function abgelaufen(?string $grund = null): self
    {
        return new self(erfolg: false, abgelaufen: true, grund: $grund);
    }

    /** Vorübergehender Fehlschlag: Das Abo bleibt bestehen. */
    public static function fehler(?string $grund = null): self
    {
        return new self(erfolg: false, grund: $grund);
    }
}
