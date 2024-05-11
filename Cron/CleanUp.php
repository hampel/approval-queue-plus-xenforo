<?php namespace Hampel\ApprovalQueuePlus\Cron;

use Hampel\ApprovalQueuePlus\Repository\UserData;

class CleanUp
{
	public static function runDailyCleanup()
	{
		$app = \XF::app();

		/** @var UserData $repo */
		$repo = $app->repository('Hampel\ApprovalQueuePlus:UserData');
		$repo->pruneUserData();
	}
}
