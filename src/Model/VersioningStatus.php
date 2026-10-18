<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The versioning state of a bucket; a bucket that never had versioning has none.
 */
enum VersioningStatus: string
{
    case Enabled = 'Enabled';
    case Suspended = 'Suspended';
}
