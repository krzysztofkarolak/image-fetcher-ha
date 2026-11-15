<?php
declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

final class EinkDashboard
{
    public const PALETTES = [
        // Inky Frame 7.3" Spectra 6 (DISPLAY_INKY_FRAME_SPECTRA_7)
        'spectra6' => [
            'black'  => [0, 0, 0],
            'white'  => [255, 255, 255],
            'yellow' => [255, 255, 0],
            'red'    => [255, 0, 0],
            '_unused'=> null, // 4 doesnt exist in Spectra panel
            'blue'   => [0, 0, 255],
            'green'  => [0, 255, 0],
        ],
        // Inky Frame 4" / 5.7" / 7.3" (ACeP, 7 colors)
        'acep7' => [
            'black'  => [0, 0, 0],
            'white'  => [255, 255, 255],
            'green'  => [0, 255, 0],
            'blue'   => [0, 0, 255],
            'red'    => [255, 0, 0],
            'yellow' => [255, 255, 0],
            'orange' => [255, 128, 0],
        ],
    ];

    /** Colors used in ?preview=1 */
    private const PREVIEW_SIM = [
        'black' => '#191E21', 'white' => '#E8E8E8', 'yellow' => '#EFDE44', 'red' => '#B21318',
        'blue' => '#2157BA', 'green' => '#125F20', 'orange' => '#D9752B',
    ];

    private string $dir;
    private array $cfg;
    private string $cacheDir;

