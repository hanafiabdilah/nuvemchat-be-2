<?php

namespace App\Services\Incident;

/** What IncidentMatcher decided about one message. */
final class IncidentVerdict
{
    public const MATCH = 'match';

    public const NO_MATCH = 'no_match';

    public const NOT_COUNTED = 'not_counted';

    public const REASON_GREETING = 'greeting';

    public const REASON_BUTTON = 'button';

    public const REASON_NO_TEXT = 'no_text';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $keyword = null,
        public readonly ?string $excluded = null,
        public readonly ?string $reason = null,
    ) {}

    public static function match(?string $keyword): self
    {
        return new self(self::MATCH, keyword: $keyword);
    }

    public static function noMatch(?string $excluded = null): self
    {
        return new self(self::NO_MATCH, excluded: $excluded);
    }

    public static function notCounted(string $reason): self
    {
        return new self(self::NOT_COUNTED, reason: $reason);
    }

    public function matched(): bool
    {
        return $this->outcome === self::MATCH;
    }

    public function counted(): bool
    {
        return $this->outcome !== self::NOT_COUNTED;
    }

    /** @return array{outcome: string, keyword: ?string, excluded: ?string, reason: ?string} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'keyword' => $this->keyword,
            'excluded' => $this->excluded,
            'reason' => $this->reason,
        ];
    }
}
