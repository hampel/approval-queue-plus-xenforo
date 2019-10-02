<?php namespace Hampel\ApprovalQueuePlus\Repository;

use Hampel\ApprovalQueuePlus\Option\UserAgentCleanUp;
use XF\Mvc\Entity\Repository;

class UserAgent extends Repository
{
	public function pruneUserAgents($cutOff = null)
	{
		$db = $this->db();

		if ($cutOff === null)
		{
			$cutOff = $this->getRegistrationCutoff();
		}

		$agents = $this->userAgentFinder()
		               ->with('User', true)
		               ->where('User.user_state', 'valid')
		               ->where('User.register_date', '<=', $cutOff)
		               ->fetch();

		if ($agents->count() > 0)
		{
			$userIdsQuoted = $db->quote($agents->keys());

			$db->delete('xf_user_agent', "user_id IN ($userIdsQuoted)");
		}
	}

	public function getRegistrationCutoff()
	{
		return \XF::$time - (UserAgentCleanUp::getDelay() * 86400);
	}

	public function userAgentFinder()
	{
		return $this->finder('Hampel\ApprovalQueuePlus:UserAgent');
	}
}
