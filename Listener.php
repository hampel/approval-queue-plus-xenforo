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

	/**
	 * Declare this add-on's secrets to the Admin API, which withholds an add-on's text settings
	 * until the add-on itself says which of them are credentials. The list is empty and should
	 * stay that way: both options are display settings - a queue sort order, and whether to prune
	 * recorded data after so many days - and the add-on reads no config.php keys at all.
	 *
	 * The event is defined by another add-on, so on a forum without it nothing fires this and the
	 * listener costs nothing. That is deliberate: it must not become a dependency, so nothing here
	 * refers to anything in that add-on's namespace.
	 */
	public static function adminApiRedaction(array &$declarations)
	{
		$declarations['Hampel/ApprovalQueuePlus'] = [];
	}
}
