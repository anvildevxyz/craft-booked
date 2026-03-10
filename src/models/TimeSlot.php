<?php

namespace anvildev\booked\models;

final class TimeSlot
{
    private int $startMinutes;
    private int $endMinutes;

    public function __construct(string $startTime, string $endTime)
    {
        $this->startMinutes = self::timeToMinutes($startTime);
        $this->endMinutes = self::timeToMinutes($endTime);
    }

    public static function fromDuration(string $startTime, int $duration): self
    {
        return new self($startTime, self::minutesToTime(self::timeToMinutes($startTime) + $duration));
    }

    public static function fromMinutes(int $startMinutes, int $endMinutes): self
    {
        return new self(self::minutesToTime($startMinutes), self::minutesToTime($endMinutes));
    }

    public function getStartTime(): string
    {
        return self::minutesToTime($this->startMinutes);
    }

    public function getEndTime(): string
    {
        return self::minutesToTime($this->endMinutes);
    }

    public function getStartMinutes(): int
    {
        return $this->startMinutes;
    }

    public function getEndMinutes(): int
    {
        return $this->endMinutes;
    }

    public function getDuration(): int
    {
        return $this->endMinutes - $this->startMinutes;
    }

    public function overlaps(TimeSlot $other): bool
    {
        return $this->startMinutes < $other->endMinutes && $other->startMinutes < $this->endMinutes;
    }

    public function contains(TimeSlot $other): bool
    {
        return $this->startMinutes <= $other->startMinutes && $this->endMinutes >= $other->endMinutes;
    }

    public function isAdjacentTo(TimeSlot $other): bool
    {
        return $this->endMinutes === $other->startMinutes || $other->endMinutes === $this->startMinutes;
    }

    public function containsTime(string $time): bool
    {
        $minutes = self::timeToMinutes($time);
        return $minutes >= $this->startMinutes && $minutes < $this->endMinutes;
    }

    public function merge(TimeSlot $other): self
    {
        if (!$this->overlaps($other) && !$this->isAdjacentTo($other)) {
            throw new \InvalidArgumentException('Cannot merge non-overlapping, non-adjacent slots');
        }

        return self::fromMinutes(
            min($this->startMinutes, $other->startMinutes),
            max($this->endMinutes, $other->endMinutes)
        );
    }

    /** @return TimeSlot[] */
    public function subtract(TimeSlot $other): array
    {
        if (!$this->overlaps($other)) {
            return [$this];
        }

        if ($other->startMinutes <= $this->startMinutes && $other->endMinutes >= $this->endMinutes) {
            return [];
        }

        $result = [];
        if ($this->startMinutes < $other->startMinutes) {
            $result[] = self::fromMinutes($this->startMinutes, $other->startMinutes);
        }
        if ($this->endMinutes > $other->endMinutes) {
            $result[] = self::fromMinutes($other->endMinutes, $this->endMinutes);
        }

        return $result;
    }

    public function shift(int $minutes): self
    {
        return self::fromMinutes($this->startMinutes + $minutes, $this->endMinutes + $minutes);
    }

    public function isValid(): bool
    {
        return $this->startMinutes >= 0
            && $this->endMinutes <= 1440
            && $this->startMinutes < $this->endMinutes;
    }

    public function toArray(): array
    {
        return [
            'start' => $this->getStartTime(),
            'end' => $this->getEndTime(),
            'duration' => $this->getDuration(),
        ];
    }

    public function __toString(): string
    {
        return $this->getStartTime() . '-' . $this->getEndTime();
    }

    public function equals(TimeSlot $other): bool
    {
        return $this->startMinutes === $other->startMinutes
            && $this->endMinutes === $other->endMinutes;
    }

    private static function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    }

    private static function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
