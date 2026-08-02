<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Exceptions;

use InvalidArgumentException;

final class UnknownIndicatorException extends InvalidArgumentException implements IndicatorException {}
