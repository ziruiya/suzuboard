<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Self-hosted image captcha for the App "local" captcha mode (AUD-001).
 *
 * The challenge answer is only ever stored server-side as a SHA-256
 * hash in the cache; the client receives an id plus a PNG data URI.
 * Challenges live for TTL_SECONDS and are single-use: any verify
 * attempt (right or wrong) consumes the challenge, so one challenge
 * buys exactly one guess.
 *
 * The production PHP runtime has no GD / imagick extension, so the
 * PNG is rasterised in pure PHP from an embedded 5x7 bitmap font and
 * encoded with zlib (gzcompress) only. The same code path therefore
 * runs identically in tests and in production.
 */
class LocalCaptchaService
{
    public const TTL_SECONDS = 300;

    public const CODE_LENGTH = 4;

    /** Ambiguous glyphs (0/O, 1/I) are excluded on purpose. */
    public const CHARSET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const RESULT_OK = 'ok';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_EXPIRED = 'expired';

    private const IMAGE_WIDTH = 140;
    private const IMAGE_HEIGHT = 52;
    private const GLYPH_SCALE = 4;

    /** 5x7 bitmap font, one string of 5 pixels per row, '#' = ink. */
    private const FONT = [
        '2' => ['.###.', '#...#', '....#', '...#.', '..#..', '.#...', '#####'],
        '3' => ['.###.', '#...#', '....#', '..##.', '....#', '#...#', '.###.'],
        '4' => ['...#.', '..##.', '.#.#.', '#..#.', '######', '....#', '....#'],
        '5' => ['#####', '#....', '####.', '....#', '....#', '#...#', '.###.'],
        '6' => ['..##.', '.#...', '#....', '####.', '#...#', '#...#', '.###.'],
        '7' => ['#####', '....#', '...#.', '..#..', '..#..', '..#..', '..#..'],
        '8' => ['.###.', '#...#', '#...#', '.###.', '#...#', '#...#', '.###.'],
        '9' => ['.###.', '#...#', '#...#', '.####', '....#', '...#.', '.##..'],
        'A' => ['.###.', '#...#', '#...#', '#####', '#...#', '#...#', '#...#'],
        'B' => ['####.', '#...#', '#...#', '####.', '#...#', '#...#', '####.'],
        'C' => ['.###.', '#...#', '#....', '#....', '#....', '#...#', '.###.'],
        'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
        'E' => ['#####', '#....', '#....', '####.', '#....', '#....', '#####'],
        'F' => ['#####', '#....', '#....', '####.', '#....', '#....', '#....'],
        'G' => ['.###.', '#...#', '#....', '#.###', '#...#', '#...#', '.###.'],
        'H' => ['#...#', '#...#', '#...#', '#####', '#...#', '#...#', '#...#'],
        'J' => ['..###', '....#', '....#', '....#', '....#', '#...#', '.###.'],
        'K' => ['#...#', '#..#.', '#.#..', '##...', '#.#..', '#..#.', '#...#'],
        'L' => ['#....', '#....', '#....', '#....', '#....', '#....', '#####'],
        'M' => ['#...#', '##.##', '#.#.#', '#.#.#', '#...#', '#...#', '#...#'],
        'N' => ['#...#', '#...#', '##..#', '#.#.#', '#..##', '#...#', '#...#'],
        'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
        'Q' => ['.###.', '#...#', '#...#', '#...#', '#.#.#', '#..#.', '.##.#'],
        'R' => ['####.', '#...#', '#...#', '####.', '#.#..', '#..#.', '#...#'],
        'S' => ['.####', '#....', '#....', '.###.', '....#', '....#', '####.'],
        'T' => ['#####', '..#..', '..#..', '..#..', '..#..', '..#..', '..#..'],
        'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
        'V' => ['#...#', '#...#', '#...#', '#...#', '#...#', '.#.#.', '..#..'],
        'W' => ['#...#', '#...#', '#...#', '#.#.#', '#.#.#', '#.#.#', '.#.#.'],
        'X' => ['#...#', '.#.#.', '..#..', '..#..', '..#..', '.#.#.', '#...#'],
        'Y' => ['#...#', '#...#', '.#.#.', '..#..', '..#..', '..#..', '..#..'],
        'Z' => ['#####', '....#', '...#.', '..#..', '.#...', '#....', '#####'],
    ];

    private const INK_COLORS = [
        [32, 32, 128],
        [128, 32, 32],
        [16, 96, 16],
        [96, 40, 144],
        [40, 40, 40],
        [16, 80, 96],
    ];

