<?php namespace Hampel\ApprovalQueuePlus\XF\Admin\Controller;

use Hampel\ApprovalQueuePlus\SubContainer\Cloudflare;

class Tools extends XFCP_Tools
{
	public function actionHampelAqpShowCfLocation()
	{
		$this->setSectionContext('hampelAQPShowCFLocation');

        /** @var Cloudflare $cf */
        $cf = $this->app()->container('aqp.cloudflare');

        $viewParams = [
            'location' => $cf->getCloudflareLocation()
        ];

        return $this->view('Hampel\ApprovalQueuePlus:Tools\TestCloudflare', 'hampel_aqp_tools_test_cloudflare', $viewParams);
	}
}