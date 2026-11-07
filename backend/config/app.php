<?php

declare(strict_types=1);

function publicRegistrationEnabled(): bool
{
    // Public signup stays disabled; staff accounts are managed by administrators.
    return false;
}