    /**
     * Issue a new challenge.
     *
     * @return array{id: string, image: string, expires_in: int}
     */
    public function issue(): array
    {
        $code = '';
        $max = strlen(self::CHARSET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CHARSET[random_int(0, $max)];
        }

        $id = Str::random(40);
        Cache::put(
            CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', $id),
            $this->hashAnswer($code),
            self::TTL_SECONDS
        );

        return [
            'id' => $id,
            'image' => 'data:image/png;base64,' . base64_encode($this->renderPng($code)),
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * Verify an answer. Returns one of the RESULT_* constants.
     * The challenge is consumed by any attempt (one guess per challenge);
     * a missing / expired / already-used challenge reports RESULT_EXPIRED.
     */
    public function verify(string $id, string $answer): string
    {
        $key = CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', $id);
        try {
            $stored = Cache::lock('local_captcha_consume_' . hash('sha256', $id), 10)
                ->block(1, function () use ($key) {
                    $stored = Cache::get($key);
                    return is_string($stored) && Cache::forget($key) ? $stored : null;
                });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return self::RESULT_EXPIRED;
        }

        if ($stored === null) {
            return self::RESULT_EXPIRED;
        }

        return hash_equals($stored, $this->hashAnswer($answer))
            ? self::RESULT_OK
            : self::RESULT_INVALID;
    }

    private function hashAnswer(string $answer): string
    {
        return hash('sha256', strtoupper(trim($answer)));
    }

    /**
     * Rasterise the code into PNG bytes (pure PHP, no image extension).
     */
    public function renderPng(string $code): string
    {
        $width = self::IMAGE_WIDTH;
        $height = self::IMAGE_HEIGHT;
        $scale = self::GLYPH_SCALE;

        // Pixel buffer of packed RGB ints, light background.
        $bg = random_int(232, 244);
        $pixels = array_fill(0, $width * $height, ($bg << 16) | ($bg << 8) | $bg);
        $set = function (int $x, int $y, array $rgb) use (&$pixels, $width, $height): void {
            if ($x >= 0 && $x < $width && $y >= 0 && $y < $height) {
                $pixels[$y * $width + $x] = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
            }
        };

        // Noise dots.
        for ($i = 0; $i < 110; $i++) {
            $g = random_int(140, 200);
            $set(random_int(0, $width - 1), random_int(0, $height - 1), [$g, $g, $g]);
        }

        // Glyphs with per-character colour and vertical jitter.
        $glyphWidth = 5 * $scale;
        $glyphHeight = 7 * $scale;
        $spacing = 7;
        $textWidth = strlen($code) * $glyphWidth + (strlen($code) - 1) * $spacing;
        $originX = intdiv($width - $textWidth, 2);
        $originY = intdiv($height - $glyphHeight, 2);

        foreach (str_split($code) as $index => $char) {
            $glyph = self::FONT[$char] ?? null;
            if ($glyph === null) {
                continue;
            }
            $color = self::INK_COLORS[random_int(0, count(self::INK_COLORS) - 1)];
            $baseX = $originX + $index * ($glyphWidth + $spacing);
            $baseY = $originY + random_int(-3, 3);
            foreach ($glyph as $row => $line) {
                foreach (str_split($line) as $col => $bit) {
                    if ($bit !== '#') {
                        continue;
                    }
                    for ($dy = 0; $dy < $scale; $dy++) {
                        for ($dx = 0; $dx < $scale; $dx++) {
                            $set($baseX + $col * $scale + $dx, $baseY + $row * $scale + $dy, $color);
                        }
                    }
                }
            }
        }

        // Noise lines across the text.
        for ($i = 0; $i < 3; $i++) {
            $g = random_int(150, 190);
            $y0 = random_int(4, $height - 5);
            $y1 = random_int(4, $height - 5);
            for ($x = 0; $x < $width; $x++) {
                $y = (int) round($y0 + ($y1 - $y0) * $x / max(1, $width - 1));
                $set($x, $y, [$g, $g, $g]);
            }
        }

        // Encode as PNG (truecolour, no filtering, zlib-compressed).
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";
            for ($x = 0; $x < $width; $x++) {
                $rgb = $pixels[$y * $width + $x];
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }

        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";
        return "\x89PNG\r\n\x1a\n"
            . $this->chunk('IHDR', $ihdr)
            . $this->chunk('IDAT', gzcompress($raw, 9))
            . $this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
