<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Exceptions;

use InvalidArgumentException;

/**
 * A class registered as an indicator does not implement the Indicator contract.
 */
final class InvalidIndicatorException extends InvalidArgumentException implements IndicatorException {}
