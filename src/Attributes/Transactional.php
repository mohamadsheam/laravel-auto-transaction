<?php

namespace Sheum\AutoTransaction\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Transactional
{
    public function __construct(
        public ?string $connection = null,
        public int $attempts = 1,
        public bool $throwOnFailure = true
    ) {}
}
