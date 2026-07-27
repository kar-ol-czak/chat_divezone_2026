<?php

declare(strict_types=1);

namespace DiveChat\Enum;

enum SearchStrategy: string
{
    case BESTSELLER = 'bestseller';
    case BUDGET = 'budget';
    case RANGE = 'range';
    case SEMANTIC = 'semantic';
    case SPECIFIC = 'specific';
}
