<?php namespace Tests\Unit;

use Hampel\ApprovalQueuePlus\Cli\Command\Config;
use Hampel\ApprovalQueuePlus\Cli\Command\Validate;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * XenForo loads every add-on's command classes simply to list them, so a class here that cannot be
 * loaded takes `cmd.php` down for every add-on on the forum - not only this one. Nothing else in a
 * suite loads them at all.
 *
 * The two policy checks are deliberately not in the framework's assertion: they are about what
 * this add-on may write, not about whether XenForo can load it.
 */
class CommandClassesTest extends TestCase
{
	private const COMMANDS = [Config::class, Validate::class];

	public function test_every_command_class_loads_as_a_xenforo_command()
	{
		// sorted: the walk's order is the filesystem's and is not part of any contract
		$found = $this->assertConsoleCommandsLoad('Hampel/ApprovalQueuePlus');
		sort($found);

		$expected = self::COMMANDS;
		sort($expected);

		$this->assertSame($expected, $found, 'a command was added or removed without a test naming it');
	}

	/**
	 * `xf-make:cli-command` scaffolds a class extending `XF\Cli\Command\AbstractCommand`, which
	 * does not exist on XenForo 2.2 - and this add-on supports 2.2.
	 */
	public function test_no_command_extends_xenforos_abstract_command()
	{
		$wrong = [];

		foreach (self::COMMANDS AS $class)
		{
			if (!is_subclass_of($class, Command::class) || is_subclass_of($class, 'XF\Cli\Command\AbstractCommand'))
			{
				$wrong[] = $class;
			}
		}

		$this->assertSame([], $wrong, 'extend Symfony\'s Command - XF\Cli\Command\AbstractCommand is not on 2.2');
	}

	/**
	 * `Command::run()` is public and Symfony calls it. A helper of that name silently replaces the
	 * whole invocation.
	 */
	public function test_no_command_redefines_run()
	{
		$redefined = [];

		foreach (self::COMMANDS AS $class)
		{
			$method = new \ReflectionMethod($class, 'run');

			if ($method->getDeclaringClass()->getName() === $class)
			{
				$redefined[] = $class;
			}
		}

		$this->assertSame([], $redefined, 'never name a helper run() - it collides with Command::run()');
	}

	/**
	 * XenForo 2.2 ships its own fork of symfony/console, which knows only the eight basic colours
	 * and throws on `gray` - taking the command down at its first annotation. Grey goes through
	 * RendersReport::muted(), which falls back.
	 */
	public function test_nothing_writes_a_grey_colour_tag_directly()
	{
		$offenders = [];

		foreach (glob(dirname(__DIR__, 2) . '/Cli/{*.php,Command/*.php}', GLOB_BRACE) ?: [] AS $file)
		{
			$source = file_get_contents($file);

			// the one legitimate occurrence is the fallback inside muted() itself
			if (preg_match('/fg=gre?y/i', $source) && basename($file) !== 'RendersReport.php')
			{
				$offenders[] = basename($file);
			}
		}

		$this->assertSame([], $offenders, 'write grey through muted(), which falls back on XenForo 2.2');
	}

	/**
	 * A guard on the test above: with no files found it would pass having read nothing.
	 */
	public function test_the_colour_sweep_has_files_to_read()
	{
		$files = glob(dirname(__DIR__, 2) . '/Cli/{*.php,Command/*.php}', GLOB_BRACE) ?: [];

		$names = array_map(function ($file)
		{
			return str_replace(dirname(__DIR__, 2) . '/Cli/', '', $file);
		}, $files);
		sort($names);

		$this->assertSame(
			['Command/Config.php', 'Command/Validate.php', 'RendersReport.php'],
			array_values($names)
		);
	}
}