    public function __construct(private string $name, string $baseDir, ?string $cacheDir = null)
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            throw new InvalidArgumentException("Invalid dashboard name: $name");
        }
        $this->dir = rtrim($baseDir, '/') . '/' . $name;
        if (!is_file($this->dir . '/dashboard.yaml')) {
            throw new RuntimeException("File not found: {$this->dir}/dashboard.yaml");
        }
        $this->cfg = Yaml::parseFile($this->dir . '/dashboard.yaml') + ['device' => [], 'data' => [], 'vars' => []];
        $this->cfg['device'] += ['width' => 800, 'height' => 480, 'palette' => 'spectra6', 'dither' => false];
        $this->cfg['template'] ??= 'dashboard.svg.twig';
        if (!isset(self::PALETTES[$this->cfg['device']['palette']])) {
            throw new RuntimeException('Invalid palette: ' . $this->cfg['device']['palette']);
        }
        $this->cacheDir = ($cacheDir ?? sys_get_temp_dir() . '/eink-cache') . '/' . $name;
        @mkdir($this->cacheDir, 0775, true);
    }

    public function device(): array
    {
        return $this->cfg['device'];
    }

    public function fetchData(): array
    {
        if (!$this->cfg['data']) {
            return [];
        }

        $tpl = '';
        $json = [];
        $i = 0;
        foreach ($this->cfg['data'] as $key => $jinja) {
            $tpl .= "{% set _v$i %}" . $jinja . "{% endset %}";
            $json[] = json_encode((string)$key) . ': {{ _v' . $i . ' | trim | tojson }}';
            $i++;
        }
        $tpl .= '{' . implode(', ', $json) . '}';

        $raw = self::haRequest('/api/template', ['template' => $tpl]);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('HomeAssistant returned invalid JSON: ' . substr($raw, 0, 300));
        }

        // decodes json data from HA
        foreach ($data as $k => $v) {
            if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) {
                $dec = json_decode($v, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$k] = $dec;
                }
            }
        }
        ksort($data);
        return $data;
    }

    public static function haRequest(string $path, ?array $post = null): string
    {
        $base = rtrim(trim(preg_replace('/\s+/', ' ', (string)getenv('HA_URL'))), '/');
        $ch = curl_init($base . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . trim(preg_replace('/\s+/', ' ', (string)getenv('HA_BEARER'))),
                'Content-Type: application/json',
            ],
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($post);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            throw new RuntimeException("HA $path: HTTP $code $err " . substr((string)$body, 0, 300));
        }
        return $body;
    }

    public function hash(array $data): string
    {
        $files = array_merge(
            glob($this->dir . '/*') ?: [],
            glob(dirname($this->dir) . '/_*.twig') ?: []
        );
        sort($files);
        $fileHashes = array_map(fn($f) => is_file($f) ? md5_file($f) : '', $files);
        return substr(hash('sha256', json_encode([$data, $fileHashes, self::class . '/1'])), 0, 16);
    }

    public function current(): array
    {
        $last = $this->cacheDir . '/last.json';
        try {
            $data = $this->fetchData();
            $hash = $this->hash($data);
            $png = $this->cacheDir . "/$hash.png";
            if (!is_file($png)) {
                $this->renderLocked($data, $png);
            }
            file_put_contents($last, json_encode(['hash' => $hash, 'data' => $data]));
            return [$hash, $png, $data];
        } catch (Throwable $e) {
            error_log('[eink] ' . $e->getMessage());
            $prev = is_file($last) ? json_decode((string)file_get_contents($last), true) : null;
            if ($prev && is_file($this->cacheDir . "/{$prev['hash']}.png")) {
                return [$prev['hash'], $this->cacheDir . "/{$prev['hash']}.png", $prev['data']];
            }
            throw $e;
        }
    }

    public function pngForHash(string $hash): ?string
    {
        $f = $this->cacheDir . '/' . preg_replace('/[^a-f0-9]/', '', $hash) . '.png';
        return is_file($f) ? $f : null;
    }

    private function renderLocked(array $data, string $png): void
    {
        $lock = fopen($this->cacheDir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            if (!is_file($png)) {
                $this->renderPng($data, $png);
                $this->cleanup();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cleanup(int $keep = 10): void
    {
        $files = glob($this->cacheDir . '/*.png') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $f) {
            @unlink($f);
            @unlink($f . '.svg');
        }
    }

    // rendering

    public function renderSvg(array $data, bool $simulateColors = false): string
    {
        $palette = [];
        foreach (self::PALETTES[$this->cfg['device']['palette']] as $n => $rgb) {
            if ($rgb !== null) {
                $palette[$n] = $simulateColors ? self::PREVIEW_SIM[$n] : sprintf('#%02X%02X%02X', ...$rgb);
            }
        }

        $paths = array_values(array_unique(array_filter(
            [$this->dir, dirname($this->dir), dirname(__DIR__) . '/dashboards'], 'is_dir')));
        $loader = new \Twig\Loader\FilesystemLoader($paths);
        $twig = new \Twig\Environment($loader, [
            'autoescape'       => 'html',
            'strict_variables' => false,
            'cache'            => sys_get_temp_dir() . '/twig-cache',
            'auto_reload'      => true,
        ]);
        $this->registerHelpers($twig);

        return $twig->render($this->cfg['template'], [
            'd'      => $data,
            'c'      => $palette,
            'device' => $this->cfg['device'],
            'w'      => (int)$this->cfg['device']['width'],
            'h'      => (int)$this->cfg['device']['height'],
            'vars'   => $this->cfg['vars'],
        ]);
    }

    private function renderPng(array $data, string $png): void
    {
        $svg = $this->renderSvg($data);
        file_put_contents($png . '.svg', $svg);

        $w = (int)$this->cfg['device']['width'];
        $h = (int)$this->cfg['device']['height'];
        $raw = $png . '.raw.png';
        $cmd = sprintf(
            'rsvg-convert --width %d --height %d --background-color white --format png --output %s %s 2>&1',
            $w, $h, escapeshellarg($raw), escapeshellarg($png . '.svg')
        );
        exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($raw)) {
            throw new RuntimeException('rsvg-convert: ' . implode("\n", $out));
        }

        $img = $this->quantize(imagecreatefrompng($raw));
        unlink($raw);
        imagepng($img, $png . '.tmp', 9);
        imagedestroy($img);
        rename($png . '.tmp', $png);
    }

    private function quantize(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $pal = self::PALETTES[$this->cfg['device']['palette']];

        $out = imagecreate($w, $h);
        $idx = [];
        $cands = [];
        foreach (array_values($pal) as $i => $rgb) {
            $idx[$i] = imagecolorallocate($out, ...($rgb ?? [255, 255, 255]));
            if ($rgb !== null) {
                $cands[$i] = $rgb;
            }
        }
        $bw = [0 => $cands[0], 1 => $cands[1]];

        $nearest = function (int $r, int $g, int $b) use ($cands, $bw): int {
            $set = (max($r, $g, $b) - min($r, $g, $b) < 48) ? $bw : $cands;
            $best = 0;
            $bestD = PHP_INT_MAX;
            foreach ($set as $i => [$pr, $pg, $pb]) {
                $d = 3 * ($r - $pr) ** 2 + 4 * ($g - $pg) ** 2 + 2 * ($b - $pb) ** 2;
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $i;
                }
            }
            return $best;
        };

        $dither = (bool)$this->cfg['device']['dither'];
        $memo = [];
        $errNext = $dither ? array_fill(0, $w + 2, [0, 0, 0]) : [];
        for ($y = 0; $y < $h; $y++) {
            $errCur = $errNext;
            if ($dither) {
                $errNext = array_fill(0, $w + 2, [0, 0, 0]);
            }
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($src, $x, $y);
                if (!$dither) {
                    $i = $memo[$c] ??= $nearest(($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
                } else {
                    $e = $errCur[$x + 1];
                    $r = max(0, min(255, (($c >> 16) & 255) + $e[0]));
                    $g = max(0, min(255, (($c >> 8) & 255) + $e[1]));
                    $b = max(0, min(255, ($c & 255) + $e[2]));
                    $i = $nearest($r, $g, $b);
                    [$pr, $pg, $pb] = $cands[$i];
                    $er = [$r - $pr, $g - $pg, $b - $pb];
                    foreach ([[1, 0, 7], [-1, 1, 3], [0, 1, 5], [1, 1, 1]] as [$dx, $dy, $wgt]) {
                        if ($dy === 0) {
                            for ($k = 0; $k < 3; $k++) $errCur[$x + 1 + $dx][$k] += intdiv($er[$k] * $wgt, 16);
                        } else {
                            for ($k = 0; $k < 3; $k++) $errNext[$x + 1 + $dx][$k] += intdiv($er[$k] * $wgt, 16);
                        }
                    }
                }
                imagesetpixel($out, $x, $y, $idx[$i]);
            }
        }
        imagedestroy($src);
        return $out;
    }

    private function registerHelpers(\Twig\Environment $twig): void
    {
        $iconDirs = array_filter([
            getenv('MDI_DIR') ?: null,
            dirname($this->dir) . '/icons',
            '/usr/share/mdi/svg',
        ]);

        $twig->addFunction(new \Twig\TwigFunction('icon', function (string $name, float $x, float $y, float $size = 48, string $color = '#000000') use ($iconDirs) {
            $name = preg_replace('/^mdi[-:]/', '', $name);
            foreach ($iconDirs as $d) {
                $f = "$d/$name.svg";
                if (is_file($f) && preg_match('/\sd="([^"]+)"/', (string)file_get_contents($f), $m)) {
                    $s = $size / 24;
                    return sprintf('<path transform="translate(%s %s) scale(%s)" fill="%s" d="%s"/>',
                        $x, $y, $s, htmlspecialchars($color), $m[1]);
                }
            }
            return "<!-- brak ikony: $name -->";
        }, ['is_safe' => ['html']]));

        $twig->addFilter(new \Twig\TwigFilter('num', function ($v, int $dec = 0) {
            if (!is_numeric($v)) {
                return '—';
            }
            return number_format((float)$v, $dec, ',', ' ');
        }));

        $twig->addFilter(new \Twig\TwigFilter('trunc', fn($s, int $n) =>
            mb_strlen((string)$s) > $n ? rtrim(mb_substr((string)$s, 0, $n - 1)) . '…' : (string)$s));

        $twig->addFilter(new \Twig\TwigFilter('lines', function ($s, int $chars, int $max = 99) {
            $lines = explode("\n", wordwrap((string)$s, $chars, "\n", true));
            if (count($lines) > $max) {
                $lines = array_slice($lines, 0, $max);
                $lines[$max - 1] = rtrim(mb_substr($lines[$max - 1], 0, $chars - 1)) . '…';
            }
            return $lines;
        }));

        // {{ d.date|pl_date }} – polish dates
        $twig->addFilter(new \Twig\TwigFilter('pl_date', function ($v, string $fmt = 'EEEE, d MMMM') {
            $dt = $v instanceof DateTimeInterface ? $v : new DateTimeImmutable((string)$v);
            if (class_exists(IntlDateFormatter::class)) {
                return (new IntlDateFormatter('pl_PL', 0, 0, $dt->getTimezone(), null, $fmt))->format($dt);
            }
            $days = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
            $months = ['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca',
                       'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
            return $days[(int)$dt->format('w')] . ', ' . $dt->format('j') . ' ' . $months[(int)$dt->format('n')];
        }));
    }
}
