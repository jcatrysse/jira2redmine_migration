<?php

declare(strict_types=1);

/**
 * A script's source without its layout: every run of whitespace as one space, none just inside brackets. The tests that
 * look for a line of a script read it this way, so that PSR-12 wrapping a long call over several lines (phase 6) does
 * not change what they find; the code they look for stays the same.
 */
function sourceWithoutLayout(string $code): string
{
    $code = (string)preg_replace('/\s+/', ' ', $code);

    return (string)preg_replace(['/([(\[]) /', '/ ([)\]])/'], ['$1', '$1'], $code);
}
