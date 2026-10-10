<?php namespace Hampel\ApprovalQueuePlus\Repository;

use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use XF\Mvc\Entity\Repository;

class UserData extends Repository
{
	public function pruneUserData($cutOff = null)
	{
		$db = $this->db();

		$agents = $this->findPrunableUserData($cutOff)->fetch();

		if ($agents->count() > 0)
		{
			$userIdsQuoted = $db->quote($agents->keys());

			$db->delete('xf_aqp_user_data', "user_id IN ($userIdsQuoted)");
		}
	}

	/**
	 * Which rows the prune would take: a user who is now `valid` - so no longer in the queue - and
	 * who registered on or before the cut-off.
	 *
	 * Separate from pruneUserData() so that anything wanting to report what *would* be deleted
	 * counts the same rows the prune deletes, rather than a second copy of these conditions that
	 * can drift from them. approval-queue-plus:config and :validate both use it.
	 *
	 * @param int|null $cutOff a timestamp, or null for the one the option implies
	 *
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function findPrunableUserData($cutOff = null)
	{
		if ($cutOff === null)
		{
			$cutOff = $this->getRegistrationCutoff();
		}

		return $this->userDataFinder()
		            ->with('User', true)
		            ->where('User.user_state', 'valid')
		            ->where('User.register_date', '<=', $cutOff);
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
