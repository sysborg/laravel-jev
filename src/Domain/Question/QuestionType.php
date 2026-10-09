<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Question;

enum QuestionType: string
{
    /** Yes/no question answered with the probability of "yes". */
    case Noul = 'noul';

    /** Pick one of 2–255 named options. */
    case Choice = 'choice';

    /** Place the input on an ordered scale of 2–10 levels. */
    case Score = 'score';
}
