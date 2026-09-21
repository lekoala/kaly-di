<?php

declare(strict_types=1);

namespace Kaly\Di;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class UnresolvableParameterException extends Exception implements ContainerExceptionInterface
{
    private ?string $parameterName = null;

    private ?string $objectId = null;

    private ?string $resolutionPath = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $parameterName = null,
        ?string $objectId = null,
        ?string $resolutionPath = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->parameterName = $parameterName;
        $this->objectId = $objectId;
        $this->resolutionPath = $resolutionPath;
    }

    public function getParameterName(): ?string
    {
        return $this->parameterName;
    }

    /**
     * The id/class being built when the parameter failed, when known.
     */
    public function getObjectId(): ?string
    {
        return $this->objectId;
    }

    /**
     * Structured resolution path built from the cause chain, e.g.
     * `Root::$middle -> Middle::$leaf -> Leaf::$apiKey`.
     *
     * Only the outermost exception carries the full path; inner ones carry their
     * own suffix. Never derived from message text.
     */
    public function getResolutionPath(): ?string
    {
        return $this->resolutionPath;
    }
}
