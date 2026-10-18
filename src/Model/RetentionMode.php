<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * How strictly a retention protects an object version: GOVERNANCE can be
 * lifted with the bypass permission, COMPLIANCE by nobody until it expires.
 */
enum RetentionMode: string
{
    case Governance = 'GOVERNANCE';
    case Compliance = 'COMPLIANCE';
}
