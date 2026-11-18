<?php

namespace App\Enums;

enum GenerationStatus: string
{
    // Ours alone: saved here, but not yet accepted by Pixelforge. Users see
    // it as "queued". The other four are Pixelforge's own statuses.
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * How far along its life a generation is. A status may only ever be
     * replaced by one that ranks higher, which is what makes duplicate and
     * out-of-order updates harmless. Succeeded and failed share the top rank,
     * so neither can overwrite the other.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
