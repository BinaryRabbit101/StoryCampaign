<?php

namespace App\Services\Claude;

use RuntimeException;

/**
 * The CLI ran, and refused the credential.
 *
 * Worth its own type because it is the one CLI failure that will not fix
 * itself: a malformed prompt or a flaky run is worth retrying every minute,
 * and a dead token is worth telling someone about. Callers use it to stop
 * hammering and start reporting.
 */
class ClaudeAuthException extends RuntimeException {}
