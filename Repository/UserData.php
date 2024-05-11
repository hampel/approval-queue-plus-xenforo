<?php namespace Hampel\ApprovalQueuePlus\Repository;

use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use XF\Mvc\Entity\Repository;

class UserData extends Repository
{
	public function pruneUserData($cutOff = null)
	{
		$db = $this->db();

		if ($cutOff === null)
		{
			$cutOff = $this->getRegistrationCutoff();
		}

		$agents = $this->userDataFinder()
		               ->with('User', true)
		               ->where('User.user_state', 'valid')
		               ->where('User.register_date', '<=', $cutOff)
		               ->fetch();

		if ($agents->count() > 0)
		{
			$userIdsQuoted = $db->quote($agents->keys());

			$db->delete('xf_aqp_user_data', "user_id IN ($userIdsQuoted)");
		}
	}

	public function getRegistrationCutoff()
	{
		return \XF::$time - (UserDataCleanUp::getDelay() * 86400);
	}

	public function userDataFinder()
	{
		return $this->finder('Hampel\ApprovalQueuePlus:UserData');
	}
}
