<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Raised for misconfiguration or provider failures.
 * The message is safe to show to the user (never contains keys or data).
 */
class AiException extends RuntimeException {}
