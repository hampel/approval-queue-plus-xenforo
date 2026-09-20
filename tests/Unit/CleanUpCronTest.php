<?php namespace Tests\Unit;

use Hampel\ApprovalQueuePlus\Cron\CleanUp;
use Tests\TestCase;

/**
 * The cron entry runs unconditionally; the option is what decides whether it does anything.
 * That gate was missing until 3.5.4 — `isEnabled()` existed and had no callers — so unticking
 * the box in the ACP changed nothing and rows went on being deleted.
 */
class CleanUpCronTest extends TestCase
{
	public function test_the_prune_runs_when_the_option_is_on()
	{
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => '7']);

		$this->mockRepository('Hampel\ApprovalQueuePlus:UserData', function ($mock)
		{
			$mock->expects()->pruneUserData()->once();
		});

		CleanUp::runDailyCleanup();
	}

	public function test_the_prune_is_skipped_when_the_option_is_off()
	{
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '0', 'delay' => '7']);

		$this->mockRepository('Hampel\ApprovalQueuePlus:UserData', function ($mock)
		{
			$mock->expects()->pruneUserData()->never();
		});

		CleanUp::runDailyCleanup();
	}
}
