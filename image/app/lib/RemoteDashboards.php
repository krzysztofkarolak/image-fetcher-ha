<?php
declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Sync dashboard config files from Home Assistant.
 */

final class RemoteDashboards
{
    public function __construct(
        private string $baseUrl,
        private string $localBase,
        private int $ttl = 60,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        @mkdir($this->localBase, 0775, true);
    }

    public function sync(string $name, bool $force = false): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            throw new InvalidArgumentException("Invalid dashboard name: $name");
        }
        $dir = $this->localBase . '/' . $name;
        @mkdir($dir, 0775, true);
        $stamp = $dir . '/.synced';
        if (!$force && is_file($stamp) && time() - filemtime($stamp) < $this->ttl) {
            return $this->localBase;
        }

        $lock = fopen($dir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->fetch("$name/dashboard.yaml", "$dir/dashboard.yaml", required: true);

            $cfg = Yaml::parseFile("$dir/dashboard.yaml") ?: [];
            $files = array_merge([$cfg['template'] ?? 'dashboard.svg.twig'], (array)($cfg['includes'] ?? []));
            foreach (array_unique($files) as $f) {
                if (!preg_match('/^[A-Za-z0-9_.-]+$/', (string)$f) || str_starts_with((string)$f, '.')) {
                    throw new RuntimeException("Invalid filename in dashboard.yaml: $f");
                }
                $this->fetch("$name/$f", "$dir/$f", required: true);
            }

            $this->fetch('_macros.svg.twig', $this->localBase . '/_macros.svg.twig', required: false);

            touch($stamp);
        } catch (Throwable $e) {
            error_log('[eink] sync ' . $e->getMessage());
            if (!is_file("$dir/dashboard.yaml")) {
                throw $e;
            }
            touch($stamp);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->localBase;
    }

    private function fetch(string $path, string $target, bool $required): void
    {
        $metaFile = $target . '.meta';
        $meta = is_file($metaFile) && is_file($target) ? (json_decode((string)file_get_contents($metaFile), true) ?: []) : [];

        $headers = [];
        if (!empty($meta['etag'])) {
            $headers[] = 'If-None-Match: ' . $meta['etag'];
        }
        if (!empty($meta['last_modified'])) {
            $headers[] = 'If-Modified-Since: ' . $meta['last_modified'];
        }
        if ($token = trim((string)getenv('DASHBOARDS_BEARER'))) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $resp = [];
        $ch = curl_init($this->baseUrl . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$resp) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $resp[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code === 304) {
            return;
        }
        if ($code === 404 && !$required) {
            @unlink($target);
            @unlink($metaFile);
            return;
        }
        if ($body === false || $code !== 200) {
            throw new RuntimeException("GET $path: HTTP $code $err");
        }

        if (!is_file($target) || md5_file($target) !== md5($body)) {
            file_put_contents($target . '.tmp', $body);
            rename($target . '.tmp', $target);
        }
        file_put_contents($metaFile, json_encode([
            'etag'          => $resp['etag'] ?? null,
            'last_modified' => $resp['last-modified'] ?? null,
        ]));
    }
}
