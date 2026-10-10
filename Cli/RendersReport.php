<?php namespace Hampel\ApprovalQueuePlus\Cli;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Settings rows and check rows, in the same layout as hampel/console-report's ReportsSettings and
 * RendersChecks - which this cannot use, because that package needs PHP 8.3. The reason is one
 * function: it pads with mb_str_pad(), native only from 8.3, and XenForo does not ship the
 * polyfill that supplies it below that. XenForo's own symfony/console is not the obstacle; it
 * satisfies the package's constraint.
 *
 * The four check outcomes mean what they mean everywhere else: `[ ok ]` was proved, `[warn]` works
 * now and bites later, `[fail]` cannot do what it is configured to, and a blank marker did not
 * apply. A skip is never a pass.
 *
 * **Every name here is the package's**, where the package has one - checkSection(), checkOk() and
 * its siblings, checkResult(), checksFailed(), checksWarned(), checkExitCode(), detail(),
 * heading(), and the $check... properties - so a command written against this reads the same as
 * one written against the package, and moving to it would be a change of `use`. Keep it that way:
 * add to the package's vocabulary, do not rename it. Three things are not in the package, being
 * XenForo's concerns: muted(), probe() and errorLogPrefix(). The package declined probe(): it has
 * no logger to report what was thrown, which is what earns the method its place here.
 *
 * For a Symfony `Command`: probe() names the command in what it logs.
 */
trait RendersReport
{
	/** @var OutputInterface */
	protected $report;

	protected int $checkLabelWidth = 24;

	protected bool $checkFailed = false;

	protected bool $checkWarned = false;

	/** @var string|null the colour muted() uses, once known */
	private static ?string $mutedColour = null;

	/**
	 * What every row this command writes to XenForo's server error log begins with - the add-on's
	 * own prefix, such as `Monolog: `, so a mirror of that log can skip its rows. Abstract, not a
	 * property with a default: a command that forgot it would log rows a mirror alerts on, and
	 * nothing would say so. This way it does not load.
	 */
	abstract protected function errorLogPrefix(): string;

	protected function heading(string $title): void
	{
		$this->report->writeln('');
		$this->report->writeln("  <fg=green;options=bold>{$title}</>");
	}

	/**
	 * A setting and its value, with a dotted leader. An empty value reads as `not set`, never blank:
	 * a blank line reads as a setting that is fine.
	 */
	protected function detail(string $label, string $value): void
	{
		$value = $value === '' ? $this->muted('not set') : $value;
		$dots = max(2, 33 - mb_strlen($label));

		$this->report->writeln("  {$label} " . $this->muted(str_repeat('.', $dots)) . " {$value}");
	}

	/**
	 * Text in grey, where the console has grey. XenForo 2.2 ships its own fork of symfony/console,
	 * which knows only the eight basic colours and throws on `gray` - taking the command down at its
	 * first annotation - so there it is the terminal's default colour instead. Never write a grey
	 * foreground tag directly; CommandsTest fails if anything does.
	 */
	protected function muted(string $text): string
	{
		if (self::$mutedColour === null)
		{
			try
			{
				new OutputFormatterStyle('gray');
				self::$mutedColour = 'gray';
			}
			catch (\Throwable $e)
			{
				self::$mutedColour = 'default';
			}
		}

		return '<fg=' . self::$mutedColour . ">{$text}</>";
	}

	/**
	 * A heading above a run of check rows, with a blank line before it.
	 */
	protected function checkSection(string $title): void
	{
		$this->heading($title);
	}

	protected function checkOk(string $label, string $detail = ''): void
	{
		$this->checkResult('<info>[ ok ]</info>', $label, $detail);
	}

	protected function checkWarn(string $label, string $detail = ''): void
	{
		$this->checkWarned = true;
		$this->checkResult('<comment>[warn]</comment>', $label, $detail);
	}

	protected function checkFail(string $label, string $detail = ''): void
	{
		$this->checkFailed = true;
		$this->checkResult('<error>[fail]</error>', $label, $detail);
	}

	protected function checkSkip(string $label, string $detail = ''): void
	{
		$this->checkResult('[    ]', $label, $detail);
	}

	protected function checksFailed(): bool
	{
		return $this->checkFailed;
	}

	protected function checksWarned(): bool
	{
		return $this->checkWarned;
	}

	/**
	 * The exit code for the checks reported so far: 1 if any failed, otherwise 0 - a warning is not
	 * a failure, so `command && next-step` carries on past one.
	 *
	 * $strict makes a warning exit 2, for a caller that wants to hear of one. 1 still means failed.
	 * This is NOT the monitoring-plugin numbering, where 1 is a warning and 2 is critical.
	 */
	protected function checkExitCode(bool $strict = false): int
	{
		if ($this->checkFailed)
		{
			return 1;
		}

		return $strict && $this->checkWarned ? 2 : 0;
	}

	/**
	 * Runs one check, and turns anything it throws into a `[fail]` row and a server error log
	 * entry. No check may end the run: a validation that stops at its first problem hides the rest,
	 * on the one occasion the whole list was wanted.
	 *
	 * @return mixed|null what the probe returned, or null if it threw
	 */
	protected function probe(string $label, callable $probe)
	{
		try
		{
			return $probe();
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, "{$this->errorLogPrefix()}{$this->getName()} - {$label}: ");
			$this->checkFail($label, get_class($e) . ': ' . strtok($e->getMessage(), "\n"));

			return null;
		}
	}

	protected function checkResult(string $marker, string $label, string $detail): void
	{
		$padding = str_repeat(' ', max(0, $this->checkLabelWidth - mb_strlen($label)));

		$this->report->writeln(rtrim("  {$marker} {$label}{$padding} {$detail}"));
	}
}
