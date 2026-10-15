<?php

namespace App\Support;

use RuntimeException;

/** Records were added or removed between the pre-clear backup and the wipe; nothing was deleted. */
class RecordsChangedException extends RuntimeException
{
}
