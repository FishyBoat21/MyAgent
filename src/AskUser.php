<?php
declare(strict_types=1);

namespace MyAgent;

use Closure;
use RuntimeException;

/**
 * Terminal prompt behind the ask_user tool.
 *
 * A question is rendered as a numbered single-choice list of 1-4 options plus
 * an always-present "Other" entry. The answer is plain single-line text: the
 * chosen option, or whatever the user typed. Rendering goes to STDERR so the
 * model's prose on STDOUT stays clean, matching the security [y/N] prompt.
 */
final class AskUser
{
    public const MAX_OPTIONS = 4;
    private const MAX_ATTEMPTS = 3;

    /** @var null|Closure(): ?string */
    private ?Closure $readLine;

    /** @var Closure(string): void */
    private Closure $write;

    private ?Terminal $terminal;

    /**
     * @param null|Closure(): ?string $readLine Reads one line; null means no interactive user.
     * @param null|Closure(string): void $write Writes prompt text; defaults to STDERR.
     * @param null|Terminal $terminal Optional renderer for styled prompts.
     */
    public function __construct(?Closure $readLine = null, ?Closure $write = null, ?Terminal $terminal = null)
    {
        $this->readLine = $readLine;
        $this->terminal = $terminal;
        if ($write !== null) {
            $this->write = $write;
        } elseif ($terminal !== null) {
            $this->write = static function (string $text) use ($terminal): void {
                $terminal->err($text);
            };
        } else {
            $this->write = static function (string $text): void {
                fwrite(STDERR, $text);
            };
        }
    }

    /** Real terminal wiring. Without a TTY the tool is unavailable. */
    public static function terminal(): self
    {
        if (!@stream_isatty(STDIN)) {
            return new self();
        }
        return new self(
            static function (): ?string {
                $line = fgets(STDIN);
                return $line === false ? null : $line;
            },
        );
    }

    public function available(): bool
    {
        return $this->readLine !== null;
    }

    /**
     * Ask one question and return the answer.
     *
     * @param list<mixed> $options
     * @return string The chosen option or a custom answer.
     * @throws RuntimeException on invalid input, cancellation, or EOF.
     */
    public function ask(string $question, array $options): string
    {
        $question = self::oneLine($question);
        if ($question === '') {
            throw new RuntimeException('ask_user requires a non-empty question.');
        }
        $choices = [];
        foreach ($options as $option) {
            if (is_int($option) || is_float($option)) {
                $option = (string) $option;
            }
            if (!is_string($option) || self::oneLine($option) === '') {
                throw new RuntimeException('ask_user options must be non-empty text.');
            }
            $choices[] = self::oneLine($option);
        }
        if ($choices === [] || count($choices) > self::MAX_OPTIONS) {
            throw new RuntimeException('ask_user requires between 1 and 4 options.');
        }
        if ($this->readLine === null) {
            throw new RuntimeException('ask_user is unavailable: no interactive terminal is attached. State the question in your final answer instead.');
        }

        $count = count($choices);
        $other = $count + 1;
        $styled = $this->terminal !== null && $this->terminal->colorErr();
        $width = $this->terminal !== null ? $this->terminal->width() : 0;
        $wrap = static function (string $text) use ($width): string {
            return $width > 0 ? implode("\n", Ansi::wrap($text, $width)) : $text;
        };

        if ($styled) {
            $terminal = $this->terminal;
            ($this->write)(
                "\n" . $wrap($terminal->styleErr($terminal->symbolErr('question'), 'accent', 'bold')
                    . ' ' . $terminal->styleErr($question, 'bold')) . "\n"
            );
            foreach ($choices as $index => $choice) {
                $number = $terminal->styleErr((string) ($index + 1), 'accent', 'bold');
                $suffix = str_contains($choice, '(Recommended)')
                    ? ' ' . $terminal->styleErr($terminal->symbolErr('star'), 'muted')
                    : '';
                ($this->write)($wrap('  ' . $number . ') ' . $choice . $suffix) . "\n");
            }
            ($this->write)($wrap(
                '  ' . $terminal->styleErr((string) $other, 'accent', 'bold')
                . ') ' . $terminal->styleErr('Other - type your own answer', 'muted')
            ) . "\n");
            $prompt = $wrap($terminal->styleErr("Select 1-{$other}, type a custom answer, or press Enter to cancel: ", 'accent'));
        } else {
            ($this->write)("\n" . $wrap("[?] {$question}") . "\n");
            foreach ($choices as $index => $choice) {
                ($this->write)($wrap('  ' . ($index + 1) . ') ' . $choice) . "\n");
            }
            ($this->write)($wrap("  {$other}) Other - type your own answer") . "\n");
            $prompt = $wrap("Select 1-{$other}, type a custom answer, or press Enter to cancel: ");
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            ($this->write)($prompt);
            $line = ($this->readLine)();
            if ($line === null || trim($line) === '') {
                throw new RuntimeException('The user cancelled the question without answering. Do not ask it again; continue with your best judgment or explain what you need.');
            }
            $answer = trim($line);
            if (preg_match('/\A\d+\z/', $answer) === 1) {
                $choice = (int) $answer;
                if ($choice >= 1 && $choice <= $count) {
                    return $choices[$choice - 1];
                }
                if ($choice === $other) {
                    return $this->custom();
                }
                ($this->write)("Please select 1-{$other} or type an answer.\n");
                continue;
            }
            // Any non-numeric text is the user's own answer.
            return self::oneLine($answer);
        }
        throw new RuntimeException('No valid answer was given.');
    }

    /** Collect the free-text answer behind the "Other" entry. */
    private function custom(): string
    {
        $prompt = $this->terminal !== null && $this->terminal->colorErr()
            ? $this->terminal->styleErr('Your answer: ', 'accent')
            : 'Your answer: ';
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            ($this->write)($prompt);
            $line = ($this->readLine)();
            if ($line === null) {
                throw new RuntimeException('The user closed the input without answering.');
            }
            $answer = self::oneLine($line);
            if ($answer !== '') {
                return $answer;
            }
            ($this->write)("Please type an answer.\n");
        }
        throw new RuntimeException('The user did not type an answer.');
    }

    /** Collapse whitespace so prompts and answers stay on one line. */
    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
