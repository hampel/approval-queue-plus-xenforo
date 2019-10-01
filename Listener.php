<?php namespace Hampel\ApprovalQueuePlus;

class Listener
{
	public static function userDeleteCleanInit($deleteService, array &$deletes)
	{
		$deletes['xf_user_agent'] = 'user_id = ?';
	}

}