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

		$app = \XF::app();

		/** @var UserData $repo */
		$repo = $app->repository('Hampel\ApprovalQueuePlus:UserData');
		$repo->pruneUserData();
	}
}
