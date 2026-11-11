<?php

declare(strict_types=1);

namespace App\Demo;

use InvalidArgumentException;

/**
 * One demo task: its brief, when it was created, and what happened to it afterwards, in order.
 *
 * Times are seconds before "now" for events and seconds after "now" for the deadline (negative: past),
 * so the scenario holds no calendar dates and reads the same whenever it is seeded.
 */
final readonly class PlannedTask
{
    /** @var list<PlannedStep> the steps after the creation, oldest first */
    public array $steps;

    /**
     * @param list<string> $geo
     * @param list<string> $referenceUrls
     * @param list<string> $referenceFiles names of placeholder images the buyer attaches as references
     * @param list<PlannedStep> $steps
     */
    public function __construct(
        public string $offer,
        public array $geo,
        public string $creativeType,
        public int $quantity,
        public string $priority,
        public string $description,
        public string $buyer,
        public int $createdSecondsAgo,
        public int $deadlineSecondsFromNow,
        public array $referenceUrls,
        public array $referenceFiles,
        array $steps,
    ) {
        $previous = $createdSecondsAgo;
        foreach ($steps as $step) {
            if ($step->secondsAgo > $previous || $step->secondsAgo < 0) {
                throw new InvalidArgumentException(sprintf('The steps of "%s" are out of order or in the future.', $offer));
            }
            $previous = $step->secondsAgo;
        }

        $this->steps = $steps;
    }

    /**
     * Every step including the creation, oldest first.
     *
     * @return list<PlannedStep>
     */
    public function timeline(): array
    {
        return [PlannedStep::create($this->createdSecondsAgo, $this->buyer), ...$this->steps];
    }
}
