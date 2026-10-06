<?php

namespace App\Mvp\Ai\Cover;

/**
 * Copertina deterministica, il default del profilo locale: non richiede alcun
 * modello e restituisce la stessa immagine per la stessa richiesta. La palette
 * dipende dal tono, il motivo dallo stile, la disposizione da un hash del
 * contenuto. Nessun testo, come per le copertine generate dai modelli.
 */
final class DeterministicCoverGenerator implements CoverImageGenerator
{
    private const WIDTH = 1280;

    private const HEIGHT = 720;

    /**
     * Sfondo iniziale, sfondo finale e accento per ciascuno dei toni ammessi
     * da GenerateCommunicationRequest::TONES.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const PALETTES = [
        'Chiaro e diretto' => ['#1d4e89', '#3f88c5', '#f4f7fb'],
        'Più istituzionale' => ['#14213d', '#2f4b7c', '#c9a227'],
        'Più sintetico' => ['#2d3436', '#636e72', '#00b894'],
        'Empatico' => ['#c8553d', '#f28f3b', '#fff1e6'],
        'Tecnico' => ['#0b3c49', '#1b998b', '#c5f9d7'],
    ];

    /** @var array{0: string, 1: string, 2: string} */
    private const NEUTRAL_PALETTE = ['#3d405b', '#81b29a', '#f4f1de'];

    /**
     * Un motivo per ciascuno degli stili ammessi da GenerateCommunicationRequest::STYLES.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        'Testo informativo' => 'circles',
        'Avviso operativo' => 'bands',
        'Aggiornamento breve' => 'tiles',
    ];

    public function generate(string $prompt, string $tone, string $style, ?string $modelImagePrompt): array
    {
        $palette = self::PALETTES[$tone] ?? self::NEUTRAL_PALETTE;
        $seed = hash('sha256', implode("\n", [$tone, $style, $prompt, (string) $modelImagePrompt]), true);

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);

        $this->drawGradient($image, $palette[0], $palette[1]);

        match (self::PATTERNS[$style] ?? 'circles') {
            'bands' => $this->drawBands($image, $palette[2], $seed),
            'tiles' => $this->drawTiles($image, $palette[2], $seed),
            default => $this->drawCircles($image, $palette[2], $seed),
        };

        // Filo d'accento in basso: la stessa firma visiva su ogni copertina.
        imagefilledrectangle($image, 0, self::HEIGHT - 12, self::WIDTH, self::HEIGHT, $this->color($image, $palette[2], 0));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return ['bytes' => $bytes, 'mime' => 'image/png', 'warning' => null, 'reason' => null];
    }

    /**
     * Gradiente diagonale disegnato per linee: una per diagonale invece che
     * per pixel, cosi' il costo resta lineare nella somma dei lati.
     */
    private function drawGradient(\GdImage $image, string $from, string $to): void
    {
        [$r1, $g1, $b1] = $this->rgb($from);
        [$r2, $g2, $b2] = $this->rgb($to);
        $steps = self::WIDTH + self::HEIGHT;

        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $color = imagecolorallocate(
                $image,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            );
            imageline($image, $i, 0, $i - self::HEIGHT, self::HEIGHT, $color);
        }
    }

    private function drawCircles(\GdImage $image, string $accent, string $seed): void
    {
        for ($i = 0; $i < 9; $i++) {
            $x = $this->number($seed, $i * 3, self::WIDTH);
            $y = $this->number($seed, $i * 3 + 1, self::HEIGHT);
            $diameter = 120 + $this->number($seed, $i * 3 + 2, 360);
            imagefilledellipse($image, $x, $y, $diameter, $diameter, $this->color($image, $accent, 92 + $i * 3));
        }
    }

    private function drawBands(\GdImage $image, string $accent, string $seed): void
    {
        for ($i = 0; $i < 5; $i++) {
            $start = -200 + $i * 320 + $this->number($seed, $i, 120);
            $width = 70 + $this->number($seed, $i + 5, 90);
            imagefilledpolygon($image, [
                $start, 0,
                $start + $width, 0,
                $start + $width + self::HEIGHT, self::HEIGHT,
                $start + self::HEIGHT, self::HEIGHT,
            ], $this->color($image, $accent, 88 + $i * 6));
        }
    }

    private function drawTiles(\GdImage $image, string $accent, string $seed): void
    {
        $size = 96;
        $gap = 24;

        for ($row = 0; $row < 4; $row++) {
            for ($column = 0; $column < 9; $column++) {
                $index = $row * 9 + $column;

                if ($this->number($seed, $index, 3) === 0) {
                    continue;
                }

                $x = 80 + $column * ($size + $gap);
                $y = 120 + $row * ($size + $gap);
                imagefilledrectangle($image, $x, $y, $x + $size, $y + $size, $this->color($image, $accent, 70 + $this->number($seed, $index + 7, 50)));
            }
        }
    }

    /**
     * Intero in [0, $max) ricavato dall'hash: stessa richiesta, stessa forma.
     */
    private function number(string $seed, int $index, int $max): int
    {
        $bytes = hash('sha256', $seed.pack('N', $index), true);

        return unpack('N', $bytes)[1] % max(1, $max);
    }

    private function color(\GdImage $image, string $hex, int $alpha): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return imagecolorallocatealpha($image, $r, $g, $b, max(0, min(127, $alpha)));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $value = hexdec(ltrim($hex, '#'));

        return [($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];
    }
}
