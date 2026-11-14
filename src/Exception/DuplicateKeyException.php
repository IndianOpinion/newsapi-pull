<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull\Exception;

/**
 * A concurrent insert stored the same key hash first.
 */
class DuplicateKeyException extends DatabaseException
{
}
