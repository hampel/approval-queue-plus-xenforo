<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Rows leave `xf_aqp_user_data` by two routes, and this is the one the prune tests do not cover:
 * `Listener::userDeleteCleanInit()` answers `user_delete_clean_init` to add the table to the list
 * XenForo clears when a user is deleted.
 *
 * XenForo is the only caller of that listener, so the test has to reach it through XenForo. The
 * event is fired by `XF\Service\User\DeleteCleanUpService`'s constructor, and the step that
 * consumes what the listener added is `stepDeleteContent()` - it is what binds the user id into
 * each condition string, so a listener given the wrong signature, the wrong condition or the
 * wrong event name fails here and nowhere else.
 *
 * Needs framework 5.19 or later for `createUserAccount()`. A user built with
 * `createEntity('XF:User')` has no profile, option, privacy or authentication record, and
 * deletion reads all four.
 */
class UserDeletionRemovesUserDataTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_the_clean_up_after_a_deletion_removes_the_recorded_data()
	{
		$user = $this->createUserAccount();
		$userId = $user->user_id;
		$username = $user->username;

		$this->createEntity('Hampel\ApprovalQueuePlus:UserData', [
			'user_id' => $userId,
			'user_agent' => 'Mozilla/5.0 (deleted registrant)',
			'iso_code' => 'AU',
			'cf_location' => ['city' => 'Sydney', 'country' => 'Australia'],
		]);

		$this->assertDatabaseHas('xf_aqp_user_data', ['user_id' => $userId]);

		$this->assertTrue(
			$this->app()->service('XF:User\Delete', $user)->delete($errors),
			'the user could not be deleted: ' . implode('; ', (array) $errors)
		);

		// the row survives the deletion itself - it goes when the queued clean-up runs
		$this->assertDatabaseHas('xf_aqp_user_data', ['user_id' => $userId]);

		$this->runTheCleanUpStepThatConsumesTheDeletes($userId, $username);

		$this->assertDatabaseMissing('xf_aqp_user_data', ['user_id' => $userId]);
	}

	/**
	 * The listener must not claim any other add-on's table, and must use the placeholder form
	 * `stepDeleteContent()` binds the user id into. Asserting the entry directly is cheap; the
	 * test above is what proves XenForo honours it.
	 */
	public function test_the_listener_adds_only_this_add_ons_table()
	{
		$deletes = [];
		\Hampel\ApprovalQueuePlus\Listener::userDeleteCleanInit(null, $deletes);

		$this->assertSame(['xf_aqp_user_data' => 'user_id = ?'], $deletes);
	}

	/**
	 * Core reaches this step through `XF\Job\UserDeleteCleanUp`, which runs every step the service
	 * has - and one of the others, `stepMiscCleanUp()`, rebuilds the approval queue's unapproved
	 * counts in the data registry. That is a row the live forum writes too, so running the whole
	 * job inside this test's transaction raced it and failed with
	 * `MySQL query error [1213]: Deadlock found`, taking the transaction with it. It passed the
	 * first several times it was run, which is what such a race looks like.
	 *
	 * So the test runs the one step that consumes the listener's entry and none of the others.
	 * Reflection is the only way in - the steps are protected, and `MultiPartRunnerTrait` offers
	 * no way to select one - but the service is still built by the container, so the event fires
	 * from its real constructor and the deletion is performed by core's own code.
	 */
	protected function runTheCleanUpStepThatConsumesTheDeletes($userId, $username): void
	{
		$service = $this->app()->service('XF:User\DeleteCleanUp', $userId, $username);

		$step = new \ReflectionMethod($service, 'stepDeleteContent');
		$step->setAccessible(true);

		$this->assertNull(
			$step->invoke($service, null, 0),
			'the step reported more work to do, so it may not have reached this table'
		);
	}
}
