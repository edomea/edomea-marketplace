<?php

declare(strict_types=1);

namespace Emp\Core\Plugin;

/**
 * Client HTTP con l'estensione curl (php-curl), usato solo dalla sorgente GitHub.
 *
 * - solo HTTPS, anche nei reindirizzamenti, con verifica del certificato;
 * - solo i domini di GitHub (API, sito e storage degli allegati delle release);
 * - limite reale sui byte ricevuti e tempi massimi;
 * - nessun token: le release di un repository pubblico si leggono senza credenziali.
 * allow_url_fopen resta disattivato nel gruppo PHP-FPM del Core.
 */
final class CurlHttpClient implements HttpClient
{
    private const ALLOWED_HOSTS = '/^(api\.github\.com|github\.com|codeload\.github\.com|[a-z0-9-]+\.githubusercontent\.com)$/';
    private const USER_AGENT = 'EMP-Core (Edomea Marketplace)';

    public static function isAvailable(): bool
    {
        return function_exists('curl_init');
    }

    public static function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port'] === 443)
            && (bool) preg_match(self::ALLOWED_HOSTS, strtolower((string) ($parts['host'] ?? '')));
    }

    public function get(string $url, int $maxBytes): string
    {
        $buffer = '';
        $this->request($url, $maxBytes, static function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $buffer;
    }

    public function download(string $url, string $dest, int $maxBytes): void
    {
        $out = fopen($dest, 'xb');
        if ($out === false) {
            throw new PackageException('Impossibile creare il file temporaneo del download');
        }
        try {
            $this->request($url, $maxBytes, static function (string $chunk) use ($out): void {
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new PackageException('Scrittura del download non riuscita');
                }
            });
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($dest);
            throw $e;
        }
        fclose($out);
    }

    /** @param \Closure(string): void $sink */
    private function request(string $url, int $maxBytes, \Closure $sink): void
    {
        if (!self::isAvailable()) {
            throw new PackageException('Estensione PHP curl assente: rieseguire l\'installazione di EMP Server');
        }
        // Reindirizzamenti seguiti a mano: ogni indirizzo deve essere HTTPS e di GitHub.
        for ($hop = 0; $hop <= 5; $hop++) {
            if (!self::allowedUrl($url)) {
                throw new PackageException('Indirizzo non consentito per la sorgente GitHub: ' . (parse_url($url, PHP_URL_HOST) ?: $url));
            }
            $received = 0;
            $status = 0;
            $location = null;
            $error = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, application/octet-stream', 'X-GitHub-Api-Version: 2022-11-28'],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status, &$location): int {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int) $m[1];
                        $location = null;
                    } elseif (preg_match('/^location:\s*(\S+)/i', $line, $m)) {
                        $location = $m[1];
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received, $maxBytes, $sink, &$status, &$error): int {
                    if ($status >= 300 && $status < 400) {
                        return strlen($chunk); // corpo del reindirizzamento: ignorato
                    }
                    $received += strlen($chunk);
                    if ($received > $maxBytes) {
                        $error = 'File scaricato più grande del previsto';

                        return 0;
                    }
                    if ($status === 200) {
                        try {
                            $sink($chunk);
                        } catch (\Throwable $e) {
                            $error = $e->getMessage();

                            return 0;
                        }
                    }

                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($error !== null) {
                throw new PackageException($error);
            }
            if ($ok === false) {
                throw new PackageException('GitHub non raggiungibile: ' . $curlError);
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null) {
                $url = $location;
                continue;
            }
            if ($status === 404) {
                throw new PackageException('Non trovato su GitHub (repository, release o allegato inesistente)');
            }
            if ($status === 403 || $status === 429) {
                throw new PackageException('GitHub ha rifiutato la richiesta (limite di richieste raggiunto?): riprovare più tardi');
            }
            if ($status !== 200) {
                throw new PackageException(sprintf('Risposta inattesa da GitHub (HTTP %d)', $status));
            }

            return;
        }
        throw new PackageException('Troppi reindirizzamenti da GitHub');
    }
}
