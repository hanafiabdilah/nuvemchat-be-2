<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A tutorial upload that cannot become a playable video, in a sentence written
 * for the admin who uploaded it (Back Office → Tutorials shows it as is).
 */
class TutorialVideoException extends RuntimeException {}
