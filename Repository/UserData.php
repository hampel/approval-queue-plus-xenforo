<?php namespace Hampel\ApprovalQueuePlus\Repository;

use Hampel\ApprovalQueuePlus\Option\UserDataCleanUp;
use XF\Mvc\Entity\Repository;

class UserData extends Repository
{
	/**
	 * Delete the recorded data of users who have since been approved.
	 *
	 * **In batches, because the number of eligible rows is not bounded by anything.** This runs
	 * unattended every morning, and the rows it deletes accumulate for as long as nothing deletes
	 * them: a forum that has had the clean-up switched off, or that has had it enabled with an
	 * unusable delay since 3.6.2 so the cron declines every run, has a backlog of every
	 * registration it has ever approved. Fetching that in one go hydrates an entity per row plus
	 * its user - measured at 5.3 KB a row, so somewhere in the low tens of thousands it exhausts
	 * the 128 MB that `XF::setMemoryLimit()` guarantees, and a PHP fatal in a cron job prunes
	 * nothing and says nothing. It would then do the same the next morning.
	 *
	 * **Batching alone did not fix it, which is worth knowing before anyone simplifies this.**
	 * Looping `fetch()` over a limited finder still grew to 28 MB at five thousand rows, because
	 * the entity manager identity-maps everything it hydrates: each batch was added to the entity
	 * cache and none of it was released. So this asks for the ids and nothing else.
	 * `fetchColumns()` runs the same query through `fetchRaw()`, so the conditions still live in
	 * one place - in `findPrunableUserData()`, which the commands count with - while no entity is
	 * built at all. Memory is then flat in the size of the backlog.
	 *
	 * @param int|null $cutOff a timestamp, or null for the one the option implies
	 * @param int $batchSize rows per statement; the tests pass a small one to exercise the loop
	 */
	public function pruneUserData($cutOff = null, $batchSize = 1000)
	{
		$db = $this->db();
		$batchSize = max(1, (int) $batchSize);

		while (true)
		{
			$userIds = array_column(
				$this->findPrunableUserData($cutOff)->limit($batchSize)->fetchColumns('user_id'),
				'user_id'
			);

			if (!$userIds)
			{
				return;
			}

			$deleted = $db->delete('xf_aqp_user_data', 'user_id IN (' . $db->quote($userIds) . ')');

			// The loop ends because each pass deletes what the next pass would have found. If a
			// delete ever removes nothing, the finder would hand back the same rows for ever, so
			// stop rather than spin - a cron entry that never returns takes the whole queue with
			// it.
			if (!$deleted)
			{
				return;
			}
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
