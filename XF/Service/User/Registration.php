<?php namespace Hampel\ApprovalQueuePlus\XF\Service\User;

class Registration extends XFCP_Registration
{
	protected function _save()
	{
		$user = parent::_save();

		// save the user agent this user registered with
		$userAgent = $this->em()->create('Hampel\ApprovalQueuePlus:UserAgent');
		$userAgent->user_id = $user->user_id;
		$userAgent->user_agent = $this->app->request()->getUserAgent();
		$userAgent->save();

		return $user;
	}
}
