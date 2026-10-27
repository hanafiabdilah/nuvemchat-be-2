<?php

namespace App\Rules;

use App\Support\DisposableEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses temp-mail addresses — see App\Support\DisposableEmail. */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && DisposableEmail::isDisposable($value)) {
            $fail('Temporary or disposable email addresses are not accepted. Please use a permanent email address.');
        }
    }
}
