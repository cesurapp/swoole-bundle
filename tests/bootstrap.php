<?php

use Doctrine\Deprecations\Deprecation;

require dirname(__DIR__).'/vendor/autoload.php';

// Doctrine deprecations are silent by default. Raised as E_USER_DEPRECATED they fail the suite
// (failOnDeprecation), so any API removed in the next Doctrine major is caught here first.
Deprecation::enableWithTriggerError();
