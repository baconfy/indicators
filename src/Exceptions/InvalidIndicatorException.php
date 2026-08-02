<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Exceptions;

use InvalidArgumentException;

final class InvalidIndicatorException extends InvalidArgumentException implements IndicatorException {}
