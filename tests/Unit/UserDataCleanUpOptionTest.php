<?php namespace Tests\Unit;

use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use Tests\TestCase;

/**
 * The option is an `onofftextbox`: a checkbox and a delay in days. Both sub-options are read
 * through this class, and both have a fallback that decides what happens to stored data.
 */
class UserDataCleanUpOptionTest extends TestCase
{
	public function test_the_checkbox_is_honoured()
	{
		$this->setCleanUpOption(['enabled' => '1', 'delay' => '7']);
		$this->assertTrue(UserDataCleanUp::isEnabled());

		$this->setCleanUpOption(['enabled' => '0', 'delay' => '7']);
		$this->assertFalse(UserDataCleanUp::isEnabled());
	}

	public function test_a_missing_checkbox_reads_as_off()
	{
		$this->setCleanUpOption(['delay' => '7']);

		$this->assertFalse(UserDataCleanUp::isEnabled());
	}

	public function test_the_delay_comes_back_as_an_integer()
	{
		$this->setCleanUpOption(['enabled' => '1', 'delay' => '30']);

		$this->assertSame(30, UserDataCleanUp::getDelay());
	}

	/**
	 * A non-numeric delay yields 0, which puts the prune cut-off at "now" and takes every
	 * approved user's row. Reachable by clearing the delay box while the option stays on, so
	 * this pins the behaviour rather than endorsing it — change it here first.
	 */
	public function test_a_non_numeric_delay_yields_zero()
	{
		$this->setCleanUpOption(['enabled' => '1', 'delay' => '']);

		$this->assertSame(0, UserDataCleanUp::getDelay());
	}

	protected function setCleanUpOption(array $value): void
	{
		$this->setOption('approvalQueuePlusUserAgentCleanUp', $value);
	}
}
