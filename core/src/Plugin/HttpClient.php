<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

/** Client HTTP minimo per la sorgente GitHub (sostituibile nei test). */
interface HttpClient
{
    /** Corpo della risposta (al massimo $maxBytes byte). */
    public function get(string $url, int $maxBytes): string;

    /** Scarica nel file $dest (al massimo $maxBytes byte). */
    public function download(string $url, string $dest, int $maxBytes): void;
}
