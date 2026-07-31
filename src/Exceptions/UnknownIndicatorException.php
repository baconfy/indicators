<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Exceptions;

use InvalidArgumentException;

/**
 * The manager does not know the requested indicator name.
 */
final class UnknownIndicatorException extends InvalidArgumentException implements IndicatorException {}
