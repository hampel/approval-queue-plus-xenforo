<?php namespace Hampel\ApprovalQueuePlus\Seed;

use Hampel\ApprovalQueuePlus\Factory\UserDataFactory;
use Hampel\Seeder\Factory\UserFactory;
use Hampel\Seeder\Seed\AbstractSeeder;

/**
 * An Approval Queue holding every shape this add-on has to display, so that the queue can be
 * looked at by a person rather than reasoned about.
 *
 * Development tooling, like the test suite - `build.json` keeps this directory out of the release.
 *
 * Each member is registered at its own moment across the run's window, and XenForo writes the
 * queue row itself: `XF\Entity\User::_postSave()` sees a member inserted as `moderated` and saves
 * an `xf_approval_queue` row whose `content_date` is the registration date. That date is what the
 * queue sorts on, and reversing that sort is one of this add-on's two options - so a spread of
 * dates is what makes the option visible.
 *
 * Parameters:
 *
 *     pending     how many members awaiting approval, each with a recorded registration (12)
 *     bare        how many more with no recorded data at all, which is what an account created in
 *                 the admin panel, imported, or registered before this add-on was installed looks
 *                 like. The macro must leave every row out rather than fail - a missing row was a
 *                 fatal until 3.4.0 (2)
 *     prunable    how many approved members with old recorded data, which the queue does not show
 *                 and the clean-up is entitled to delete. For exercising the prune rather than the
 *                 display; a few thousand is what shows how it behaves at scale (0)
 *     days        how long ago the prunable members registered, in days (90)
 */
class QueueSeeder extends AbstractSeeder
{
	public function run()
	{
		$pending = $this->count('pending', 12);
		if ($pending)
		{
			// one of each variant first, so a small run still shows every branch the macro has
			UserDataFactory::new()->withoutUserAgent()->create();
			UserDataFactory::new()->withoutLocation()->create();
			UserDataFactory::new()->withoutContinent()->create();

			$remaining = max(0, $pending - 3);
			if ($remaining)
			{
				UserDataFactory::new()->count($remaining)->create();
			}
		}

		$bare = $this->count('bare', 2);
		if ($bare)
		{
			UserFactory::new()->moderated()->count($bare)->create();
		}

		$prunable = $this->count('prunable', 0);
		if ($prunable)
		{
			UserDataFactory::new()
				->forApprovedUser($this->count('days', 90))
				->count($prunable)
				->create();
		}
	}
}
