<?php

namespace App\Services\Mcp\OAuth;

use RuntimeException;

/**
 * A client's own registration was refused.
 *
 * Its message is written for whoever is integrating the client, not for a
 * customer — this endpoint has no human in front of it — so it is surfaced
 * verbatim as the `error_description` of an `invalid_client_metadata` response.
 * That is the exception to the platform's usual rule about upstream wording,
 * and it holds because the words here are ours.
 */
class ClientRegistrationException extends RuntimeException {}
