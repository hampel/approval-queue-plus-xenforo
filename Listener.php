<?php namespace Hampel\ApprovalQueuePlus;

use Hampel\ApprovalQueuePlus\SubContainer\Cloudflare;
use XF\App;
use XF\Container;

class Listener
{
    public static function appSetup(App $app)
    {
        $container = $app->container();

        $container['aqp.cloudflare'] = function(Container $c) use ($app)
        {
            $class = $app->extendClass(Cloudflare::class);
            return new $class($c, $app);
        };
    }

	public static function userDeleteCleanInit($deleteService, array &$deletes)
	{
		$deletes['xf_aqp_user_data'] = 'user_id = ?';
	}

}