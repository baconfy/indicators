<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Exceptions;

/**
 * Marker implemented by every exception this package throws.
 *
 * Lets a consumer catch anything coming out of the package with a single
 * catch block, without knowing the concrete types.
 */
interface IndicatorException {}
