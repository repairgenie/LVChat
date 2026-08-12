<?php

declare(strict_types=1);

// Fixture module whose boot hook throws — proves the loader catches it, records
// a warning, and keeps the request/daemon running (never fatal).
throw new RuntimeException('broken-mod exploded at boot');
