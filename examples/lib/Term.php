<?php

declare(strict_types=1);

namespace StarDust\Examples;

/**
 * The smallest terminal renderer that can hold a repainting frame.
 *
 * Two modes, chosen once at construction:
 *
 *   - **Repaint** (stdout is a TTY and tall enough) — `paint()` redraws
 *     the frame in place by walking the cursor back over the previous
 *     one. Output stays in scrollback, so the finished frame is still
 *     there to re-read afterwards. No alternate screen buffer.
 *   - **Append** (piped output, CI, `docker compose logs`, or a short
 *     terminal) — `paint()` is a no-op and the caller is expected to
 *     fall back to `note()`, which is a plain timestamped line.
 *
 * Callers must handle both. The examples do it by emitting every state
 * transition through `note()` regardless, and treating the frame as a
 * live view layered on top rather than the only output.
 */
final class Term
{
    /** Tallest frame the examples draw, plus a little headroom. */
    public const MIN_ROWS_FOR_REPAINT = 38;

    private readonly bool $colour;
    private readonly bool $repaint;
    private int $paintedLines = 0;

    public function __construct(bool $forceNoColour = false)
    {
        // Escape hatch for demos and for verifying the frame through a
        // pipe, where `stream_isatty()` is correctly false but the
        // recorded output is still meant to look like the live view.
        $forced = getenv('STARDUST_EXAMPLE_TTY') === '1';
        $tty    = $forced || (\function_exists('stream_isatty') && @stream_isatty(STDOUT));

        // NO_COLOR is the de-facto opt-out; honour it and the flag.
        $this->colour  = $tty && ! $forceNoColour && getenv('NO_COLOR') === false;
        $this->repaint = $tty && ($forced || self::terminalRows() >= self::MIN_ROWS_FOR_REPAINT);
    }

    public function repaints(): bool
    {
        return $this->repaint;
    }

    /**
     * Why the live frame was declined, or null when it was not.
     *
     * Falling back silently is the wrong behaviour here: the frame *is*
     * the example, so someone who gets the append-mode transcript
     * should be told the live view exists and what it needs.
     */
    public function repaintDeclinedReason(): ?string
    {
        if ($this->repaint) {
            return null;
        }

        if (! (\function_exists('stream_isatty') && @stream_isatty(STDOUT))) {
            return 'output is not a terminal, so the live frame is off;'
                . ' transitions are printed as they happen';
        }

        return sprintf(
            'this terminal is %d rows and the live frame needs %d;'
            . ' resize and re-run to watch it update in place',
            self::terminalRows(),
            self::MIN_ROWS_FOR_REPAINT,
        );
    }

    /**
     * Draw a frame, replacing the previous one when repainting.
     *
     * @param list<string> $lines
     */
    public function paint(array $lines): void
    {
        if (! $this->repaint) {
            return;
        }

        if ($this->paintedLines > 0) {
            // Walk back over the previous frame. `\e[K` clears each line
            // to the right so a shorter line cannot leave a tail behind.
            echo "\e[{$this->paintedLines}A";
        }

        foreach ($lines as $line) {
            echo "\e[K" . $line . "\n";
        }

        // The previous frame may have been taller; blank the remainder
        // and step back so the next paint still starts at frame top.
        $overhang = $this->paintedLines - \count($lines);
        if ($overhang > 0) {
            echo str_repeat("\e[K\n", $overhang);
            echo "\e[{$overhang}A";
        }

        $this->paintedLines = \count($lines);
    }

    /**
     * Emit a permanent line above the live frame.
     *
     * In repaint mode the frame is redrawn by the next `paint()`, so
     * this scrolls the transcript without tearing the view.
     */
    public function note(string $line): void
    {
        if ($this->repaint && $this->paintedLines > 0) {
            echo "\e[{$this->paintedLines}A\e[J";
            $this->paintedLines = 0;
        }

        echo $line . "\n";
    }

    /** Release the frame so shell output resumes below it. */
    public function release(): void
    {
        $this->paintedLines = 0;
    }

    public function bold(string $s): string
    {
        return $this->wrap($s, '1');
    }

    public function dim(string $s): string
    {
        return $this->wrap($s, '2');
    }

    public function red(string $s): string
    {
        return $this->wrap($s, '31');
    }

    public function green(string $s): string
    {
        return $this->wrap($s, '32');
    }

    public function yellow(string $s): string
    {
        return $this->wrap($s, '33');
    }

    public function cyan(string $s): string
    {
        return $this->wrap($s, '36');
    }

    /** A progress bar. `$fraction` is clamped into [0, 1]. */
    public static function bar(float $fraction, int $width): string
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $filled   = (int) round($fraction * $width);

        return str_repeat('#', $filled) . str_repeat('.', $width - $filled);
    }

    /** `12,345` — thousands separators, no decimals. */
    public static function num(int $n): string
    {
        return number_format($n);
    }

    /** `6s` / `1m 07s` — a duration a beginner reads without decoding. */
    public static function ago(float $seconds): string
    {
        $whole = (int) $seconds;

        return $whole < 60
            ? "{$whole}s"
            : sprintf('%dm %02ds', intdiv($whole, 60), $whole % 60);
    }

    /** `01:07` from a second count. */
    public static function clock(float $seconds): string
    {
        $whole = (int) $seconds;

        return sprintf('%02d:%02d', intdiv($whole, 60), $whole % 60);
    }

    private function wrap(string $s, string $code): string
    {
        return $this->colour ? "\e[{$code}m{$s}\e[0m" : $s;
    }

    /**
     * Best-effort terminal height.
     *
     * `stty` is absent from slim containers and `$LINES` is not exported
     * by every shell, so this falls back to the classic 24 — which is
     * below the repaint threshold, i.e. an unknown terminal degrades to
     * append mode rather than to a corrupted frame.
     */
    private static function terminalRows(): int
    {
        $env = getenv('LINES');
        if (is_string($env) && ctype_digit($env)) {
            return (int) $env;
        }

        $stty = @shell_exec('stty size 2>/dev/null');
        if (is_string($stty) && preg_match('/^(\d+)\s+\d+/', trim($stty), $m) === 1) {
            return (int) $m[1];
        }

        return 24;
    }
}
