<?php namespace Hampel\ApprovalQueuePlus\XF\Service\User;

use Hampel\ApprovalQueuePlus\SubContainer\Cloudflare;

class Registration extends XFCP_Registration
{
	protected function _save()
	{
		$user = parent::_save();

        /** @var Cloudflare $cf */
        $cf = $this->app->container('aqp.cloudflare');
        $location = $cf->getCloudflareLocation();

            // save the user agent this user registered with
		$userData = $this->em()->create('Hampel\ApprovalQueuePlus:UserData');
		$userData->user_id = $user->user_id;
		$userData->user_agent = $this->app->request()->getUserAgent();

        if (isset($location['country_code']))
        {
            $userData->iso_code = $location['country_code'];
        }

        if (!empty($location))
        {
            $userData->cf_location = $location;
        }

		$userData->save();

		return $user;
	}
}
