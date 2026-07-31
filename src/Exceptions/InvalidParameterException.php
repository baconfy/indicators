<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Exceptions;

use InvalidArgumentException;

/**
 * A constructor parameter is not acceptable (period < 1, unknown parameter name).
 */
final class InvalidParameterException extends InvalidArgumentException implements IndicatorException {}
