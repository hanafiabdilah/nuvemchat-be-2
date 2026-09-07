<?php

namespace App\Support\Errors;

/**
 * "getMessage() on this exception is copy we wrote."
 *
 * The marker exists because the translating catch blocks cannot tell our own
 * sentences from an upstream's by looking at them, and getting that wrong is
 * costly in both directions: leave it out and "(#131047) Re-engagement
 * message" reaches a customer; apply it too widely and a hint we spent real
 * thought on — "ask your current provider to disable two-step verification,
 * then run the migration again" — is flattened into "não foi possível
 * concluir", which is the more expensive mistake because nothing in the log
 * records that it happened.
 *
 * Implementations must guarantee the message contains no upstream wording,
 * no error codes, no host names and no ids the reader has never seen.
 */
interface HasUserSafeMessage
{
}
