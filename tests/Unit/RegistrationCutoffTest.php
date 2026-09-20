<?php namespace Tests\Unit;

use Hampel\ApprovalQueuePlus\Repository\UserData;
use Tests\TestCase;

class RegistrationCutoffTest extends TestCase
{
	public function test_the_cutoff_is_the_delay_in_days_before_now()
	{
		$now = mktime(12, 0, 0, 6, 15, 2026);
		$this->setTestTime($now);
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => '7']);

		$this->assertSame($now - (7 * 86400), $this->repo()->getRegistrationCutoff());
	}

	public function test_a_zero_delay_puts_the_cutoff_at_now()
	{
		$now = mktime(12, 0, 0, 6, 15, 2026);
		$this->setTestTime($now);
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => '0']);

		$this->assertSame($now, $this->repo()->getRegistrationCutoff());
	}

	protected function repo(): UserData
	{
		return $this->app()->repository('Hampel\ApprovalQueuePlus:UserData');
	}
}
