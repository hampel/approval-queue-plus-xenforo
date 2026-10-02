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

	/**
	 * A cleared delay box reads as 0, which would put the cut-off at "now" and take every
	 * approved user's data. The ACP spinbox has a minimum of 1, so 0 can only mean the value is
	 * missing or unusable — never a deliberate "prune immediately".
	 */
	public function test_the_prune_is_skipped_when_the_delay_is_unusable()
	{
		foreach (['', 'soon', null] as $delay)
		{
			$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => $delay]);

			$this->mockRepository('Hampel\ApprovalQueuePlus:UserData', function ($mock)
			{
				$mock->expects()->pruneUserData()->never();
			});

			CleanUp::runDailyCleanup();
		}
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
