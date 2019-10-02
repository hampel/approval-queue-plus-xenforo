<?php namespace Hampel\ApprovalQueuePlus\Cron;

class CleanUp
{
	public static function runDailyCleanup()
	{
		$app = \XF::app();

		/** @var \Hampel\ApprovalQueuePlus\Repository\UserAgent $repo */
		$repo = $app->repository('Hampel\ApprovalQueuePlus:UserAgent');
		$repo->pruneUserAgents();
	}
}
