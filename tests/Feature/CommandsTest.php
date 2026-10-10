<?php namespace Tests\Feature;

use Hampel\ApprovalQueuePlus\Cli\Command\Config;
use Hampel\ApprovalQueuePlus\Cli\Command\Validate;
use Hampel\ApprovalQueuePlus\SubContainer\Cloudflare;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The two self-check commands, and the exit contract they publish: 1 if any check failed, 0
 * otherwise, and `--strict` turning a warnings-only run into 2. A monitor reads the code rather
 * than the output, so the code is the thing under test here.
 *
 * Needs framework 5.10 or later for `runConsoleCommand()`.
 */
class CommandsTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setUp(): void
	{
		parent::setUp();

		// a clean starting point, so a forum whose own options are odd cannot decide these outcomes
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => '7']);
	}

	public function test_config_reports_the_configuration_and_changes_nothing()
	{
		$before = $this->rowCount();

		$tester = $this->runConsoleCommand(Config::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame($before, $this->rowCount(), 'config must not write anything');

		$display = $tester->getDisplay();
		foreach (['Approval Queue Plus', 'default order', 'xf_aqp_user_data', 'schedule', 'headers mapped'] AS $expected)
		{
			$this->assertStringContainsString($expected, $display);
		}
	}

	/**
	 * `dom: [-1]` is XenForo's "any day of the month". This add-on's own documentation read it as
	 * "the last day" for three weeks, so the command renders the schedule from the entry's rules
	 * and this asserts the reading.
	 */
	public function test_config_reports_the_clean_up_as_daily()
	{
		$display = $this->runConsoleCommand(Config::class)->getDisplay();

		$this->assertStringContainsString('every day at 04:26', $display);
		$this->assertStringNotContainsString('of the month', $display);
	}

	public function test_validate_passes_when_the_add_on_is_set_up()
	{
		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
		$this->assertStringContainsString('Validated', $tester->getDisplay());
		$this->assertStringNotContainsString('[fail]', $tester->getDisplay());

		// a clean run is clean with the flag too: --strict only promotes warnings
		$this->assertSame(0, $this->runConsoleCommand(Validate::class, ['--strict' => true])->getStatusCode());
	}

	/**
	 * Nothing is broken, and every recorded user agent and IP is being kept for ever - which is
	 * exactly the shape that should warn rather than pass.
	 */
	public function test_the_clean_up_being_switched_off_warns_without_failing()
	{
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '0', 'delay' => '7']);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('[warn]', $tester->getDisplay());
		$this->assertStringContainsString('kept for ever', $tester->getDisplay());
		$this->assertStringContainsString('with warnings', $tester->getDisplay());

		$this->assertSame(
			2,
			$this->runConsoleCommand(Validate::class, ['--strict' => true])->getStatusCode(),
			'--strict should make a warnings-only run exit 2'
		);
	}

	/**
	 * The state 3.6.2 made safe and silent: the cron declines every run, so nothing is cleaned up
	 * and nothing says so. This is the check that exists because of it.
	 */
	public function test_an_unusable_delay_fails_whatever_the_flags()
	{
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => '']);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('[fail]', $tester->getDisplay());
		$this->assertStringContainsString('the prune declines every run', $tester->getDisplay());

		// 1 means failed with the flag and without it
		$this->assertSame(1, $this->runConsoleCommand(Validate::class, ['--strict' => true])->getStatusCode());
	}

	/**
	 * The add-on's whole user-facing surface is four template modifications, and a XenForo upgrade
	 * breaks one by changing a template it matches - silently, because the queue still renders.
	 */
	public function test_a_modification_that_matches_nothing_fails()
	{
		$this->app()->db()->query(
			'UPDATE xf_template_modification_log AS l
			INNER JOIN xf_template_modification AS m ON (m.modification_id = l.modification_id)
			SET l.apply_count = 0
			WHERE m.modification_key = ?',
			['approvalQueuePlusApprovalItemUser']
		);

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('matched nothing', $tester->getDisplay());
	}

	/**
	 * No check may end the run: a validation that stops at its first exception hides the rest, on
	 * the one occasion the whole list was wanted.
	 */
	public function test_a_check_that_throws_becomes_a_failure_and_logs_with_the_add_on_prefix()
	{
		$this->fakesErrors();

		$app = $this->app();
		$this->swap('aqp.cloudflare', function () use ($app)
		{
			return new class($app->container(), $app) extends Cloudflare
			{
				public function getCloudflareLocation()
				{
					throw new \RuntimeException('deliberate failure from a test');
				}
			};
		});

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('RuntimeException', $tester->getDisplay());

		// the sections after the one that threw still ran
		$this->assertStringContainsString('Clean-up', $tester->getDisplay());
		$this->assertStringContainsString('cron entry', $tester->getDisplay());

		$this->assertExceptionLogged(\RuntimeException::class, function (array $entry)
		{
			return strpos($entry['message'], 'ApprovalQueuePlus: ') !== false;
		});
	}

	/**
	 * Accepted so a monitor can use one command line across add-ons. Nothing here sends, so it has
	 * nothing to skip - and that has to stay true.
	 */
	public function test_unattended_changes_nothing()
	{
		$plain = $this->runConsoleCommand(Validate::class);
		$unattended = $this->runConsoleCommand(Validate::class, ['--unattended' => true]);

		$this->assertSame($plain->getStatusCode(), $unattended->getStatusCode());
		$this->assertSame($plain->getDisplay(), $unattended->getDisplay());
	}

	private function rowCount(): int
	{
		return (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_aqp_user_data');
	}
}
