<?php

declare(strict_types=1);

namespace Mika56\SPFCheck\MechanismEvaluator;

use Mika56\SPFCheck\Mechanism\AbstractMechanism;
use Mika56\SPFCheck\Model\Query;
use Mika56\SPFCheck\Model\Result;

class AllEvaluator implements EvaluatorInterface
{

    public static function matches(AbstractMechanism $mechanism, Query $query, Result $result): bool
    {
        return true;
    }
}
