<?php

namespace Tourze\Symfony\CircuitBreaker\Exception;

/**
 * 手动标记失败异常
 *
 * 用于手动标记熔断器失败时抛出的业务异常
 */
class ManualFailureException extends \RuntimeException
{
}
