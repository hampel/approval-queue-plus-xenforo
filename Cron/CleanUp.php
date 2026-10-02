<?php namespace Hampel\ApprovalQueuePlus\Cron;

use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use Hampel\ApprovalQueuePlus\Repository\UserData;

class CleanUp
{
	public static function runDailyCleanup()
	{
		if (!UserDataCleanUp::isEnabled())
		{
			return;
		}

		// A delay of 0 is not a deliberate "prune immediately" - the ACP spinbox has a minimum of
		// 1, so getDelay() only returns 0 for a value that is missing or not numeric. Pruning on
		// it would put the cut-off at now and take every approved user's data.
		if (UserDataCleanUp::getDelay() < 1)
		{
			return;
		}

		$app = \XF::app();

		/** @var UserData $repo */
		$repo = $app->repository('Hampel\ApprovalQueuePlus:UserData');
		$repo->pruneUserData();
	}
}
