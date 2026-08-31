<?php

declare(strict_types=1);

namespace StarDust\Examples;

/**
 * Renders one {@see LifecycleSnapshot} as a frame of plain text.
 *
 * The layout is fixed and the numbering is stable, so across a repaint
 * only the *values* move. That is the whole trick: a beginner watches
 * five numbered rows and sees which ones change, in what order, and how
 * long each takes — none of which a static document can show, because
 * the thing being taught is a sequence in time.
 *
 * Kept to 78 columns so it survives a default terminal, a screenshot,
 * and a paste into a chat window.
 */
final class LifecycleView
{
    private const WIDTH  = 78;
    /** 2 + marker + 2 + 14-wide label + 1 — the content column. */
    private const INDENT = '                    ';

    /** The slot lifecycle, as a literal so the caret can be aligned to it. */
    private const CHAIN = 'free -> assigned -> backfilling -> ready';

    public function __construct(
        private readonly Term $term,
        private readonly int $tenantId,
        private readonly int $fieldId,
        private readonly string $probeExpression,
    ) {
    }

    /**
     * @return list<string>
     */
    public function render(
        LifecycleSnapshot $s,
        int $act,
        string $actCaption,
        float $elapsed,
        bool $daemonsTicking,
        int $watcherTicks,
        int $reconcilerTicks,
    ): array {
        $lines = [];

        $lines[] = '  ' . $this->term->bold('StarDust — from "I marked it filterable" to "I can filter it"');
        $lines[] = '  ' . str_repeat('=', self::WIDTH - 2);
        // Pad against the *plain* string: sprintf counts ANSI escape
        // bytes as width, so colouring before padding misaligns the
        // column by exactly the length of the escape sequence.
        $actLabel = "ACT {$act} of 2";
        $left     = $actLabel . '  ·  ' . $actCaption;
        $elapsedCol = 'elapsed ' . Term::clock($elapsed);
        $gap      = max(1, (self::WIDTH - 2) - \strlen($left) - \strlen($elapsedCol));
        $lines[]  = '  ' . $this->term->cyan($actLabel)
            . '  ·  ' . $actCaption . str_repeat(' ', $gap) . $this->term->dim($elapsedCol);
        $lines[] = '';

        array_push($lines, ...$this->call($elapsed));
        $lines[] = '';
        array_push($lines, ...$this->registry($s));
        $lines[] = '';
        array_push($lines, ...$this->slot($s));
        $lines[] = '';
        array_push($lines, ...$this->backfill($s));
        $lines[] = '';
        array_push($lines, ...$this->probe($s));
        $lines[] = '';

        $lines[] = '  ' . str_repeat('-', self::WIDTH - 2);
        array_push($lines, ...$this->daemons($daemonsTicking, $watcherTicks, $reconcilerTicks));
        $lines[] = '';
        array_push($lines, ...$this->footer($s, $daemonsTicking));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function call(float $elapsed): array
    {
        return [
            $this->row('1', 'YOUR CALL', $this->term->bold(
                "\$engine->promoteFieldToFilterable({$this->tenantId}, {$this->fieldId});"
            )),
            self::INDENT . $this->term->dim(sprintf(
                'returned %s ago — and has been finished ever since.',
                Term::ago($elapsed),
            )),
        ];
    }

    /**
     * The one comparison the example exists to make.
     *
     * @return list<string>
     */
    private function registry(LifecycleSnapshot $s): array
    {
        $filterable = $s->isFilterable
            ? $this->term->green('YES')
            : $this->term->dim(' no');
        $indexed = $s->isIndexed
            ? $this->term->green('YES')
            : $this->term->yellow(' no');

        return [
            $this->row('2', 'THE REGISTRY', sprintf(
                "describeModel() on '%s' (%s):",
                $s->fieldName,
                $s->declaredType,
            )),
            self::INDENT . sprintf('  %-13s ', 'isFilterable') . $filterable
                . '   ' . $this->term->dim('<- the intent you recorded'),
            self::INDENT . sprintf('  %-13s ', 'isIndexed') . $indexed
                . '   ' . $this->term->dim('<- whether a filter works NOW'),
        ];
    }

    /**
     * @return list<string>
     */
    private function slot(LifecycleSnapshot $s): array
    {
        $stage = $s->slotStage();

        $held = $stage === null
            ? $this->term->dim('no slot held yet')
            : sprintf(
                '%s.%s',
                $s->slotPageTable ?? '?',
                $this->term->bold($s->slotColumn ?? '?'),
            );

        // `chainWithCaret()` may return two lines; the frame's repaint
        // accounting counts array entries, so split before pushing.
        $lines = [$this->row('3', 'THE SLOT', $held)];
        foreach (explode("\n", $this->chainWithCaret($stage)) as $chainLine) {
            $lines[] = self::INDENT . $chainLine;
        }

        if ($stage === null) {
            $lines[] = self::INDENT . $this->term->dim('the Watcher must provision an indexed page before');
            $lines[] = self::INDENT . $this->term->dim('the Reconciler has anything to reserve');
        }

        return $lines;
    }

    /**
     * The lifecycle chain with a caret under the current state.
     *
     * Aligning the caret to a `strpos()` into the literal keeps the two
     * in sync — an edit to the chain moves the caret with it.
     */
    private function chainWithCaret(?string $stage): string
    {
        $chain = $this->term->dim(self::CHAIN);

        if ($stage === null) {
            return $chain;
        }

        $at = strpos(self::CHAIN, $stage);
        if ($at === false) {
            // `tombstoned` — a state the chain does not draw, because a
            // tombstoned slot has already been detached from the field.
            return $chain . '   ' . $this->term->yellow("({$stage})");
        }

        return $chain . "\n" . str_repeat(' ', $at)
            . $this->term->green(str_repeat('^', \strlen($stage)));
    }

    /**
     * @return list<string>
     */
    private function backfill(LifecycleSnapshot $s): array
    {
        if ($s->checkpointStatus === null) {
            return [
                $this->row('4', 'THE BACKFILL', $this->term->dim('no checkpoint — nothing to copy')),
            ];
        }

        $status = $s->checkpointStatus === 'completed'
            ? $this->term->green('completed')
            : $this->term->yellow($s->checkpointStatus);

        $pct  = (int) round($s->progress() * 100);
        $bar  = Term::bar($s->progress(), 24);
        $bar  = $s->progress() >= 1.0 ? $this->term->green($bar) : $this->term->cyan($bar);

        $copied = $s->slotValuesWritten === null
            ? $this->term->dim('no slot to copy into yet')
            : $this->term->dim(sprintf(
                '%s of %s rows now carry a value in the slot',
                Term::num($s->slotValuesWritten),
                Term::num($s->totalRows),
            ));

        return [
            $this->row('4', 'THE BACKFILL', "checkpoint retype_field_{$this->fieldId} is {$status}"),
            self::INDENT . sprintf('[%s] %3d%%   cursor id %s', $bar, $pct, Term::num($s->cursor)),
            self::INDENT . $copied,
        ];
    }

    /**
     * @return list<string>
     */
    private function probe(LifecycleSnapshot $s): array
    {
        $lines = [$this->row('5', 'CAN I FILTER?', $this->term->dim($this->probeExpression))];

        if ($s->filterWorks()) {
            $lines[] = self::INDENT . $this->term->green(sprintf(
                'YES — first page returned %d rows',
                $s->probeRows ?? 0,
            ));
            $lines[] = self::INDENT . $this->term->dim(
                '(a page, not a total — StarDust never returns a count)'
            );

            return $lines;
        }

        $lines[] = self::INDENT . $this->term->red('NO — ' . ($s->probeError ?? 'unknown error'));
        foreach ($this->wrap($s->probeMessage ?? '', 56) as $line) {
            $lines[] = self::INDENT . $this->term->dim($line);
        }

        // Row 2 says isFilterable YES; the exception says "not
        // filterable". Both are the engine's own words, and a beginner
        // reading them together will conclude they have found a bug in
        // their own code. Name the contradiction rather than hide it.
        if ($s->isFilterable && $s->probeError === 'FieldNotFilterableException') {
            $lines[] = self::INDENT . $this->term->yellow(
                'Note: that message contradicts row 2. The field IS'
            );
            $lines[] = self::INDENT . $this->term->yellow(
                'filterable; what it has not got yet is an indexed slot.'
            );
            $lines[] = self::INDENT . $this->term->yellow(
                'Nothing is wrong with your code.'
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function daemons(bool $ticking, int $watcherTicks, int $reconcilerTicks): array
    {
        // -1 means --observe: this script is ticking nothing, so it has
        // no honest way to say whether a daemon is alive. Saying so is
        // better than guessing, and it is the diagnostic being taught.
        $mark = function (int $ticks): string {
            if ($ticks < 0) {
                return $this->term->yellow('yours — is it up?');
            }

            return $ticks > 0
                ? $this->term->green("ticking ({$ticks})")
                : $this->term->red('not ticking');
        };

        return [
            '  ' . sprintf('%-12s %-42s ', 'WATCHER', 'provisions indexed pages') . $mark($watcherTicks),
            '  ' . sprintf('%-12s %-42s ', 'RECONCILER', 'reserves the slot, then copies the rows') . $mark($reconcilerTicks),
        ];
    }

    /**
     * @return list<string>
     */
    private function footer(LifecycleSnapshot $s, bool $daemonsTicking): array
    {
        if (! $daemonsTicking) {
            return [
                '  ' . $this->term->yellow('Nothing above will change while both are stopped —'),
                '  ' . $this->term->yellow('and this is not an error state. It is the normal'),
                '  ' . $this->term->yellow('condition of a StarDust install with no daemons.'),
            ];
        }

        if ($s->filterWorks() && $s->isIndexed) {
            return [
                '  ' . $this->term->green('Done. The same read() call that was throwing a moment'),
                '  ' . $this->term->green('ago now returns rows. Nothing in your code changed.'),
            ];
        }

        return [
            '  ' . $this->term->dim('Watch rows 3, 4 and 5 change in that order.'),
        ];
    }

    private function row(string $n, string $label, string $content): string
    {
        return sprintf('  %s  %-14s %s', $this->term->bold($n), $label, $content);
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }

        $wrapped = wordwrap($text, $width, "\n", true);

        return array_values(array_slice(explode("\n", $wrapped), 0, 3));
    }
}
