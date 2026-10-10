<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;
use XF\Job\UserDeleteCleanUp;

/**
 * Rows leave `xf_aqp_user_data` by two routes, and this is the one the prune tests do not cover:
 * `Listener::userDeleteCleanInit()` answers `user_delete_clean_init` to add the table to the list
 * XenForo clears when a user is deleted.
 *
 * XenForo is the only caller of that listener, and it reaches it through a job rather than inline
 * - `XF\Entity\User::_postDelete()` enqueues `XF\Job\UserDeleteCleanUp`, whose
 * `DeleteCleanUpService` fires the event from its constructor. So the test drives the real
 * deletion and then the real job: a listener given the wrong signature, the wrong condition
 * string or the wrong event name would leave the row behind, and nothing else in this suite or on
 * the forum would say so.
 *
 * Needs framework 5.19 or later for `createUserAccount()`. A user built with
 * `createEntity('XF:User')` has no profile, option, privacy or authentication record, and
 * deletion reads all four.
 */
class UserDeletionRemovesUserDataTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_deleting_a_user_removes_the_recorded_data()
	{
		$user = $this->createUserAccount();

		$this->createEntity('Hampel\ApprovalQueuePlus:UserData', [
			'user_id' => $user->user_id,
			'user_agent' => 'Mozilla/5.0 (deleted registrant)',
			'iso_code' => 'AU',
			'cf_location' => ['city' => 'Sydney', 'country' => 'Australia'],
		]);

		$this->assertDatabaseHas('xf_aqp_user_data', ['user_id' => $user->user_id]);

		$userId = $user->user_id;
		$username = $user->username;

		$this->assertTrue(
			$this->app()->service('XF:User\Delete', $user)->delete($errors),
			'the user could not be deleted: ' . implode('; ', (array) $errors)
		);

		// the row survives the delete itself - it goes when the queued clean-up runs
		$this->assertDatabaseHas('xf_aqp_user_data', ['user_id' => $userId]);

		$this->runJobToCompletion(UserDeleteCleanUp::class, [
			'userId' => $userId,
			'username' => $username,
		]);

		$this->assertDatabaseMissing('xf_aqp_user_data', ['user_id' => $userId]);
	}

	/**
	 * The listener must not claim any other add-on's table, and must use the placeholder form
	 * `DeleteCleanUpService` binds the user id into. Asserting the entry directly is cheap; the
	 * test above is what proves XenForo honours it.
	 */
	public function test_the_listener_adds_only_this_add_ons_table()
	{
		$deletes = [];
		\Hampel\ApprovalQueuePlus\Listener::userDeleteCleanInit(null, $deletes);

		$this->assertSame(['xf_aqp_user_data' => 'user_id = ?'], $deletes);
	}
}
