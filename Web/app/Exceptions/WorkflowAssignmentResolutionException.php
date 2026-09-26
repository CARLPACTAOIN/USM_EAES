<?php

namespace App\Exceptions;

use DomainException;

class WorkflowAssignmentResolutionException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $roleKey,
        public readonly string $roleLabel,
        public readonly string $stepKey,
        public readonly string $stepTitle,
    ) {
        parent::__construct($message);
    }
}
