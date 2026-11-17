<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

use Emp\Core\Module\Manifest;

/** Pacchetto del catalogo con impronta verificata. */
final class VerifiedPackage
{
    public function __construct(
        public readonly Manifest $manifest,
        public readonly string $path,
        public readonly string $sha256,
    ) {
    }
}
