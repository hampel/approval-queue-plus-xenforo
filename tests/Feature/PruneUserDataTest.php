<?php namespace Tests\Feature;

use Hampel\ApprovalQueuePlus\Repository\UserData;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Entity\User;

/**
 * The prune decides whose data is deleted, and a user still waiting in the queue is exactly who
 * must keep it. Runs the real query against real rows, inside a transaction rolled back after.
 */
class PruneUserDataTest extends TestCase
{
	use UsesDatabaseTransactions;

	const DAY = 86400;

	protected function setUp(): void
	{
		parent::setUp();

		$this->fakesErrors();
		$this->setTestTime(mktime(12, 0, 0, 6, 15, 2026));
		$this->setOption('approvalQueuePlusUserAgentCleanUp', ['enabled' => '1', 'delay' => '7']);
	}

	public function test_only_approved_users_past_the_delay_lose_their_data()
	{
		$approvedLongAgo = $this->userWithData('valid', \XF::$time - 30 * self::DAY);
		$approvedRecently = $this->userWithData('valid', \XF::$time - 2 * self::DAY);
		$stillPending = $this->userWithData('moderated', \XF::$time - 30 * self::DAY);
		$rejected = $this->userWithData('rejected', \XF::$time - 30 * self::DAY);

		$this->repo()->pruneUserData();

		$this->assertFalse($this->hasData($approvedLongAgo), 'an approved user past the delay is pruned');
		$this->assertTrue($this->hasData($approvedRecently), 'an approved user inside the delay is kept');
		$this->assertTrue($this->hasData($stillPending), 'a user still in the queue keeps the data the queue shows');
		$this->assertTrue($this->hasData($rejected), 'only approved users are pruned');
	}

	public function test_a_user_registered_exactly_at_the_cutoff_is_pruned()
	{
		$atCutoff = $this->userWithData('valid', $this->repo()->getRegistrationCutoff());
		$justInside = $this->userWithData('valid', $this->repo()->getRegistrationCutoff() + 1);

		$this->repo()->pruneUserData();

		$this->assertFalse($this->hasData($atCutoff));
		$this->assertTrue($this->hasData($justInside));
	}

	protected function userWithData(string $state, int $registerDate): User
	{
		$suffix = bin2hex(random_bytes(4));

		/** @var User $user */
		$user = $this->createEntity('XF:User', [
			'username' => "aqpprune{$suffix}",
			'email' => "aqpprune{$suffix}@example.com",
			'user_state' => $state,
			'register_date' => $registerDate,
		]);

		$this->createEntity('Hampel\ApprovalQueuePlus:UserData', [
			'user_id' => $user->user_id,
			'user_agent' => 'Mozilla/5.0 (prune test)',
		]);

		return $user;
	}

	protected function hasData(User $user): bool
	{
		return (bool) $this->app()->db()->fetchOne(
			'SELECT user_id FROM xf_aqp_user_data WHERE user_id = ?', $user->user_id
		);
	}

	protected function repo(): UserData
	{
		return $this->app()->repository('Hampel\ApprovalQueuePlus:UserData');
	}
}
